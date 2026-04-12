<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use Database\Factories\BusinessHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'day_of_week', 'open_time', 'close_time'])]
class BusinessHour extends Model
{
    /** @use HasFactory<BusinessHourFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
