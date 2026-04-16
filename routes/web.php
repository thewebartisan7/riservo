<?php

use App\Http\Controllers\Auth\CustomerRegisterController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Booking\BookingManagementController;
use App\Http\Controllers\Booking\PublicBookingController;
use App\Http\Controllers\Customer\BookingController as CustomerBookingController;
use App\Http\Controllers\Dashboard\BookingController as DashboardBookingController;
use App\Http\Controllers\Dashboard\CalendarController;
use App\Http\Controllers\Dashboard\CustomerController as DashboardCustomerController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\Settings\BookingSettingsController;
use App\Http\Controllers\Dashboard\Settings\BusinessExceptionController;
use App\Http\Controllers\Dashboard\Settings\EmbedController;
use App\Http\Controllers\Dashboard\Settings\ProfileController as SettingsProfileController;
use App\Http\Controllers\Dashboard\Settings\ProviderController as SettingsProviderController;
use App\Http\Controllers\Dashboard\Settings\ServiceController as SettingsServiceController;
use App\Http\Controllers\Dashboard\Settings\StaffController as SettingsStaffController;
use App\Http\Controllers\Dashboard\Settings\WorkingHoursController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public
Route::get('/', fn () => Inertia::render('welcome'))->name('home');

// Guest-only routes (redirect if authenticated)
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/magic-link', [MagicLinkController::class, 'create'])->name('magic-link.create');
    Route::post('/magic-link', [MagicLinkController::class, 'store'])->name('magic-link.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');

    Route::get('/invite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invite/{token}', [InvitationController::class, 'accept'])->name('invitation.accept');

    Route::get('/customer/register', [CustomerRegisterController::class, 'create'])->name('customer.register');
    Route::post('/customer/register', [CustomerRegisterController::class, 'store']);
});

// Magic link verify (works whether logged in or not, must be signed)
Route::get('/magic-link/verify/{user}', [MagicLinkController::class, 'verify'])
    ->name('magic-link.verify')
    ->middleware('signed');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Email verification
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Onboarding wizard (auth + verified + admin only, no onboarded middleware)
    Route::middleware(['verified', 'role:admin'])->group(function () {
        Route::get('/onboarding/step/{step}', [OnboardingController::class, 'show'])
            ->name('onboarding.show')
            ->where('step', '[1-5]');
        Route::post('/onboarding/step/{step}', [OnboardingController::class, 'store'])
            ->name('onboarding.store')
            ->where('step', '[1-5]');
        Route::post('/onboarding/slug-check', [OnboardingController::class, 'checkSlug'])
            ->name('onboarding.slug-check');
        Route::post('/onboarding/logo-upload', [OnboardingController::class, 'uploadLogo'])
            ->name('onboarding.logo-upload');
    });

    // Business dashboard (auth + verified + business role + onboarded)
    Route::middleware(['verified', 'role:admin,staff', 'onboarded'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/welcome', [WelcomeController::class, 'show'])->name('dashboard.welcome');

        // Bookings
        Route::get('/dashboard/bookings', [DashboardBookingController::class, 'index'])->name('dashboard.bookings');
        Route::post('/dashboard/bookings', [DashboardBookingController::class, 'store'])->name('dashboard.bookings.store');
        Route::patch('/dashboard/bookings/{booking}/status', [DashboardBookingController::class, 'updateStatus'])->name('dashboard.bookings.update-status');
        Route::patch('/dashboard/bookings/{booking}/notes', [DashboardBookingController::class, 'updateNotes'])->name('dashboard.bookings.update-notes');

        // Calendar
        Route::get('/dashboard/calendar', [CalendarController::class, 'index'])->name('dashboard.calendar');

        // Dashboard API (JSON)
        Route::get('/dashboard/api/available-dates', [DashboardBookingController::class, 'availableDates'])->name('dashboard.api.available-dates');
        Route::get('/dashboard/api/slots', [DashboardBookingController::class, 'slots'])->name('dashboard.api.slots');

        // Customers (admin only)
        Route::middleware('role:admin')->group(function () {
            Route::get('/dashboard/customers', [DashboardCustomerController::class, 'index'])->name('dashboard.customers');
            Route::get('/dashboard/customers/{customer}', [DashboardCustomerController::class, 'show'])->name('dashboard.customers.show');
            Route::get('/dashboard/api/customers/search', [DashboardCustomerController::class, 'search'])->name('dashboard.api.customers.search');
        });

        // Settings (admin only)
        Route::middleware('role:admin')->prefix('dashboard/settings')->group(function () {
            // Profile
            Route::get('/profile', [SettingsProfileController::class, 'edit'])->name('settings.profile');
            Route::put('/profile', [SettingsProfileController::class, 'update'])->name('settings.profile.update');
            Route::post('/profile/logo', [SettingsProfileController::class, 'uploadLogo'])->name('settings.profile.upload-logo');
            Route::post('/profile/slug-check', [SettingsProfileController::class, 'checkSlug'])->name('settings.profile.slug-check');

            // Booking settings
            Route::get('/booking', [BookingSettingsController::class, 'edit'])->name('settings.booking');
            Route::put('/booking', [BookingSettingsController::class, 'update'])->name('settings.booking.update');

            // Working hours
            Route::get('/hours', [WorkingHoursController::class, 'edit'])->name('settings.hours');
            Route::put('/hours', [WorkingHoursController::class, 'update'])->name('settings.hours.update');

            // Business exceptions
            Route::get('/exceptions', [BusinessExceptionController::class, 'index'])->name('settings.exceptions');
            Route::post('/exceptions', [BusinessExceptionController::class, 'store'])->name('settings.exceptions.store');
            Route::put('/exceptions/{exception}', [BusinessExceptionController::class, 'update'])->name('settings.exceptions.update');
            Route::delete('/exceptions/{exception}', [BusinessExceptionController::class, 'destroy'])->name('settings.exceptions.destroy');

            // Services
            Route::get('/services', [SettingsServiceController::class, 'index'])->name('settings.services');
            Route::get('/services/create', [SettingsServiceController::class, 'create'])->name('settings.services.create');
            Route::post('/services', [SettingsServiceController::class, 'store'])->name('settings.services.store');
            Route::get('/services/{service}', [SettingsServiceController::class, 'edit'])->name('settings.services.edit');
            Route::put('/services/{service}', [SettingsServiceController::class, 'update'])->name('settings.services.update');

            // Staff (team membership + invitations)
            Route::get('/staff', [SettingsStaffController::class, 'index'])->name('settings.staff');
            Route::post('/staff/invite', [SettingsStaffController::class, 'invite'])->name('settings.staff.invite');
            Route::post('/staff/invitations/{invitation}/resend', [SettingsStaffController::class, 'resendInvitation'])->name('settings.staff.resend-invitation');
            Route::delete('/staff/invitations/{invitation}', [SettingsStaffController::class, 'cancelInvitation'])->name('settings.staff.cancel-invitation');
            Route::get('/staff/{user}', [SettingsStaffController::class, 'show'])->name('settings.staff.show');
            Route::post('/staff/{user}/avatar', [SettingsStaffController::class, 'uploadAvatar'])->name('settings.staff.upload-avatar');

            // Providers (bookability: schedule, services, exceptions, activation)
            Route::post('/providers/{provider}/toggle', [SettingsProviderController::class, 'toggle'])
                ->withTrashed()
                ->name('settings.providers.toggle');
            Route::put('/providers/{provider}/schedule', [SettingsProviderController::class, 'updateSchedule'])->name('settings.providers.update-schedule');
            Route::put('/providers/{provider}/services', [SettingsProviderController::class, 'syncServices'])->name('settings.providers.sync-services');
            Route::post('/providers/{provider}/exceptions', [SettingsProviderController::class, 'storeException'])->name('settings.providers.store-exception');
            Route::put('/providers/{provider}/exceptions/{exception}', [SettingsProviderController::class, 'updateException'])->name('settings.providers.update-exception');
            Route::delete('/providers/{provider}/exceptions/{exception}', [SettingsProviderController::class, 'destroyException'])->name('settings.providers.destroy-exception');

            // Embed & Share
            Route::get('/embed', [EmbedController::class, 'edit'])->name('settings.embed');
        });
    });

    // Customer area (auth + customer role)
    Route::middleware('role:customer')->group(function () {
        Route::get('/my-bookings', [CustomerBookingController::class, 'index'])->name('customer.bookings');
        Route::post('/my-bookings/{booking}/cancel', [CustomerBookingController::class, 'cancel'])->name('customer.bookings.cancel');
    });
});

// Guest booking management (no auth, via cancellation token)
Route::get('/bookings/{token}', [BookingManagementController::class, 'show'])->name('bookings.show');
Route::post('/bookings/{token}/cancel', [BookingManagementController::class, 'cancel'])->name('bookings.cancel');

// Public booking API (JSON) — rate limited
Route::prefix('booking/{slug}')->middleware('throttle:booking-api')->group(function () {
    Route::get('/providers', [PublicBookingController::class, 'providers'])->name('booking.providers');
    Route::get('/available-dates', [PublicBookingController::class, 'availableDates'])->name('booking.available-dates');
    Route::get('/slots', [PublicBookingController::class, 'slots'])->name('booking.slots');
});

Route::post('/booking/{slug}/book', [PublicBookingController::class, 'store'])
    ->middleware('throttle:booking-create')
    ->name('booking.store');

// Public booking page — catch-all (MUST BE LAST — see D-013, D-043)
Route::get('/{slug}/{serviceSlug?}', [PublicBookingController::class, 'show'])->name('booking.show');
