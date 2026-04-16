<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\UpdateProfileRequest;
use App\Services\SlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $business = tenant()->business();

        return Inertia::render('dashboard/settings/profile', [
            'business' => $business->only('name', 'slug', 'description', 'logo', 'phone', 'email', 'address'),
            'logoUrl' => $business->logo ? Storage::disk('public')->url($business->logo) : null,
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $business = tenant()->business();
        $data = $request->validated();
        $business->removeLogoIfCleared($data);
        $business->update($data);

        return redirect()->route('settings.profile')->with('success', __('Business profile updated.'));
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', File::image()->max(2048)->types(['jpg', 'jpeg', 'png', 'webp'])],
        ]);

        $business = tenant()->business();

        if ($business->logo && Storage::disk('public')->exists($business->logo)) {
            Storage::disk('public')->delete($business->logo);
        }

        $path = $request->file('logo')->store('logos', 'public');
        $business->update(['logo' => $path]);

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    public function checkSlug(Request $request): JsonResponse
    {
        $request->validate([
            'slug' => ['required', 'string', 'max:255'],
        ]);

        $slug = $request->input('slug');
        $business = tenant()->business();
        $slugService = app(SlugService::class);

        $isOwn = $business->slug === $slug;
        $isReserved = $slugService->isReserved($slug);
        $isTaken = $slugService->isTakenExcluding($slug, $business->id);
        $isValidFormat = (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);

        return response()->json([
            'available' => $isValidFormat && ! $isReserved && ($isOwn || ! $isTaken),
        ]);
    }
}
