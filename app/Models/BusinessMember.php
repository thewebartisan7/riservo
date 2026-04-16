<?php

namespace App\Models;

use App\Enums\BusinessMemberRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property BusinessMemberRole $role
 */
class BusinessMember extends Pivot
{
    use SoftDeletes;

    protected $table = 'business_members';

    protected $fillable = ['role'];

    protected function casts(): array
    {
        return [
            'role' => BusinessMemberRole::class,
        ];
    }
}
