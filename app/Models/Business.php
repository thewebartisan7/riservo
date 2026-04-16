<?php

namespace App\Models;

use App\Enums\AssignmentStrategy;
use App\Enums\BusinessMemberRole;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property PaymentMode $payment_mode
 * @property ConfirmationMode $confirmation_mode
 * @property AssignmentStrategy $assignment_strategy
 * @property Carbon|null $onboarding_completed_at
 */
#[Fillable([
    'name',
    'slug',
    'description',
    'logo',
    'phone',
    'email',
    'address',
    'timezone',
    'payment_mode',
    'confirmation_mode',
    'allow_provider_choice',
    'cancellation_window_hours',
    'assignment_strategy',
    'reminder_hours',
    'onboarding_step',
    'onboarding_completed_at',
])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payment_mode' => PaymentMode::class,
            'confirmation_mode' => ConfirmationMode::class,
            'allow_provider_choice' => 'boolean',
            'assignment_strategy' => AssignmentStrategy::class,
            'reminder_hours' => 'array',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /**
     * Active memberships only. The BusinessMember pivot uses SoftDeletes, but
     * Eloquent's BelongsToMany does not auto-apply a pivot's SoftDeletes scope,
     * so we filter here explicitly. Per D-079, the pivot uniqueness shape is
     * (business_id, user_id, deleted_at) — trashed rows live alongside active
     * ones and must be hidden from every live read.
     *
     * @return BelongsToMany<User, $this, BusinessMember, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_members')
            ->using(BusinessMember::class)
            ->withPivot(['role'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this, BusinessMember, 'pivot'> */
    public function admins(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'admin');
    }

    /** @return BelongsToMany<User, $this, BusinessMember, 'pivot'> */
    public function staff(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'staff');
    }

    /** @return HasMany<Provider, $this> */
    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
    }

    /** @return HasMany<BusinessHour, $this> */
    public function businessHours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<AvailabilityException, $this> */
    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class);
    }

    /** @return HasMany<BusinessInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(BusinessInvitation::class);
    }

    public function isOnboarded(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /**
     * Restore-or-create membership for `(this, user)`. Per D-079 this is the
     * single home for the membership re-entry semantic — every caller that
     * adds a user to a business routes through this method so the flow is
     * identical whether the row is fresh or restored from soft-delete.
     *
     * If a trashed row exists for the pair, restore it and update its role.
     * Otherwise attach a new row. Either way the returned pivot is active
     * (deleted_at IS NULL) and carries the requested role.
     */
    public function attachOrRestoreMember(User $user, BusinessMemberRole $role): BusinessMember
    {
        $existing = BusinessMember::withTrashed()
            ->where('business_id', $this->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->update(['role' => $role->value]);

            return $existing->refresh();
        }

        $this->members()->attach($user->id, ['role' => $role->value]);

        return BusinessMember::query()
            ->where('business_id', $this->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    /**
     * Normalise a cleared `logo` field submitted via profile forms. When the
     * form posts an empty value (null after the ConvertEmptyStringsToNull
     * middleware, or an empty string when that middleware is off), delete the
     * current file from the public disk and force the persisted value to null.
     *
     * @param  array<string, mixed>  $data
     */
    public function removeLogoIfCleared(array &$data): void
    {
        if (! array_key_exists('logo', $data) || ! in_array($data['logo'], [null, ''], true)) {
            return;
        }

        if ($this->logo && Storage::disk('public')->exists($this->logo)) {
            Storage::disk('public')->delete($this->logo);
        }

        $data['logo'] = null;
    }
}
