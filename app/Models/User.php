<?php

namespace App\Models;

use App\Enums\BusinessUserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'avatar', 'magic_link_token'])]
#[Hidden(['password', 'remember_token', 'magic_link_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Check if the user has any of the given business roles.
     */
    public function hasBusinessRole(string ...$roles): bool
    {
        return $this->businesses()
            ->wherePivotIn('role', $roles)
            ->exists();
    }

    /**
     * Check if the user has a linked customer record.
     */
    public function isCustomer(): bool
    {
        return Customer::where('user_id', $this->id)->exists();
    }

    /**
     * Get the user's current business (MVP: users belong to one business).
     */
    public function currentBusiness(): ?Business
    {
        return $this->businesses()->first();
    }

    /**
     * Get the user's role in their current business.
     */
    public function currentBusinessRole(): ?BusinessUserRole
    {
        /** @var (Business&object{pivot: BusinessUser})|null $business */
        $business = $this->businesses()->first();

        if (! $business) {
            return null;
        }

        return $business->pivot->role;
    }

    /** @return BelongsToMany<Business, $this, BusinessUser, 'pivot'> */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)
            ->using(BusinessUser::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    /** @return HasMany<AvailabilityRule, $this> */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class, 'collaborator_id');
    }

    /** @return HasMany<AvailabilityException, $this> */
    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class, 'collaborator_id');
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'collaborator_service', 'collaborator_id')
            ->withTimestamps();
    }

    /** @return HasMany<Booking, $this> */
    public function bookingsAsCollaborator(): HasMany
    {
        return $this->hasMany(Booking::class, 'collaborator_id');
    }

    /** @return HasOne<CalendarIntegration, $this> */
    public function calendarIntegration(): HasOne
    {
        return $this->hasOne(CalendarIntegration::class);
    }

    /** @return HasOne<Customer, $this> */
    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }
}
