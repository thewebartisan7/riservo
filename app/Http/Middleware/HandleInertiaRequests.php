<?php

namespace App\Http\Middleware;

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
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'locale' => $locale,
            'translations' => fn () => $this->getTranslations($locale),
        ];
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
     * @return array{id: int, name: string, slug: string}|null
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

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
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
