<?php

namespace App\Models;

use App\Enums\ExceptionType;
use Database\Factories\AvailabilityExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'collaborator_id',
    'start_date',
    'end_date',
    'start_time',
    'end_time',
    'type',
    'reason',
])]
class AvailabilityException extends Model
{
    /** @use HasFactory<AvailabilityExceptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'type' => ExceptionType::class,
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
}
