<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\StoreSettingsServiceRequest;
use App\Http\Requests\Dashboard\Settings\UpdateSettingsServiceRequest;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $business = tenant()->business();

        $services = $business->services()
            ->withCount('bookings')
            ->with(['providers' => fn ($q) => $q->where('providers.business_id', $business->id)->with('user:id,name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'duration_minutes' => $s->duration_minutes,
                'price' => $s->price,
                'is_active' => $s->is_active,
                'bookings_count' => $s->bookings_count,
                'providers' => $s->providers->map(fn (Provider $p) => [
                    'id' => $p->id,
                    'name' => $p->user?->name ?? '',
                ])->values(),
            ]);

        return Inertia::render('dashboard/settings/services/index', [
            'services' => $services,
        ]);
    }

    public function create(Request $request): Response
    {
        $business = tenant()->business();

        $providers = $business->providers()
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Provider $p) => ['id' => $p->id, 'name' => $p->user?->name ?? '']);

        return Inertia::render('dashboard/settings/services/create', [
            'providers' => $providers,
        ]);
    }

    public function store(StoreSettingsServiceRequest $request): RedirectResponse
    {
        $business = tenant()->business();
        $validated = $request->validated();

        $providerIds = $validated['provider_ids'] ?? [];
        unset($validated['provider_ids']);

        $validated['slug'] = $this->generateUniqueSlug($business, $validated['name']);

        $service = $business->services()->create($validated);

        if (! empty($providerIds)) {
            $service->providers()->sync($providerIds);
        }

        return redirect()->route('settings.services')->with('success', __('Service created.'));
    }

    public function edit(Request $request, Service $service): Response
    {
        $business = tenant()->business();

        if ($service->business_id !== $business->id) {
            abort(403);
        }

        $providers = $business->providers()
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Provider $p) => ['id' => $p->id, 'name' => $p->user?->name ?? '']);

        return Inertia::render('dashboard/settings/services/edit', [
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'slug' => $service->slug,
                'description' => $service->description,
                'duration_minutes' => $service->duration_minutes,
                'price' => $service->price,
                'buffer_before' => $service->buffer_before,
                'buffer_after' => $service->buffer_after,
                'slot_interval_minutes' => $service->slot_interval_minutes,
                'is_active' => $service->is_active,
                'provider_ids' => $service->providers()
                    ->where('providers.business_id', $business->id)
                    ->pluck('providers.id'),
            ],
            'providers' => $providers,
        ]);
    }

    public function update(UpdateSettingsServiceRequest $request, Service $service): RedirectResponse
    {
        $business = tenant()->business();

        if ($service->business_id !== $business->id) {
            abort(403);
        }

        $validated = $request->validated();
        $providerIds = $validated['provider_ids'] ?? [];
        unset($validated['provider_ids']);

        $service->update($validated);
        $service->providers()->sync($providerIds);

        return redirect()->route('settings.services.edit', $service)->with('success', __('Service updated.'));
    }

    private function generateUniqueSlug(mixed $business, string $name): string
    {
        $slug = Str::slug($name);
        $baseSlug = $slug;
        $counter = 2;

        while ($business->services()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
