<?php

namespace App\Models;

use Database\Factories\CalendarWatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at
 */
#[Fillable([
    'integration_id',
    'calendar_id',
    'channel_id',
    'resource_id',
    'channel_token',
    'sync_token',
    'expires_at',
])]
class CalendarWatch extends Model
{
    /** @use HasFactory<CalendarWatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CalendarIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(CalendarIntegration::class, 'integration_id');
    }
}
