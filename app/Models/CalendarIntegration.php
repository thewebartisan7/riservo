<?php

namespace App\Models;

use Database\Factories\CalendarIntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'provider',
    'access_token',
    'refresh_token',
    'calendar_id',
    'webhook_channel_id',
    'webhook_expiry',
])]
#[Hidden(['access_token', 'refresh_token'])]
class CalendarIntegration extends Model
{
    /** @use HasFactory<CalendarIntegrationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'webhook_expiry' => 'datetime',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
