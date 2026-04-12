<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use Database\Factories\AvailabilityRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['collaborator_id', 'business_id', 'day_of_week', 'start_time', 'end_time'])]
class AvailabilityRule extends Model
{
    /** @use HasFactory<AvailabilityRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collaborator_id');
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
