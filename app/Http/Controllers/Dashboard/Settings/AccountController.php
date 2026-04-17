<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Enums\BusinessMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\UpdateAccountPasswordRequest;
use App\Http\Requests\Dashboard\Settings\UpdateAccountProfileRequest;
use App\Models\Provider;
use App\Services\ProviderScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The actor's own identity surface (profile, password, avatar) plus the
 * admin-only "Bookable provider" toggle (D-062).
 *
 * Self-service for admin + staff (D-096); routes split so toggleProvider stays
 * admin-only via route middleware. The schedule / exception / services bodies
 * formerly hosted here moved to AvailabilityController.
 */
class AccountController extends Controller
{
    public function __construct(private readonly ProviderScheduleService $schedules) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $business = tenant()->business();
        $isAdmin = tenant()->role() === BusinessMemberRole::Admin;

        $provider = Provider::withTrashed()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        $isProvider = $provider !== null && ! $provider->trashed();

        return Inertia::render('dashboard/settings/account', [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
            ],
            'hasPassword' => $user->password !== null,
            'isAdmin' => $isAdmin,
            'isProvider' => $isProvider,
            'hasProviderRow' => $provider !== null,
        ]);
    }

    public function updateProfile(UpdateAccountProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $emailChanged = $user->email !== $request->validated('email');

        $user->fill($request->validated());

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();

            // Redirect straight to verification.notice rather than back to
            // settings.account: the GET /account would be intercepted by the
            // `verified` middleware and redirected to verification.notice
            // anyway, but the flash data would be consumed by that
            // intermediate request and the user would land on the notice page
            // with no feedback. The status copy is rendered by the notice
            // page's existing `status` prop (auth/verify-email.tsx).
            return redirect()->route('verification.notice')->with(
                'status',
                __('Profile updated. We sent a verification link to :email.', ['email' => $user->email]),
            );
        }

        return redirect()->route('settings.account')->with('success', __('Profile updated.'));
    }

    public function updatePassword(UpdateAccountPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $hadPassword = $user->password !== null;

        $user->update(['password' => Hash::make($request->validated('password'))]);

        return redirect()->route('settings.account')->with(
            'success',
            $hadPassword ? __('Password changed.') : __('Password set.'),
        );
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'avatar' => ['required', File::image()->max(2048)->types(['jpg', 'jpeg', 'png', 'webp'])],
        ]);

        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => $path]);

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    public function removeAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->update(['avatar' => null]);

        return redirect()->route('settings.account')->with('success', __('Avatar removed.'));
    }

    public function toggleProvider(Request $request): RedirectResponse
    {
        $user = $request->user();
        $business = tenant()->business();

        $provider = Provider::withTrashed()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        DB::transaction(function () use ($business, $user, $provider) {
            if ($provider === null) {
                $newProvider = Provider::create([
                    'business_id' => $business->id,
                    'user_id' => $user->id,
                ]);

                $this->schedules->writeFromBusinessHours($newProvider, $business);

                $activeServiceIds = $business->services()
                    ->where('is_active', true)
                    ->pluck('id')
                    ->all();

                if (! empty($activeServiceIds)) {
                    $newProvider->services()->syncWithoutDetaching($activeServiceIds);
                }

                return;
            }

            if ($provider->trashed()) {
                $provider->restore();

                return;
            }

            $provider->delete();
        });

        $flashKey = 'success';
        $flashMessage = __('Account updated.');

        $fresh = Provider::withTrashed()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        if ($fresh && $fresh->trashed()) {
            $unstaffed = $business->services()
                ->structurallyUnbookable()
                ->count();

            if ($unstaffed > 0) {
                $flashKey = 'warning';
                $flashMessage = __('You are no longer bookable. :count service(s) now have no provider — customers will not see them.', [
                    'count' => $unstaffed,
                ]);
            } else {
                $flashMessage = __('You are no longer bookable.');
            }
        } elseif ($fresh && ! $fresh->trashed() && $provider === null) {
            $flashMessage = __('You are now bookable.');
        } elseif ($fresh && ! $fresh->trashed()) {
            $flashMessage = __('You are bookable again.');
        }

        return redirect()->route('settings.account')->with($flashKey, $flashMessage);
    }
}
