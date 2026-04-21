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
use Laravel\Cashier\Billable;

/**
 * @property PaymentMode $payment_mode
 * @property ConfirmationMode $confirmation_mode
 * @property AssignmentStrategy $assignment_strategy
 * @property Carbon|null $onboarding_completed_at
 * @property array<int, int>|null $reminder_hours
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property Carbon|null $trial_ends_at
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
    // Cashier-managed columns. Required in fillable so webhook handlers and
    // Cashier's customer/subscription updates can mass-assign them silently.
    'stripe_id',
    'pm_type',
    'pm_last_four',
    'trial_ends_at',
])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use Billable, HasFactory;

    protected function casts(): array
    {
        return [
            'payment_mode' => PaymentMode::class,
            'confirmation_mode' => ConfirmationMode::class,
            'allow_provider_choice' => 'boolean',
            'assignment_strategy' => AssignmentStrategy::class,
            'reminder_hours' => 'array',
            'onboarding_completed_at' => 'datetime',
            // Cashier-managed timestamp. Cashier's own predicates
            // (onGenericTrial, hasExpiredGenericTrial, …) call ->isFuture()
            // / ->isPast() on this column and our subscriptionStateForPayload()
            // serialises it as ISO; both require a Carbon instance.
            'trial_ends_at' => 'datetime',
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

    /**
     * Indefinite trial: a business is on trial for as long as it has never
     * created any subscription record (D-089). Reads the eager-loaded
     * `subscriptions` relation when present (HandleInertiaRequests warms it)
     * to avoid an extra query on the hot Inertia path.
     */
    public function onTrial(string $type = 'default', $price = null): bool
    {
        // TODO we must remember about this, or better not to override but add long trial e.g. 3-6 months
        return $this->subscriptions->isEmpty();
    }

    /**
     * Product-semantic state. One of:
     *   - 'trial'       — no subscription has ever been created
     *   - 'active'      — current subscription is healthy
     *   - 'past_due'    — Stripe is dunning a failing card; access still allowed
     *   - 'canceled'    — cancel_at_period_end set, still inside the paid period
     *   - 'read_only'   — subscription has fully ended
     */
    public function subscriptionState(): string
    {
        if ($this->onTrial()) {
            return 'trial';
        }

        $sub = $this->subscription();

        if ($sub === null || $sub->ended()) {
            return 'read_only';
        }

        if ($sub->pastDue()) {
            return 'past_due';
        }

        if ($sub->canceled() && $sub->onGracePeriod()) {
            return 'canceled';
        }

        return 'active';
    }

    /**
     * True for every state except `read_only`. Used by EnsureBusinessCanWrite
     * (D-090) to gate mutating dashboard verbs.
     */
    public function canWrite(): bool
    {
        return $this->subscriptionState() !== 'read_only';
    }

    /**
     * Shaped payload for the `auth.business.subscription` shared Inertia prop
     * (D-089 §4.9). Trial businesses report `trial_ends_at = null` because the
     * trial is indefinite; `current_period_ends_at` reports the active
     * subscription's `ends_at` (set during the cancel-grace window) or the
     * Stripe-side period end via the items relation when active.
     *
     * @return array{status: string, trial_ends_at: string|null, current_period_ends_at: string|null}
     */
    public function subscriptionStateForPayload(): array
    {
        $status = $this->subscriptionState();
        $sub = $status === 'trial' ? null : $this->subscription();

        return [
            'status' => $status,
            'trial_ends_at' => $this->trial_ends_at?->toISOString(),
            'current_period_ends_at' => $sub?->ends_at?->toISOString(),
        ];
    }
}
