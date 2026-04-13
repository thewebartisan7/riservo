<?php

namespace App\Models;

use App\Enums\BusinessUserRole;
use Database\Factories\BusinessInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'email', 'role', 'token', 'service_ids', 'expires_at', 'accepted_at'])]
class BusinessInvitation extends Model
{
    /** @use HasFactory<BusinessInvitationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => BusinessUserRole::class,
            'service_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isExpired() && ! $this->isAccepted();
    }
}
