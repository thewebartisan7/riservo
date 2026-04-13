<?php

namespace App\Models;

use App\Enums\BusinessUserRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property BusinessUserRole $role
 */
class BusinessUser extends Pivot
{
    protected $table = 'business_user';

    protected function casts(): array
    {
        return [
            'role' => BusinessUserRole::class,
        ];
    }
}
