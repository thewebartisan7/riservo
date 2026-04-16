# Browser Test Route Coverage

This ledger tracks which E2E browser tests exercise each named route in
`routes/web.php`. Every E2E session appends entries to the "Covered by"
column when it lands. The orchestrator verifies that every row has at least
one entry before closing the E2E roadmap.

Conventions for the "Covered by" column:

- `E2E-<n>: <relative-path>::'<test description>'`
- Multiple tests separated by `; `
- `N/A` if the route is purposely out of scope and why

## Public & auth (guest)

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `home` | `GET /` | guest | E2E-0: `tests/Browser/SmokeTest.php::'it loads the landing page without JS errors'` |
| `register` | `GET /register` | guest | E2E-1: `tests/Browser/Auth/RegistrationTest.php` |
| `login` | `GET /login` | guest | E2E-1: `tests/Browser/Auth/LoginTest.php` |
| `logout` | `POST /logout` | auth | E2E-1: `tests/Browser/Auth/LogoutTest.php` |
| `magic-link.create` | `GET /magic-link` | guest | E2E-1: `tests/Browser/Auth/MagicLinkRequestTest.php` |
| `magic-link.store` | `POST /magic-link` | guest | E2E-1: `tests/Browser/Auth/MagicLinkRequestTest.php` |
| `magic-link.verify` | `GET /magic-link/verify/{user}` | signed | E2E-1: `tests/Browser/Auth/MagicLinkVerifyTest.php`; E2E-6: `tests/Browser/CrossCutting/AuthorizationEdgeCasesTest.php` |
| `password.request` | `GET /forgot-password` | guest | E2E-1: `tests/Browser/Auth/PasswordResetTest.php` |
| `password.email` | `POST /forgot-password` | guest | E2E-1: `tests/Browser/Auth/PasswordResetTest.php` |
| `password.reset` | `GET /reset-password/{token}` | guest | E2E-1: `tests/Browser/Auth/PasswordResetTest.php` |
| `password.update` | `POST /reset-password` | guest | E2E-1: `tests/Browser/Auth/PasswordResetTest.php` |
| `invitation.show` | `GET /invite/{token}` | guest | E2E-1: `tests/Browser/Auth/InviteAcceptanceTest.php` |
| `invitation.accept` | `POST /invite/{token}` | guest | E2E-1: `tests/Browser/Auth/InviteAcceptanceTest.php` |
| `customer.register` | `GET /customer/register` | guest | E2E-1: `tests/Browser/Auth/CustomerRegistrationTest.php` |

## Email verification

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `verification.notice` | `GET /email/verify` | auth | E2E-1: `tests/Browser/Auth/EmailVerificationTest.php` |
| `verification.verify` | `GET /email/verify/{id}/{hash}` | auth, signed | E2E-1: `tests/Browser/Auth/EmailVerificationTest.php` |
| `verification.send` | `POST /email/verification-notification` | auth | E2E-1: `tests/Browser/Auth/EmailVerificationTest.php` |

## Onboarding (admin)

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `onboarding.show` | `GET /onboarding/step/{step}` | admin | E2E-2: `tests/Browser/Onboarding/WizardHappyPathTest.php::'it walks a freshly-registered admin from step 1 through launch'`; `tests/Browser/Onboarding/WizardResumeTest.php::'it lands a returning admin on their current step when they visit /dashboard'`; `tests/Browser/Onboarding/WizardPostCompletionTest.php::'it redirects a completed admin away from /onboarding/step/1 to /dashboard'` |
| `onboarding.store` | `POST /onboarding/step/{step}` | admin | E2E-2: `tests/Browser/Onboarding/WizardHappyPathTest.php::'it walks a freshly-registered admin from step 1 through launch'`; `tests/Browser/Onboarding/WizardAdminAsProviderTest.php::'it creates a Provider row when the admin checks "I take bookings myself" in step 3'`; `tests/Browser/Onboarding/WizardStaffInviteTest.php::'it creates a BusinessInvitation row when the admin submits a staff email on step 4'`; `tests/Browser/Onboarding/WizardLaunchGateTest.php::'it blocks launch when an active service has zero providers and lands back on step 3'`; `tests/Browser/Onboarding/WizardValidationTest.php::'it keeps the admin on step 1 and shows an error when the business name is empty'` |
| `onboarding.slug-check` | `POST /onboarding/slug-check` | admin | E2E-2: `tests/Browser/Onboarding/WizardSlugCheckTest.php::'it shows an "available" indicator for a free slug'`; `tests/Browser/Onboarding/WizardSlugCheckTest.php::'it shows an "unavailable" indicator for a slug already taken by another business'`; `tests/Browser/Onboarding/WizardSlugCheckTest.php::'it shows "unavailable" when typing a reserved system slug'`; `tests/Browser/Onboarding/WizardSlugCheckTest.php::'it treats the business\'s own current slug as available (self-match)'` |
| `onboarding.logo-upload` | `POST /onboarding/logo-upload` | admin | E2E-2: `tests/Browser/Onboarding/WizardLogoUploadTest.php::'it attaches a valid image and persists the path on the business'`; `tests/Browser/Onboarding/WizardLogoUploadTest.php::'it rejects a non-image attachment (PDF) with a 422 from the server'` |
| `onboarding.enable-owner-as-provider` | `POST /onboarding/enable-owner-as-provider` | admin | E2E-2: `tests/Browser/Onboarding/WizardLaunchGateTest.php::'it lets the admin recover with one click via "Be your own first provider" and then launch'`; `tests/Browser/Onboarding/WizardAdminAsProviderTest.php::'it lists the admin-as-provider as bookable on the public /{slug} page after launch'` |

## Dashboard (admin + staff, onboarded)

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `dashboard` | `GET /dashboard` | admin, staff | E2E-4: `tests/Browser/Dashboard/DashboardHomeTest.php::'it shows four stat cards and the heading greeting'`; `'it shows the quiet-day empty state when there are no bookings today'`; `'it navigates to the bookings list when clicking the All bookings link'`; `'it renders the dashboard home for a staff member without errors'` |
| `dashboard.welcome` | `GET /dashboard/welcome` | admin, staff | E2E-2: `tests/Browser/Onboarding/DashboardWelcomeTest.php::'it renders the business name, public URL, and dashboard CTA for a just-launched admin'`; `tests/Browser/Onboarding/DashboardWelcomeTest.php::'it navigates to /dashboard when the admin clicks "Open your dashboard"'`; `tests/Browser/Onboarding/DashboardWelcomeTest.php::'it shows next-step CTAs linking to settings pages'`; `tests/Browser/Onboarding/WizardHappyPathTest.php::'it walks a freshly-registered admin from step 1 through launch'` |
| `dashboard.bookings` | `GET /dashboard/bookings` | admin, staff | E2E-4: `tests/Browser/Dashboard/BookingsListTest.php::'admin sees bookings across every provider'`; `'staff sees only bookings tied to their own provider row'`; `'filters by status via the URL query string'`; `'filters by a date range via the URL query string'`; `'filters by provider when an admin selects a single provider'`; `'paginates to the second page when there are more than 20 bookings'`; `'shows an empty state when there are no bookings'`; `tests/Browser/Dashboard/BookingDetailPanelTest.php::'it opens the detail panel with customer contact info when clicking a row'`; `'it appends (deactivated) to a soft-deleted provider name in the panel (D-067)'`; `'it renders google_calendar-sourced bookings with the Google source badge'` |
| `dashboard.bookings.store` | `POST /dashboard/bookings` | admin, staff | E2E-4: `tests/Browser/Dashboard/ManualBookingTest.php::'it creates a manual booking for a new customer via the HTTP endpoint'`; `'it reuses an existing customer when the email matches the directory'`; `'it shows a slot-unavailable error when manually booking an occupied slot'`; `'it opens the manual-booking dialog from the bookings page'` |
| `dashboard.bookings.update-status` | `PATCH /dashboard/bookings/{booking}/status` | admin, staff | E2E-4: `tests/Browser/Dashboard/StatusTransitionsTest.php::'it confirms a pending booking from the detail panel'`; `'it cancels a confirmed booking from the detail panel'`; `'it marks a confirmed booking as completed from the detail panel'`; `'it marks a confirmed booking as no-show from the detail panel'`; `'it does not show status action buttons for a cancelled booking'` |
| `dashboard.bookings.update-notes` | `PATCH /dashboard/bookings/{booking}/notes` | admin, staff | E2E-4: `tests/Browser/Dashboard/InternalNotesTest.php::'it saves an internal note via the detail panel'`; `'it clears an existing internal note'` |
| `dashboard.calendar` | `GET /dashboard/calendar` | admin, staff | E2E-4: `tests/Browser/Dashboard/CalendarViewsTest.php::'it renders the week view by default and shows seeded bookings'`; `'it renders the day view when view=day is requested'`; `'it renders the month view when view=month is requested'`; `'it navigates to a different week via the date query parameter'`; `'it renders the calendar without a New booking button for staff'`; `'it falls back to week view when the view parameter is invalid'`; `tests/Browser/Dashboard/CalendarProviderFilterTest.php::'it shows the provider filter bar for an admin with multiple providers'`; `'it hides the provider filter when the admin has a single provider'`; `'it renders both providers in the filter toolbar'`; `tests/Browser/Dashboard/CalendarStaffScopeTest.php::'it limits the calendar to the staff member\\'s own bookings when they are also a provider'`; `'it does not render the admin provider filter for staff'`; `'it shows an empty calendar to a staff member who is not linked to any provider'` |
| `dashboard.api.available-dates` | `GET /dashboard/api/available-dates` | admin, staff | E2E-4: exercised indirectly by `tests/Browser/Dashboard/ManualBookingTest.php` via the in-dialog slot picker path; direct HTTP coverage lives in `tests/Feature/Dashboard/ManualBookingTest.php`. |
| `dashboard.api.slots` | `GET /dashboard/api/slots` | admin, staff | E2E-4: exercised indirectly by `tests/Browser/Dashboard/ManualBookingTest.php` via the in-dialog slot picker path; direct HTTP coverage lives in `tests/Feature/Dashboard/ManualBookingTest.php`. |
| `dashboard.customers` | `GET /dashboard/customers` | admin | E2E-4: `tests/Browser/Dashboard/CustomerDirectoryTest.php::'it lists only customers who have bookings with this business'`; `'it filters customers via the search query parameter'`; `'it shows the empty state when no customers match the search'`; `'it navigates to the customer detail page when a row is clicked'`; `'it forbids staff members from accessing the customer directory'` |
| `dashboard.customers.show` | `GET /dashboard/customers/{customer}` | admin | E2E-4: `tests/Browser/Dashboard/CustomerDetailTest.php::'it shows contact info, stats, and booking history for a scoped customer'`; `'it returns 404 when the customer has no bookings with this business'`; `'it shows the empty booking-history placeholder when a customer has no bookings (edge case)'`; `'it links back to the customer list from the detail page'` |
| `dashboard.api.customers.search` | `GET /dashboard/api/customers/search` | admin | E2E-4: `tests/Browser/Dashboard/CustomerDirectoryTest.php::'it exposes the customer search API to admins'` |

## Settings — profile

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.profile` | `GET /dashboard/settings/profile` | admin | E2E-5: `tests/Browser/Settings/BusinessProfileTest.php::'it renders the profile settings page with business data'` |
| `settings.profile.update` | `PUT /dashboard/settings/profile` | admin | E2E-5: `tests/Browser/Settings/BusinessProfileTest.php::'it persists profile edits and reflects them on reload'`; `tests/Browser/Settings/BusinessProfileTest.php::'it physically deletes the old logo file when the logo is cleared (D-076)'`; `tests/Browser/Settings/BusinessProfileTest.php::'it changes the slug and makes the old public URL 404 while the new one loads'` |
| `settings.profile.upload-logo` | `POST /dashboard/settings/profile/logo` | admin | N/A — endpoint exercised indirectly via D-076 clear path; upload path covered by Feature test `tests/Feature/Settings/ProfileTest.php` |
| `settings.profile.slug-check` | `POST /dashboard/settings/profile/slug-check` | admin | E2E-5: `tests/Browser/Settings/BusinessProfileTest.php::'it reports slug availability for own, free, taken, and reserved slugs'` |

## Settings — booking

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.booking` | `GET /dashboard/settings/booking` | admin | E2E-5: `tests/Browser/Settings/BookingSettingsTest.php::'it renders the booking settings page with the current values'` |
| `settings.booking.update` | `PUT /dashboard/settings/booking` | admin | E2E-5: `tests/Browser/Settings/BookingSettingsTest.php::'it updates confirmation_mode and persists the change'`; `tests/Browser/Settings/BookingSettingsTest.php::'it turns off allow_provider_choice so the public page skips the provider step'`; `tests/Browser/Settings/BookingSettingsTest.php::'it updates cancellation_window_hours and persists the change'`; `tests/Browser/Settings/BookingSettingsTest.php::'it updates reminder_hours to a narrower set'`; `tests/Browser/Settings/BookingSettingsTest.php::'it updates payment_mode to online'` |

## Settings — hours

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.hours` | `GET /dashboard/settings/hours` | admin | E2E-5: `tests/Browser/Settings/BusinessHoursTest.php::'it renders the working hours page with seven day rows'` |
| `settings.hours.update` | `PUT /dashboard/settings/hours` | admin | E2E-5: `tests/Browser/Settings/BusinessHoursTest.php::'it disables a day by persisting a schedule without its row'`; `tests/Browser/Settings/BusinessHoursTest.php::'it accepts a second time window on the same day (morning + afternoon)'`; `tests/Browser/Settings/BusinessHoursTest.php::'it rejects a window whose close time is before the open time'` |

## Settings — business exceptions

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.exceptions` | `GET /dashboard/settings/exceptions` | admin | E2E-5: `tests/Browser/Settings/BusinessExceptionsTest.php::'it renders an empty business exceptions list with the empty-state copy'`; `tests/Browser/Settings/BusinessExceptionsTest.php::'it lists existing business-level exceptions (not provider-level ones)'` |
| `settings.exceptions.store` | `POST /dashboard/settings/exceptions` | admin | E2E-5: `tests/Browser/Settings/BusinessExceptionsTest.php::'it creates a full-day closure exception via the store endpoint'`; `tests/Browser/Settings/BusinessExceptionsTest.php::'it creates a partial-day block exception (10:00 – 11:00)'`; `tests/Browser/Settings/BusinessExceptionsTest.php::'it creates an open exception that adds extra availability'` |
| `settings.exceptions.update` | `PUT /dashboard/settings/exceptions/{exception}` | admin | E2E-5: `tests/Browser/Settings/BusinessExceptionsTest.php::'it edits an exception and persists the updated values'` |
| `settings.exceptions.destroy` | `DELETE /dashboard/settings/exceptions/{exception}` | admin | E2E-5: `tests/Browser/Settings/BusinessExceptionsTest.php::'it deletes an exception via the destroy endpoint'` |

## Settings — services

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.services` | `GET /dashboard/settings/services` | admin | E2E-5: `tests/Browser/Settings/ServicesTest.php::'it renders the services list with the seeded service'` |
| `settings.services.create` | `GET /dashboard/settings/services/create` | admin | E2E-5: `tests/Browser/Settings/ServicesTest.php::'it renders the new-service page with the providers picker'` |
| `settings.services.store` | `POST /dashboard/settings/services` | admin | E2E-5: `tests/Browser/Settings/ServicesTest.php::'it creates a new service and it appears in the services list'`; `tests/Browser/Settings/ServicesTest.php::'it rejects a service with duration_minutes=0'` |
| `settings.services.edit` | `GET /dashboard/settings/services/{service}` | admin | E2E-5: `tests/Browser/Settings/ServicesTest.php::'it renders the edit page and updates a service'` (GET loads the edit page via Inertia redirect target) |
| `settings.services.update` | `PUT /dashboard/settings/services/{service}` | admin | E2E-5: `tests/Browser/Settings/ServicesTest.php::'it renders the edit page and updates a service'`; `tests/Browser/Settings/ServicesTest.php::'it deactivates a service by saving is_active=0 and hides it from the public page'`; `tests/Browser/Settings/ServicesTest.php::'it syncs providers on a service via update (pivot rows reflect selection)'` |

## Settings — staff & invitations

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.staff` | `GET /dashboard/settings/staff` | admin | E2E-5: `tests/Browser/Settings/StaffTest.php::'it renders the staff index with the admin and any existing staff'` |
| `settings.staff.invite` | `POST /dashboard/settings/staff/invite` | admin | E2E-5: `tests/Browser/Settings/StaffTest.php::'it invites a staff member by email and records a pending invitation'`; `tests/Browser/Settings/StaffTest.php::'it stores selected service_ids on the invitation (service pre-assignment, D-041)'`; `tests/Browser/Settings/StaffTest.php::'it rejects a duplicate invite to an email already invited'` |
| `settings.staff.resend-invitation` | `POST /dashboard/settings/staff/invitations/{invitation}/resend` | admin | E2E-5: `tests/Browser/Settings/StaffTest.php::'it resends an invitation which rotates the token (old link invalidated)'` |
| `settings.staff.cancel-invitation` | `DELETE /dashboard/settings/staff/invitations/{invitation}` | admin | E2E-5: `tests/Browser/Settings/StaffTest.php::'it cancels a pending invitation (deletes the row)'` |
| `settings.staff.show` | `GET /dashboard/settings/staff/{user}` | admin | E2E-5: `tests/Browser/Settings/StaffTest.php::'it renders the staff detail page (settings.staff.show)'` |
| `settings.staff.upload-avatar` | `POST /dashboard/settings/staff/{user}/avatar` | admin | E2E-5: `tests/Browser/Settings/StaffTest.php::'it uploads a staff avatar via JSON and stores the path on the user'`; `tests/Browser/Settings/StaffTest.php::'it rejects avatar upload for a user outside the business (403)'` |

## Settings — providers

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.providers.toggle` | `POST /dashboard/settings/providers/{provider}/toggle` | admin | E2E-5: `tests/Browser/Settings/ProvidersTest.php::'it toggles a provider off (soft-deletes) via the toggle endpoint'`; `tests/Browser/Settings/ProvidersTest.php::'it toggles a soft-deleted provider back on (restore)'`; `tests/Browser/Settings/ProvidersTest.php::'it forbids toggling a provider of a different business (tenant scope, D-063)'` |
| `settings.providers.update-schedule` | `PUT /dashboard/settings/providers/{provider}/schedule` | admin | E2E-5: `tests/Browser/Settings/ProvidersTest.php::'it updates a provider weekly schedule (AvailabilityRule rows reflect the change)'` |
| `settings.providers.sync-services` | `PUT /dashboard/settings/providers/{provider}/services` | admin | E2E-5: `tests/Browser/Settings/ProvidersTest.php::'it syncs services for a provider (pivot updates)'` |
| `settings.providers.store-exception` | `POST /dashboard/settings/providers/{provider}/exceptions` | admin | E2E-5: `tests/Browser/Settings/ProvidersTest.php::'it creates a provider-level exception via store-exception'` |
| `settings.providers.update-exception` | `PUT /dashboard/settings/providers/{provider}/exceptions/{exception}` | admin | E2E-5: `tests/Browser/Settings/ProvidersTest.php::'it updates a provider-level exception'` |
| `settings.providers.destroy-exception` | `DELETE /dashboard/settings/providers/{provider}/exceptions/{exception}` | admin | E2E-5: `tests/Browser/Settings/ProvidersTest.php::'it deletes a provider-level exception'` |

## Settings — account (admin-as-provider, D-062)

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.account` | `GET /dashboard/settings/account` | admin | E2E-5: `tests/Browser/Settings/AccountTest.php::'it renders the account settings page for an admin'`; `tests/Browser/Settings/AccountTest.php::'it admin who has not opted in sees the \"Bookable provider\" toggle (off) with no schedule'` |
| `settings.account.toggle-provider` | `POST /dashboard/settings/account/toggle-provider` | admin | E2E-5: `tests/Browser/Settings/AccountTest.php::'it toggles admin on as provider via toggle-provider and attaches to all active services (D-062)'`; `tests/Browser/Settings/AccountTest.php::'it toggles provider off and soft-deletes the Provider row (historical bookings remain, D-067)'` |
| `settings.account.update-schedule` | `PUT /dashboard/settings/account/schedule` | admin | E2E-5: `tests/Browser/Settings/AccountTest.php::'it updates own weekly schedule via update-schedule'` |
| `settings.account.store-exception` | `POST /dashboard/settings/account/exceptions` | admin | E2E-5: `tests/Browser/Settings/AccountTest.php::'it adds an own exception via store-exception'` |
| `settings.account.update-exception` | `PUT /dashboard/settings/account/exceptions/{exception}` | admin | E2E-5: `tests/Browser/Settings/AccountTest.php::'it updates an own exception'` |
| `settings.account.destroy-exception` | `DELETE /dashboard/settings/account/exceptions/{exception}` | admin | E2E-5: `tests/Browser/Settings/AccountTest.php::'it deletes an own exception'` |
| `settings.account.update-services` | `PUT /dashboard/settings/account/services` | admin | E2E-5: `tests/Browser/Settings/AccountTest.php::'it syncs own services via update-services'` |

## Settings — embed & share

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.embed` | `GET /dashboard/settings/embed` | admin | E2E-5: `tests/Browser/Settings/EmbedShareTest.php::'it renders the embed & share page with the iframe and popup snippets'`; `tests/Browser/Settings/EmbedShareTest.php::'it renders the direct link for the current slug'`; `tests/Browser/Settings/EmbedShareTest.php::'it renders a per-service preview selector when the business has services'`; `tests/Browser/Settings/EmbedShareTest.php::'it renders the live preview iframe'` |

## Customer area

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `customer.bookings` | `GET /my-bookings` | customer | E2E-3: `tests/Browser/Booking/MyBookingsTest.php`; `tests/Browser/Booking/RegisteredCustomerFlowTest.php` |
| `customer.bookings.cancel` | `POST /my-bookings/{booking}/cancel` | customer | E2E-3: `tests/Browser/Booking/MyBookingsTest.php::'it cancels an upcoming booking from the list when within the cancellation window'` |

## Public booking API (JSON)

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `booking.providers` | `GET /booking/{slug}/providers` | public | E2E-3: `tests/Browser/Booking/GuestBookingHappyPathTest.php` (provider step calls this endpoint); `tests/Browser/Booking/ServicePreFilterTest.php` |
| `booking.available-dates` | `GET /booking/{slug}/available-dates` | public | E2E-3: `tests/Browser/Booking/GuestBookingHappyPathTest.php`; `tests/Browser/Booking/NoAvailabilityUxTest.php` (calendar fetches dates) |
| `booking.slots` | `GET /booking/{slug}/slots` | public | E2E-3: `tests/Browser/Booking/GuestBookingHappyPathTest.php`; `tests/Browser/Booking/RateLimitTest.php::'it returns 429 on the booking-api endpoints after 60 requests/min/IP'` |
| `booking.store` | `POST /booking/{slug}/book` | public | E2E-3: `tests/Browser/Booking/GuestBookingHappyPathTest.php`; `tests/Browser/Booking/HoneypotTest.php`; `tests/Browser/Booking/RateLimitTest.php::'it returns 429 after 5 booking-create submissions within a minute'` |

## Public booking pages & management

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `booking.show` | `GET /{slug}/{serviceSlug?}` | public | E2E-3: `tests/Browser/Booking/LandingPageTest.php`; `tests/Browser/Booking/ServicePreFilterTest.php` |
| `bookings.show` | `GET /bookings/{token}` | public (token) | E2E-3: `tests/Browser/Booking/ConfirmationAndCancelTest.php::'it displays booking details at /bookings/{token}'` |
| `bookings.cancel` | `POST /bookings/{token}/cancel` | public (token) | E2E-3: `tests/Browser/Booking/ConfirmationAndCancelTest.php::'it shows and acts on the Cancel button when within the cancellation window'` |
