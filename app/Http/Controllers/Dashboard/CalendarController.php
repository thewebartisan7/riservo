<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $business = $user->currentBusiness();
        $isAdmin = $user->currentBusinessRole()->value === 'admin';
        $timezone = $business->timezone;

        // Validate view parameter — default to week (D-058)
        $view = $request->string('view', 'week')->toString();
        if (! in_array($view, ['day', 'week', 'month'], true)) {
            $view = 'week';
        }

        // Anchor date in business timezone — default to today
        $dateParam = $request->string('date')->toString();
        $date = $dateParam
            ? CarbonImmutable::createFromFormat('Y-m-d', $dateParam, $timezone)?->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();

        if (! $date) {
            $date = CarbonImmutable::now($timezone)->startOfDay();
        }

        // Compute visible date range based on view
        [$rangeStart, $rangeEnd] = $this->getDateRange($view, $date);

        // Convert to UTC for database query
        $utcStart = $rangeStart->setTimezone('UTC');
        $utcEnd = $rangeEnd->setTimezone('UTC');

        // Query bookings within range
        $query = Booking::where('business_id', $business->id)
            ->where('starts_at', '<', $utcEnd)
            ->where('ends_at', '>', $utcStart)
            ->with([
                'service:id,name,duration_minutes,price',
                'collaborator:id,name,avatar',
                'customer:id,name,email,phone',
            ])
            ->orderBy('starts_at');

        if (! $isAdmin) {
            $query->where('collaborator_id', $user->id);
        }

        $bookings = $query->get();

        // Collaborators for the filter (admin only) — D-060
        $collaborators = $isAdmin
            ? $business->users()
                ->wherePivotIn('role', ['admin', 'collaborator'])
                ->orderBy('users.id')
                ->get(['users.id', 'users.name', 'users.avatar'])
            : collect();

        // Services for manual booking dialog
        $services = $business->services()
            ->where('is_active', true)
            ->with('collaborators:users.id,users.name,users.avatar')
            ->get(['id', 'name', 'duration_minutes', 'price', 'slug']);

        return Inertia::render('dashboard/calendar', [
            'bookings' => $bookings->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'status' => $booking->status->value,
                'source' => $booking->source->value,
                'notes' => $booking->notes,
                'internal_notes' => $booking->internal_notes,
                'created_at' => $booking->created_at->toIso8601String(),
                'cancellation_token' => $booking->cancellation_token,
                'service' => [
                    'id' => $booking->service->id,
                    'name' => $booking->service->name,
                    'duration_minutes' => $booking->service->duration_minutes,
                    'price' => $booking->service->price,
                ],
                'collaborator' => [
                    'id' => $booking->collaborator->id,
                    'name' => $booking->collaborator->name,
                    'avatar_url' => $booking->collaborator->avatar
                        ? asset('storage/'.$booking->collaborator->avatar)
                        : null,
                ],
                'customer' => [
                    'id' => $booking->customer->id,
                    'name' => $booking->customer->name,
                    'email' => $booking->customer->email,
                    'phone' => $booking->customer->phone,
                ],
            ]),
            'collaborators' => $collaborators->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar_url' => $u->avatar ? asset('storage/'.$u->avatar) : null,
            ])->values(),
            'services' => $services->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'duration_minutes' => $service->duration_minutes,
                'price' => $service->price,
                'collaborators' => $service->collaborators->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                ])->values(),
            ])->values(),
            'view' => $view,
            'date' => $date->format('Y-m-d'),
            'isAdmin' => $isAdmin,
            'timezone' => $timezone,
        ]);
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function getDateRange(string $view, CarbonImmutable $date): array
    {
        return match ($view) {
            'day' => [
                $date->startOfDay(),
                $date->endOfDay(),
            ],
            'month' => [
                $date->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY),
                $date->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY),
            ],
            default => [
                $date->startOfWeek(CarbonImmutable::MONDAY),
                $date->endOfWeek(CarbonImmutable::SUNDAY),
            ],
        };
    }
}
