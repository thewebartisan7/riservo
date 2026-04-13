<?php

namespace Database\Seeders;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\BusinessUserRole;
use App\Enums\DayOfWeek;
use App\Enums\ExceptionType;
use App\Enums\PaymentStatus;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        $business = $this->createBusiness();
        $this->createBusinessHours($business);
        $users = $this->createUsers($business);
        $services = $this->createServices($business);
        $this->assignServicesToCollaborators($users, $services);
        $this->createAvailabilityRules($business, $users);
        $this->createAvailabilityExceptions($business, $users);
        $customers = $this->createCustomers();
        $this->createBookings($business, $users, $services, $customers);
    }

    private function createBusiness(): Business
    {
        return Business::create([
            'name' => 'Salone Bella',
            'slug' => 'salone-bella',
            'description' => 'Il miglior salone di bellezza a Lugano. Taglio, colore e trattamenti per uomo e donna.',
            'phone' => '+41 91 123 45 67',
            'email' => 'info@salone-bella.ch',
            'address' => 'Via Nassa 24, 6900 Lugano',
            'timezone' => 'Europe/Zurich',
            'cancellation_window_hours' => 24,
            'reminder_hours' => [24, 1],
            'onboarding_step' => 5,
            'onboarding_completed_at' => now(),
        ]);
    }

    /**
     * Mon-Fri: 09:00-12:30 + 13:30-18:30
     * Sat: 09:00-16:00
     * Sun: closed
     */
    private function createBusinessHours(Business $business): void
    {
        $weekdaySlots = [
            ['open_time' => '09:00', 'close_time' => '12:30'],
            ['open_time' => '13:30', 'close_time' => '18:30'],
        ];

        foreach ([DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday, DayOfWeek::Friday] as $day) {
            foreach ($weekdaySlots as $slot) {
                BusinessHour::create([
                    'business_id' => $business->id,
                    'day_of_week' => $day,
                    'open_time' => $slot['open_time'],
                    'close_time' => $slot['close_time'],
                ]);
            }
        }

        BusinessHour::create([
            'business_id' => $business->id,
            'day_of_week' => DayOfWeek::Saturday,
            'open_time' => '09:00',
            'close_time' => '16:00',
        ]);
    }

    /**
     * @return array{maria: User, luca: User, sofia: User, marco: User}
     */
    private function createUsers(Business $business): array
    {
        $maria = User::factory()->create([
            'name' => 'Maria Rossi',
            'email' => 'maria@salone-bella.ch',
        ]);
        $business->users()->attach($maria, ['role' => BusinessUserRole::Admin]);

        $luca = User::factory()->create([
            'name' => 'Luca Bianchi',
            'email' => 'luca@salone-bella.ch',
        ]);
        $business->users()->attach($luca, ['role' => BusinessUserRole::Collaborator]);

        $sofia = User::factory()->create([
            'name' => 'Sofia Conti',
            'email' => 'sofia@salone-bella.ch',
        ]);
        $business->users()->attach($sofia, ['role' => BusinessUserRole::Collaborator]);

        $marco = User::factory()->create([
            'name' => 'Marco Ferretti',
            'email' => 'marco@salone-bella.ch',
        ]);
        $business->users()->attach($marco, ['role' => BusinessUserRole::Collaborator]);

        return compact('maria', 'luca', 'sofia', 'marco');
    }

    /**
     * @return array{taglioDonna: Service, taglioUomo: Service, colore: Service, piega: Service, consulenza: Service}
     */
    private function createServices(Business $business): array
    {
        $taglioDonna = Service::create([
            'business_id' => $business->id,
            'name' => 'Taglio Donna',
            'slug' => 'taglio-donna',
            'description' => 'Taglio e styling per donna',
            'duration_minutes' => 45,
            'price' => 65.00,
            'buffer_after' => 10,
            'slot_interval_minutes' => 15,
        ]);

        $taglioUomo = Service::create([
            'business_id' => $business->id,
            'name' => 'Taglio Uomo',
            'slug' => 'taglio-uomo',
            'description' => 'Taglio classico per uomo',
            'duration_minutes' => 30,
            'price' => 40.00,
            'buffer_after' => 5,
            'slot_interval_minutes' => 15,
        ]);

        $colore = Service::create([
            'business_id' => $business->id,
            'name' => 'Colore',
            'slug' => 'colore',
            'description' => 'Trattamento colore completo',
            'duration_minutes' => 90,
            'price' => 120.00,
            'buffer_before' => 5,
            'buffer_after' => 15,
            'slot_interval_minutes' => 30,
        ]);

        $piega = Service::create([
            'business_id' => $business->id,
            'name' => 'Piega',
            'slug' => 'piega',
            'description' => 'Piega e styling',
            'duration_minutes' => 30,
            'price' => 35.00,
            'slot_interval_minutes' => 15,
        ]);

        $consulenza = Service::create([
            'business_id' => $business->id,
            'name' => 'Consulenza',
            'slug' => 'consulenza',
            'description' => 'Consulenza personalizzata per il tuo look',
            'duration_minutes' => 15,
            'price' => null, // on request (D-020)
            'slot_interval_minutes' => 15,
        ]);

        return compact('taglioDonna', 'taglioUomo', 'colore', 'piega', 'consulenza');
    }

    /**
     * @param  array{maria: User, luca: User, sofia: User, marco: User}  $users
     * @param  array{taglioDonna: Service, taglioUomo: Service, colore: Service, piega: Service, consulenza: Service}  $services
     */
    private function assignServicesToCollaborators(array $users, array $services): void
    {
        // Maria: all services
        $users['maria']->services()->attach([
            $services['taglioDonna']->id,
            $services['taglioUomo']->id,
            $services['colore']->id,
            $services['piega']->id,
            $services['consulenza']->id,
        ]);

        // Luca: Taglio Donna, Taglio Uomo, Piega
        $users['luca']->services()->attach([
            $services['taglioDonna']->id,
            $services['taglioUomo']->id,
            $services['piega']->id,
        ]);

        // Sofia: Taglio Donna, Colore, Piega, Consulenza
        $users['sofia']->services()->attach([
            $services['taglioDonna']->id,
            $services['colore']->id,
            $services['piega']->id,
            $services['consulenza']->id,
        ]);

        // Marco: Taglio Uomo, Piega
        $users['marco']->services()->attach([
            $services['taglioUomo']->id,
            $services['piega']->id,
        ]);
    }

    /**
     * @param  array{maria: User, luca: User, sofia: User, marco: User}  $users
     */
    private function createAvailabilityRules(Business $business, array $users): void
    {
        $morningAfternoon = [
            ['start_time' => '09:00', 'end_time' => '12:30'],
            ['start_time' => '13:30', 'end_time' => '18:30'],
        ];

        // Maria: Mon-Fri full, Sat morning
        foreach ([DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday, DayOfWeek::Friday] as $day) {
            foreach ($morningAfternoon as $slot) {
                AvailabilityRule::create([
                    'collaborator_id' => $users['maria']->id,
                    'business_id' => $business->id,
                    'day_of_week' => $day,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                ]);
            }
        }
        AvailabilityRule::create([
            'collaborator_id' => $users['maria']->id,
            'business_id' => $business->id,
            'day_of_week' => DayOfWeek::Saturday,
            'start_time' => '09:00',
            'end_time' => '13:00',
        ]);

        // Luca: Mon-Thu full
        foreach ([DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday] as $day) {
            foreach ($morningAfternoon as $slot) {
                AvailabilityRule::create([
                    'collaborator_id' => $users['luca']->id,
                    'business_id' => $business->id,
                    'day_of_week' => $day,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                ]);
            }
        }

        // Sofia: Tue-Sat full
        foreach ([DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday, DayOfWeek::Friday, DayOfWeek::Saturday] as $day) {
            foreach ($morningAfternoon as $slot) {
                AvailabilityRule::create([
                    'collaborator_id' => $users['sofia']->id,
                    'business_id' => $business->id,
                    'day_of_week' => $day,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                ]);
            }
        }

        // Marco: Mon, Wed, Fri full + Sat full day
        foreach ([DayOfWeek::Monday, DayOfWeek::Wednesday, DayOfWeek::Friday] as $day) {
            foreach ($morningAfternoon as $slot) {
                AvailabilityRule::create([
                    'collaborator_id' => $users['marco']->id,
                    'business_id' => $business->id,
                    'day_of_week' => $day,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                ]);
            }
        }
        AvailabilityRule::create([
            'collaborator_id' => $users['marco']->id,
            'business_id' => $business->id,
            'day_of_week' => DayOfWeek::Saturday,
            'start_time' => '09:00',
            'end_time' => '16:00',
        ]);
    }

    /**
     * @param  array{maria: User, luca: User, sofia: User, marco: User}  $users
     */
    private function createAvailabilityExceptions(Business $business, array $users): void
    {
        // Business-level: Swiss National Day (Aug 1)
        AvailabilityException::create([
            'business_id' => $business->id,
            'start_date' => Carbon::parse('2026-08-01'),
            'end_date' => Carbon::parse('2026-08-01'),
            'type' => ExceptionType::Block,
            'reason' => 'Festa nazionale svizzera',
        ]);

        // Business-level: Christmas closure
        AvailabilityException::create([
            'business_id' => $business->id,
            'start_date' => Carbon::parse('2026-12-24'),
            'end_date' => Carbon::parse('2026-12-26'),
            'type' => ExceptionType::Block,
            'reason' => 'Chiusura natalizia',
        ]);

        // Collaborator: Luca sick day next Wednesday
        $nextWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        AvailabilityException::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['luca']->id,
            'start_date' => $nextWednesday,
            'end_date' => $nextWednesday,
            'type' => ExceptionType::Block,
            'reason' => 'Malattia',
        ]);

        // Collaborator: Sofia doctor appointment next Thursday 10:00-11:00
        $nextThursday = Carbon::now()->next(Carbon::THURSDAY);
        AvailabilityException::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['sofia']->id,
            'start_date' => $nextThursday,
            'end_date' => $nextThursday,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'type' => ExceptionType::Block,
            'reason' => 'Appuntamento medico',
        ]);

        // Collaborator: Marco extra Saturday availability
        $nextSaturday = Carbon::now()->next(Carbon::SATURDAY);
        AvailabilityException::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['marco']->id,
            'start_date' => $nextSaturday,
            'end_date' => $nextSaturday,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'type' => ExceptionType::Open,
            'reason' => 'Disponibilità extra',
        ]);
    }

    /**
     * @return array<int, Customer>
     */
    private function createCustomers(): array
    {
        $registeredUser1 = User::factory()->create([
            'name' => 'Thomas Keller',
            'email' => 'thomas.keller@example.com',
        ]);

        $registeredUser2 = User::factory()->create([
            'name' => 'Sara Fontana',
            'email' => 'sara.fontana@example.com',
        ]);

        $named = [
            Customer::create(['name' => 'Anna Mueller', 'email' => 'anna.mueller@example.com', 'phone' => '+41 79 100 00 01']),
            Customer::create(['name' => 'Elena Bernasconi', 'email' => 'elena.bernasconi@example.com', 'phone' => '+41 79 100 00 02']),
            Customer::create(['name' => 'Thomas Keller', 'email' => 'thomas.keller@example.com', 'phone' => '+41 79 100 00 03', 'user_id' => $registeredUser1->id]),
            Customer::create(['name' => 'Chiara Lombardi', 'email' => 'chiara.lombardi@example.com', 'phone' => '+41 79 100 00 04']),
            Customer::create(['name' => 'Peter Widmer', 'email' => 'peter.widmer@example.com', 'phone' => '+41 79 100 00 05']),
            Customer::create(['name' => 'Sara Fontana', 'email' => 'sara.fontana@example.com', 'phone' => '+41 79 100 00 06', 'user_id' => $registeredUser2->id]),
        ];

        $generated = Customer::factory()->count(100)->create()->all();

        return array_merge($named, $generated);
    }

    /**
     * @param  array{maria: User, luca: User, sofia: User, marco: User}  $users
     * @param  array{taglioDonna: Service, taglioUomo: Service, colore: Service, piega: Service, consulenza: Service}  $services
     * @param  array<int, Customer>  $customers
     */
    private function createBookings(Business $business, array $users, array $services, array $customers): void
    {
        $today = Carbon::today('Europe/Zurich');
        $nextMonday = Carbon::now()->next(Carbon::MONDAY);

        // --- TODAY's bookings (visible on dashboard home) ---
        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['luca']->id,
            'service_id' => $services['taglioUomo']->id,
            'customer_id' => $customers[1]->id,
            'starts_at' => $today->copy()->setTime(10, 0)->utc(),
            'ends_at' => $today->copy()->setTime(10, 30)->utc(),
            'status' => BookingStatus::Confirmed,
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['sofia']->id,
            'service_id' => $services['colore']->id,
            'customer_id' => $customers[2]->id,
            'starts_at' => $today->copy()->setTime(14, 0)->utc(),
            'ends_at' => $today->copy()->setTime(15, 30)->utc(),
            'status' => BookingStatus::Confirmed,
            'internal_notes' => 'VIP customer — always offer coffee',
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['marco']->id,
            'service_id' => $services['piega']->id,
            'customer_id' => $customers[3]->id,
            'starts_at' => $today->copy()->setTime(15, 30)->utc(),
            'ends_at' => $today->copy()->setTime(16, 0)->utc(),
            'status' => BookingStatus::Pending,
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        // --- Next Monday bookings (used by SlotGenerationIntegrationTest) ---
        // Maria: Taglio Donna at 09:00 UTC = 11:00 CEST
        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['maria']->id,
            'service_id' => $services['taglioDonna']->id,
            'customer_id' => $customers[0]->id,
            'starts_at' => $nextMonday->copy()->setTime(9, 0),
            'ends_at' => $nextMonday->copy()->setTime(9, 45),
            'status' => BookingStatus::Confirmed,
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        // Maria: Piega at 11:00 UTC = 13:00 CEST
        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['maria']->id,
            'service_id' => $services['piega']->id,
            'customer_id' => $customers[1]->id,
            'starts_at' => $nextMonday->copy()->setTime(11, 0),
            'ends_at' => $nextMonday->copy()->setTime(11, 30),
            'status' => BookingStatus::Confirmed,
            'source' => BookingSource::Manual,
            'notes' => 'Prenotazione telefonica',
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        // --- More future bookings ---
        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['luca']->id,
            'service_id' => $services['taglioUomo']->id,
            'customer_id' => $customers[1]->id,
            'starts_at' => $nextMonday->copy()->setTime(10, 0),
            'ends_at' => $nextMonday->copy()->setTime(10, 30),
            'status' => BookingStatus::Confirmed,
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['sofia']->id,
            'service_id' => $services['colore']->id,
            'customer_id' => $customers[2]->id,
            'starts_at' => $nextMonday->copy()->addDay()->setTime(14, 0),
            'ends_at' => $nextMonday->copy()->addDay()->setTime(15, 30),
            'status' => BookingStatus::Confirmed,
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['marco']->id,
            'service_id' => $services['piega']->id,
            'customer_id' => $customers[3]->id,
            'starts_at' => $nextMonday->copy()->addDays(2)->setTime(9, 30),
            'ends_at' => $nextMonday->copy()->addDays(2)->setTime(10, 0),
            'status' => BookingStatus::Pending,
            'notes' => 'Wants to discuss a full colour change',
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['maria']->id,
            'service_id' => $services['consulenza']->id,
            'customer_id' => $customers[4]->id,
            'starts_at' => $nextMonday->copy()->addDays(3)->setTime(11, 0),
            'ends_at' => $nextMonday->copy()->addDays(3)->setTime(11, 15),
            'status' => BookingStatus::Pending,
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        // --- Past bookings ---
        $lastMonday = Carbon::now()->previous(Carbon::MONDAY);

        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['luca']->id,
            'service_id' => $services['taglioUomo']->id,
            'customer_id' => $customers[0]->id,
            'starts_at' => $lastMonday->copy()->setTime(14, 0),
            'ends_at' => $lastMonday->copy()->setTime(14, 30),
            'status' => BookingStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['sofia']->id,
            'service_id' => $services['piega']->id,
            'customer_id' => $customers[5]->id,
            'starts_at' => $lastMonday->copy()->addDay()->setTime(10, 0),
            'ends_at' => $lastMonday->copy()->addDay()->setTime(10, 30),
            'status' => BookingStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['maria']->id,
            'service_id' => $services['taglioDonna']->id,
            'customer_id' => $customers[3]->id,
            'starts_at' => $lastMonday->copy()->addDays(4)->setTime(15, 0),
            'ends_at' => $lastMonday->copy()->addDays(4)->setTime(15, 45),
            'status' => BookingStatus::Cancelled,
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $users['marco']->id,
            'service_id' => $services['taglioUomo']->id,
            'customer_id' => $customers[4]->id,
            'starts_at' => $lastMonday->copy()->addDays(2)->setTime(9, 0),
            'ends_at' => $lastMonday->copy()->addDays(2)->setTime(9, 30),
            'status' => BookingStatus::NoShow,
            'internal_notes' => 'Second no-show — consider requiring deposit',
            'cancellation_token' => Str::uuid()->toString(),
        ]);

        // --- Bookings for factory-generated customers (for pagination) ---
        $collaborators = [$users['maria'], $users['luca'], $users['sofia'], $users['marco']];
        $serviceList = [$services['taglioDonna'], $services['taglioUomo'], $services['colore'], $services['piega']];
        $statuses = [BookingStatus::Completed, BookingStatus::Confirmed, BookingStatus::Pending];

        foreach (array_slice($customers, 6) as $i => $customer) {
            $collab = $collaborators[$i % count($collaborators)];
            $service = $serviceList[$i % count($serviceList)];
            $day = $lastMonday->copy()->subDays($i % 30);
            $hour = 9 + ($i % 8);

            Booking::create([
                'business_id' => $business->id,
                'collaborator_id' => $collab->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'starts_at' => $day->copy()->setTime($hour, 0)->utc(),
                'ends_at' => $day->copy()->setTime($hour, $service->duration_minutes)->utc(),
                'status' => $statuses[$i % count($statuses)],
                'cancellation_token' => Str::uuid()->toString(),
            ]);
        }
    }
}
