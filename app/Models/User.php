<?php

namespace App\Models;

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

/**
 * @property-read BusinessMember|null $pivot
 */
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
     * Check if the user is a member of any business with any of the given roles.
     *
     * Use this for "is this user a business user at all" discriminators (e.g., login
     * redirect: business users go to /dashboard, customers go to /my-bookings). For
     * role-based authorisation tied to the current request, use tenant()->role()
     * instead — hasBusinessRole() does not consider which business is active.
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
     * Active memberships only. Mirrors Business::members() — the BusinessMember
     * pivot uses SoftDeletes but BelongsToMany does not auto-apply the scope,
     * so we filter trashed pivot rows out explicitly (D-079).
     *
     * @return BelongsToMany<Business, $this, BusinessMember, 'pivot'>
     */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_members')
            ->using(BusinessMember::class)
            ->withPivot(['role'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    /** @return HasMany<Provider, $this> */
    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
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
