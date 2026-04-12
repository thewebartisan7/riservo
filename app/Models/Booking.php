<?php

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'collaborator_id',
    'service_id',
    'customer_id',
    'starts_at',
    'ends_at',
    'status',
    'source',
    'external_calendar_id',
    'payment_status',
    'notes',
    'cancellation_token',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => BookingStatus::class,
            'source' => BookingSource::class,
            'payment_status' => PaymentStatus::class,
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collaborator_id');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
