<?php

namespace App\Models;

use Database\Factories\CalendarIntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $webhook_expiry
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_pushed_at
 * @property Carbon|null $sync_error_at
 * @property Carbon|null $push_error_at
 * @property array<int, string>|null $conflict_calendar_ids
 */
#[Fillable([
    'user_id',
    'business_id',
    'provider',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'calendar_id',
    'destination_calendar_id',
    'conflict_calendar_ids',
    'sync_token',
    'google_account_email',
    'webhook_channel_id',
    'webhook_resource_id',
    'webhook_channel_token',
    'webhook_expiry',
    'last_synced_at',
    'last_pushed_at',
    'sync_error',
    'sync_error_at',
    'push_error',
    'push_error_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class CalendarIntegration extends Model
{
    /** @use HasFactory<CalendarIntegrationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'webhook_expiry' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_pushed_at' => 'datetime',
            'sync_error_at' => 'datetime',
            'push_error_at' => 'datetime',
            'conflict_calendar_ids' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    /**
     * True once the user has completed the configure step and picked a
     * destination calendar. MVPC-1 rows are unconfigured (null).
     */
    public function isConfigured(): bool
    {
        return $this->destination_calendar_id !== null && $this->business_id !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<CalendarWatch, $this> */
    public function watches(): HasMany
    {
        return $this->hasMany(CalendarWatch::class, 'integration_id');
    }

    /** @return HasMany<PendingAction, $this> */
    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class, 'integration_id');
    }
}
