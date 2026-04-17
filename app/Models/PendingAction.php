<?php

namespace App\Models;

use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use Database\Factories\PendingActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property PendingActionType $type
 * @property PendingActionStatus $status
 * @property array<string, mixed> $payload
 * @property Carbon|null $resolved_at
 */
#[Fillable([
    'business_id',
    'integration_id',
    'booking_id',
    'type',
    'payload',
    'status',
    'resolved_by_user_id',
    'resolution_note',
    'resolved_at',
])]
class PendingAction extends Model
{
    /** @use HasFactory<PendingActionFactory> */
    use HasFactory;

    protected $table = 'calendar_pending_actions';

    protected function casts(): array
    {
        return [
            'type' => PendingActionType::class,
            'status' => PendingActionStatus::class,
            'payload' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<CalendarIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(CalendarIntegration::class, 'integration_id');
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
