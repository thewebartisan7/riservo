<?php

namespace App\Http\Middleware;

use App\Enums\BusinessMemberRole;
use App\Enums\PendingActionStatus;
use App\Models\PendingAction;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email', 'avatar'),
                'role' => fn () => $this->resolveRole($request),
                'business' => fn () => $this->resolveBusiness($request),
                'email_verified' => fn () => $request->user()?->hasVerifiedEmail() ?? false,
                'has_active_provider' => fn () => $this->resolveHasActiveProvider($request),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'bookability' => fn () => $this->resolveBookability($request),
            'calendarPendingActionsCount' => fn () => $this->resolveCalendarPendingActionsCount($request),
            'locale' => $locale,
            'translations' => fn () => $this->getTranslations($locale),
        ];
    }

    /**
     * Count pending calendar sync actions for the current viewer (D-088 extension).
     * Admins see the business-wide count; staff see only actions whose integration
     * they own.
     */
    private function resolveCalendarPendingActionsCount(Request $request): int
    {
        if (! $request->user()) {
            return 0;
        }

        $tenant = tenant();

        if (! $tenant->has() || $tenant->business() === null) {
            return 0;
        }

        $query = PendingAction::where('business_id', $tenant->business()->id)
            ->where('status', PendingActionStatus::Pending->value);

        if ($tenant->role() !== BusinessMemberRole::Admin) {
            $query->whereHas('integration', fn ($q) => $q->where('user_id', $request->user()->id));
        }

        return $query->count();
    }

    /**
     * Expose structurally unbookable active services to the authenticated layout
     * so the dashboard banner (D-078) can surface them. Admin-only and onboarded-
     * only; returns an empty list for every other role/state so the banner never
     * appears outside its intended context.
     *
     * @return array{unbookableServices: array<int, array{id: int, name: string}>}
     */
    private function resolveBookability(Request $request): array
    {
        $empty = ['unbookableServices' => []];

        if (! $request->user()) {
            return $empty;
        }

        $tenant = tenant();

        if (! $tenant->has()) {
            return $empty;
        }

        $business = $tenant->business();

        if ($business === null || ! $business->isOnboarded()) {
            return $empty;
        }

        if ($tenant->role() !== BusinessMemberRole::Admin) {
            return $empty;
        }

        return [
            'unbookableServices' => $business->services()
                ->structurallyUnbookable()
                ->get(['id', 'name'])
                ->map(fn (Service $s) => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all(),
        ];
    }

    /**
     * True when the actor has a non-soft-deleted Provider row in the current
     * tenant business. Drives Availability nav visibility for both admin and
     * staff (D-099). Returns false outside an authenticated tenant context.
     */
    private function resolveHasActiveProvider(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        $tenant = tenant();

        if (! $tenant->has()) {
            return false;
        }

        return Provider::where('business_id', $tenant->businessId())
            ->where('user_id', $user->id)
            ->exists();
    }

    private function resolveRole(Request $request): ?string
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $tenant = tenant();

        if ($tenant->has()) {
            return $tenant->role()->value;
        }

        if ($user->isCustomer()) {
            return 'customer';
        }

        return null;
    }

    /**
     * @return array{id: int, name: string, slug: string, subscription: array{status: string, trial_ends_at: string|null, current_period_ends_at: string|null}}|null
     */
    private function resolveBusiness(Request $request): ?array
    {
        if (! $request->user()) {
            return null;
        }

        $business = tenant()->business();

        if (! $business) {
            return null;
        }

        // Eager-load subscriptions so subscriptionStateForPayload() (D-089 §4.9)
        // collapses two queries (`subscriptions()->exists()` +
        // `subscription('default')`) into one relation-load.
        $business->loadMissing('subscriptions');

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'subscription' => $business->subscriptionStateForPayload(),
        ];
    }

    /** @return array<string, string> */
    private function getTranslations(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        if (! file_exists($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?? [];
    }
}
