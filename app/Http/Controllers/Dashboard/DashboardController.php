<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $business = $user->currentBusiness();
        $timezone = $business->timezone;

        $todayStart = CarbonImmutable::now($timezone)->startOfDay()->utc();
        $todayEnd = CarbonImmutable::now($timezone)->endOfDay()->utc();
        $weekEnd = CarbonImmutable::now($timezone)->endOfWeek()->endOfDay()->utc();

        $baseQuery = Booking::where('business_id', $business->id);

        if ($user->currentBusinessRole()->value === 'collaborator') {
            $baseQuery->where('collaborator_id', $user->id);
        }

        $todayBookings = (clone $baseQuery)
            ->whereBetween('starts_at', [$todayStart, $todayEnd])
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
            ->with(['service:id,name,duration_minutes', 'collaborator:id,name,avatar', 'customer:id,name'])
            ->orderBy('starts_at')
            ->get();

        $stats = [
            'today_count' => $todayBookings->count(),
            'week_count' => (clone $baseQuery)
                ->whereBetween('starts_at', [$todayStart, $weekEnd])
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
                ->count(),
            'upcoming_count' => (clone $baseQuery)
                ->where('starts_at', '>=', $todayStart)
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
                ->count(),
            'pending_count' => (clone $baseQuery)
                ->where('status', BookingStatus::Pending)
                ->count(),
        ];

        return Inertia::render('dashboard', [
            'stats' => $stats,
            'todayBookings' => $todayBookings->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'status' => $booking->status->value,
                'service' => [
                    'name' => $booking->service->name,
                    'duration_minutes' => $booking->service->duration_minutes,
                ],
                'collaborator' => [
                    'id' => $booking->collaborator->id,
                    'name' => $booking->collaborator->name,
                ],
                'customer' => [
                    'name' => $booking->customer->name,
                ],
            ])->values(),
            'timezone' => $timezone,
        ]);
    }
}
