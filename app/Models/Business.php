<?php

namespace App\Models;

use App\Enums\AssignmentStrategy;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    'allow_collaborator_choice',
    'cancellation_window_hours',
    'assignment_strategy',
    'reminder_hours',
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
            'allow_collaborator_choice' => 'boolean',
            'assignment_strategy' => AssignmentStrategy::class,
            'reminder_hours' => 'array',
        ];
    }

    /** @return BelongsToMany<User, $this, BusinessUser, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(BusinessUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this, BusinessUser, 'pivot'> */
    public function admins(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'admin');
    }

    /** @return BelongsToMany<User, $this, BusinessUser, 'pivot'> */
    public function collaborators(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'collaborator');
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
}
