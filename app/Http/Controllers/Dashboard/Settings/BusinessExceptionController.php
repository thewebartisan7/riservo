<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\StoreBusinessExceptionRequest;
use App\Http\Requests\Dashboard\Settings\UpdateBusinessExceptionRequest;
use App\Models\AvailabilityException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessExceptionController extends Controller
{
    public function index(Request $request): Response
    {
        $business = $request->user()->currentBusiness();

        $exceptions = $business->availabilityExceptions()
            ->whereNull('collaborator_id')
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (AvailabilityException $e) => [
                'id' => $e->id,
                'start_date' => $e->start_date->format('Y-m-d'),
                'end_date' => $e->end_date->format('Y-m-d'),
                'start_time' => $e->start_time ? substr($e->start_time, 0, 5) : null,
                'end_time' => $e->end_time ? substr($e->end_time, 0, 5) : null,
                'type' => $e->type->value,
                'reason' => $e->reason,
            ]);

        return Inertia::render('dashboard/settings/exceptions', [
            'exceptions' => $exceptions,
        ]);
    }

    public function store(StoreBusinessExceptionRequest $request): RedirectResponse
    {
        $business = $request->user()->currentBusiness();

        $business->availabilityExceptions()->create(
            array_merge($request->validated(), ['collaborator_id' => null])
        );

        return redirect()->route('settings.exceptions')->with('success', __('Exception added.'));
    }

    public function update(UpdateBusinessExceptionRequest $request, AvailabilityException $exception): RedirectResponse
    {
        $business = $request->user()->currentBusiness();

        if ($exception->business_id !== $business->id || $exception->collaborator_id !== null) {
            abort(403);
        }

        $exception->update($request->validated());

        return redirect()->route('settings.exceptions')->with('success', __('Exception updated.'));
    }

    public function destroy(Request $request, AvailabilityException $exception): RedirectResponse
    {
        $business = $request->user()->currentBusiness();

        if ($exception->business_id !== $business->id || $exception->collaborator_id !== null) {
            abort(403);
        }

        $exception->delete();

        return redirect()->route('settings.exceptions')->with('success', __('Exception deleted.'));
    }
}
