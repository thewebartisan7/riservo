<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared Business/User/Service fixtures for browser tests.
 *
 * Each returned admin/staff user has a bcrypt-hashed `password` plaintext and
 * `email_verified_at=now()` so tests can immediately call `AuthHelper::loginAs`.
 */
final class BusinessSetup
{
    private const DEFAULT_PASSWORD = 'password';

    private const WEEKDAYS = [1, 2, 3, 4, 5]; // ISO 1–5 = Mon–Fri per D-024.

    /**
     * A business with a single admin user. No hours, services, or providers.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{business: Business, admin: User}
     */
    public static function createBusinessWithAdmin(array $overrides = []): array
    {
        $business = Business::factory()->create($overrides);
        $admin = User::factory()->create();
        attachAdmin($business, $admin);

        return ['business' => $business, 'admin' => $admin];
    }

    /**
     * A business with an admin and $count staff members. No providers.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{business: Business, admin: User, staff: Collection<int, User>}
     */
    public static function createBusinessWithStaff(int $count = 1, array $overrides = []): array
    {
        ['business' => $business, 'admin' => $admin] = self::createBusinessWithAdmin($overrides);

        $staff = new Collection;
        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->create();
            attachStaff($business, $user);
            $staff->push($user);
        }

        return ['business' => $business, 'admin' => $admin, 'staff' => $staff];
    }

    /**
     * A business with an admin and one active service. No providers.
     *
     * @param  array<string, mixed>  $serviceOverrides
     * @return array{business: Business, admin: User, service: Service}
     */
    public static function createBusinessWithService(array $serviceOverrides = []): array
    {
        ['business' => $business, 'admin' => $admin] = self::createBusinessWithAdmin();

        $service = Service::factory()
            ->for($business)
            ->create($serviceOverrides);

        return ['business' => $business, 'admin' => $admin, 'service' => $service];
    }

    /**
     * A launched, onboarding-complete business. Admin is the sole provider.
     *
     * Layout:
     *   - Mon–Fri 09:00–18:00 BusinessHour rows (weekends absent = closed).
     *   - One Service: 60 min duration, 30-min slot interval, no buffers.
     *   - Admin has a Provider row with Mon–Fri 09:00–18:00 AvailabilityRules.
     *   - Service is attached to the provider.
     *   - One unlinked Customer row (seed).
     *
     * @param  array<string, mixed>  $overrides
     * @return array{business: Business, admin: User, provider: Provider, service: Service, customer: Customer}
     */
    public static function createLaunchedBusiness(array $overrides = []): array
    {
        $business = Business::factory()->onboarded()->create($overrides);
        $admin = User::factory()->create();
        attachAdmin($business, $admin);

        self::seedWeeklyBusinessHours($business);

        $service = Service::factory()
            ->for($business)
            ->create([
                'duration_minutes' => 60,
                'slot_interval_minutes' => 30,
                'buffer_before' => 0,
                'buffer_after' => 0,
            ]);

        $provider = attachProvider($business, $admin);
        self::seedWeeklyAvailabilityRules($provider, $business);
        $provider->services()->attach($service);

        $customer = Customer::factory()->create();

        return [
            'business' => $business,
            'admin' => $admin,
            'provider' => $provider,
            'service' => $service,
            'customer' => $customer,
        ];
    }

    /**
     * A launched business with $providerCount providers attached to its single service.
     * First provider is the admin; subsequent providers are staff users.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{business: Business, admin: User, providers: Collection<int, Provider>, service: Service, customer: Customer}
     */
    public static function createBusinessWithProviders(int $providerCount = 2, array $overrides = []): array
    {
        $launched = self::createLaunchedBusiness($overrides);
        $business = $launched['business'];
        $service = $launched['service'];

        $providers = new Collection([$launched['provider']]);

        for ($i = 1; $i < $providerCount; $i++) {
            $staffUser = User::factory()->create();
            $provider = attachProvider($business, $staffUser);
            self::seedWeeklyAvailabilityRules($provider, $business);
            $provider->services()->attach($service);
            $providers->push($provider);
        }

        return [
            'business' => $business,
            'admin' => $launched['admin'],
            'providers' => $providers,
            'service' => $service,
            'customer' => $launched['customer'],
        ];
    }

    private static function seedWeeklyBusinessHours(Business $business): void
    {
        foreach (self::WEEKDAYS as $day) {
            BusinessHour::factory()->for($business)->create([
                'day_of_week' => $day,
                'open_time' => '09:00',
                'close_time' => '18:00',
            ]);
        }
    }

    private static function seedWeeklyAvailabilityRules(Provider $provider, Business $business): void
    {
        foreach (self::WEEKDAYS as $day) {
            AvailabilityRule::factory()
                ->for($provider)
                ->for($business)
                ->create([
                    'day_of_week' => $day,
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                ]);
        }
    }
}
