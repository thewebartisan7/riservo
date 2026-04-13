<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Str;

class SlugService
{
    /**
     * System route prefixes that cannot be used as business slugs.
     * Maintain this list as new routes are added. See D-039.
     *
     * @var array<int, string>
     */
    public const RESERVED_SLUGS = [
        'about',
        'admin',
        'api',
        'billing',
        'booking',
        'bookings',
        'customer',
        'dashboard',
        'email',
        'embed',
        'forgot-password',
        'health',
        'help',
        'invite',
        'login',
        'logout',
        'magic-link',
        'my-bookings',
        'onboarding',
        'pricing',
        'privacy',
        'register',
        'reset-password',
        'settings',
        'terms',
        'up',
        'widget',
    ];

    /**
     * Generate a unique slug from a business name.
     */
    public function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'business';
        }

        $slug = $base;
        $counter = 2;

        while ($this->isReserved($slug) || $this->isTaken($slug)) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    public function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED_SLUGS, true);
    }

    public function isTaken(string $slug): bool
    {
        return Business::where('slug', $slug)->exists();
    }

    public function isTakenExcluding(string $slug, int $businessId): bool
    {
        return Business::where('slug', $slug)->where('id', '!=', $businessId)->exists();
    }
}
