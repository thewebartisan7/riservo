<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\StoreSettingsServiceRequest;
use App\Http\Requests\Dashboard\Settings\UpdateSettingsServiceRequest;
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
        $business = $request->user()->currentBusiness();

        $services = $business->services()
            ->withCount('bookings')
            ->with('collaborators:users.id,users.name')
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
                'collaborators' => $s->collaborators->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                ]),
            ]);

        return Inertia::render('dashboard/settings/services/index', [
            'services' => $services,
        ]);
    }

    public function create(Request $request): Response
    {
        $business = $request->user()->currentBusiness();

        $collaborators = $business->collaborators()
            ->wherePivot('is_active', true)
            ->get(['users.id', 'users.name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]);

        return Inertia::render('dashboard/settings/services/create', [
            'collaborators' => $collaborators,
        ]);
    }

    public function store(StoreSettingsServiceRequest $request): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $validated = $request->validated();

        $collaboratorIds = $validated['collaborator_ids'] ?? [];
        unset($validated['collaborator_ids']);

        $validated['slug'] = $this->generateUniqueSlug($business, $validated['name']);

        $service = $business->services()->create($validated);

        if (! empty($collaboratorIds)) {
            $service->collaborators()->sync($collaboratorIds);
        }

        return redirect()->route('settings.services')->with('success', __('Service created.'));
    }

    public function edit(Request $request, Service $service): Response
    {
        $business = $request->user()->currentBusiness();

        if ($service->business_id !== $business->id) {
            abort(403);
        }

        $collaborators = $business->collaborators()
            ->wherePivot('is_active', true)
            ->get(['users.id', 'users.name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]);

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
                'collaborator_ids' => $service->collaborators->pluck('id'),
            ],
            'collaborators' => $collaborators,
        ]);
    }

    public function update(UpdateSettingsServiceRequest $request, Service $service): RedirectResponse
    {
        $business = $request->user()->currentBusiness();

        if ($service->business_id !== $business->id) {
            abort(403);
        }

        $validated = $request->validated();
        $collaboratorIds = $validated['collaborator_ids'] ?? [];
        unset($validated['collaborator_ids']);

        $service->update($validated);
        $service->collaborators()->sync($collaboratorIds);

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
