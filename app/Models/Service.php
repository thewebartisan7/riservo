<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'business_id',
    'name',
    'slug',
    'description',
    'duration_minutes',
    'price',
    'buffer_before',
    'buffer_after',
    'slot_interval_minutes',
    'is_active',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsToMany<Provider, $this> */
    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class, 'provider_service')
            ->withTimestamps();
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Active services with at least one non-soft-deleted provider attached and
     * at least one of those providers holding an availability rule. Single
     * source of truth for "a customer can actually book this service" —
     * see D-078.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeStructurallyBookable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereHas('providers', fn (Builder $q) => $q->has('availabilityRules'));
    }

    /**
     * Active services that fail at least one of the structural-bookability
     * conditions. Mirrors scopeStructurallyBookable — intentionally scoped to
     * active services only; inactive services are never advertised.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeStructurallyUnbookable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereDoesntHave('providers', fn (Builder $q) => $q->has('availabilityRules'));
    }
}
