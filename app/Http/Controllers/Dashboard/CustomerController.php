<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $business = $user->currentBusiness();

        $query = Customer::whereHas('bookings', fn ($q) => $q->where('business_id', $business->id));

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query
            ->withCount(['bookings' => fn ($q) => $q->where('business_id', $business->id)])
            ->withMax(['bookings' => fn ($q) => $q->where('business_id', $business->id)], 'starts_at')
            ->orderByDesc('bookings_max_starts_at')
            ->paginate(20)
            ->withQueryString();

        $customers->getCollection()->transform(fn (Customer $customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'bookings_count' => $customer->bookings_count,
            'last_booking_at' => $customer->bookings_max_starts_at,
        ]);

        return Inertia::render('dashboard/customers', [
            'customers' => $customers,
            'filters' => [
                'search' => $request->string('search', ''),
            ],
        ]);
    }

    public function show(Request $request, Customer $customer): Response
    {
        /** @var User $user */
        $user = $request->user();
        $business = $user->currentBusiness();

        // Ensure customer belongs to this business
        $hasBookings = Booking::where('business_id', $business->id)
            ->where('customer_id', $customer->id)
            ->exists();

        abort_unless($hasBookings, 404);

        $bookings = Booking::where('business_id', $business->id)
            ->where('customer_id', $customer->id)
            ->with(['service:id,name,duration_minutes,price', 'collaborator:id,name'])
            ->orderByDesc('starts_at')
            ->get();

        $stats = [
            'total_bookings' => $bookings->count(),
            'first_booking_at' => $bookings->last()?->starts_at?->toIso8601String(),
            'last_booking_at' => $bookings->first()?->starts_at?->toIso8601String(),
        ];

        return Inertia::render('dashboard/customer-show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
            'stats' => $stats,
            'bookings' => $bookings->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'status' => $booking->status->value,
                'source' => $booking->source->value,
                'service' => [
                    'name' => $booking->service->name,
                    'duration_minutes' => $booking->service->duration_minutes,
                    'price' => $booking->service->price,
                ],
                'collaborator' => [
                    'id' => $booking->collaborator->id,
                    'name' => $booking->collaborator->name,
                ],
            ])->values(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = $user->currentBusiness();

        $request->validate([
            'q' => ['required', 'string', 'min:2'],
        ]);

        $search = $request->string('q');

        $customers = Customer::whereHas('bookings', fn ($q) => $q->where('business_id', $business->id))
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'email', 'phone']);

        return response()->json(['customers' => $customers]);
    }
}
