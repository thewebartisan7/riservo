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
    Route::middleware(['verified', 'role:admin,collaborator', 'onboarded'])->group(function () {
        Route::get('/dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
        Route::get('/dashboard/welcome', [WelcomeController::class, 'show'])->name('dashboard.welcome');
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
    Route::get('/collaborators', [PublicBookingController::class, 'collaborators'])->name('booking.collaborators');
    Route::get('/available-dates', [PublicBookingController::class, 'availableDates'])->name('booking.available-dates');
    Route::get('/slots', [PublicBookingController::class, 'slots'])->name('booking.slots');
});

Route::post('/booking/{slug}/book', [PublicBookingController::class, 'store'])
    ->middleware('throttle:booking-create')
    ->name('booking.store');

// Public booking page — catch-all (MUST BE LAST — see D-013, D-043)
Route::get('/{slug}/{serviceSlug?}', [PublicBookingController::class, 'show'])->name('booking.show');
