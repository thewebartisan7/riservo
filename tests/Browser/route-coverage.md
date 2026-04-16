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
| `register` | `GET /register` | guest |  |
| `login` | `GET /login` | guest |  |
| `logout` | `POST /logout` | auth |  |
| `magic-link.create` | `GET /magic-link` | guest |  |
| `magic-link.store` | `POST /magic-link` | guest |  |
| `magic-link.verify` | `GET /magic-link/verify/{user}` | signed |  |
| `password.request` | `GET /forgot-password` | guest |  |
| `password.email` | `POST /forgot-password` | guest |  |
| `password.reset` | `GET /reset-password/{token}` | guest |  |
| `password.update` | `POST /reset-password` | guest |  |
| `invitation.show` | `GET /invite/{token}` | guest |  |
| `invitation.accept` | `POST /invite/{token}` | guest |  |
| `customer.register` | `GET /customer/register` | guest |  |

## Email verification

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `verification.notice` | `GET /email/verify` | auth |  |
| `verification.verify` | `GET /email/verify/{id}/{hash}` | auth, signed |  |
| `verification.send` | `POST /email/verification-notification` | auth |  |

## Onboarding (admin)

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `onboarding.show` | `GET /onboarding/step/{step}` | admin |  |
| `onboarding.store` | `POST /onboarding/step/{step}` | admin |  |
| `onboarding.slug-check` | `POST /onboarding/slug-check` | admin |  |
| `onboarding.logo-upload` | `POST /onboarding/logo-upload` | admin |  |
| `onboarding.enable-owner-as-provider` | `POST /onboarding/enable-owner-as-provider` | admin |  |

## Dashboard (admin + staff, onboarded)

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `dashboard` | `GET /dashboard` | admin, staff |  |
| `dashboard.welcome` | `GET /dashboard/welcome` | admin, staff |  |
| `dashboard.bookings` | `GET /dashboard/bookings` | admin, staff |  |
| `dashboard.bookings.store` | `POST /dashboard/bookings` | admin, staff |  |
| `dashboard.bookings.update-status` | `PATCH /dashboard/bookings/{booking}/status` | admin, staff |  |
| `dashboard.bookings.update-notes` | `PATCH /dashboard/bookings/{booking}/notes` | admin, staff |  |
| `dashboard.calendar` | `GET /dashboard/calendar` | admin, staff |  |
| `dashboard.api.available-dates` | `GET /dashboard/api/available-dates` | admin, staff |  |
| `dashboard.api.slots` | `GET /dashboard/api/slots` | admin, staff |  |
| `dashboard.customers` | `GET /dashboard/customers` | admin |  |
| `dashboard.customers.show` | `GET /dashboard/customers/{customer}` | admin |  |
| `dashboard.api.customers.search` | `GET /dashboard/api/customers/search` | admin |  |

## Settings — profile

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.profile` | `GET /dashboard/settings/profile` | admin |  |
| `settings.profile.update` | `PUT /dashboard/settings/profile` | admin |  |
| `settings.profile.upload-logo` | `POST /dashboard/settings/profile/logo` | admin |  |
| `settings.profile.slug-check` | `POST /dashboard/settings/profile/slug-check` | admin |  |

## Settings — booking

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.booking` | `GET /dashboard/settings/booking` | admin |  |
| `settings.booking.update` | `PUT /dashboard/settings/booking` | admin |  |

## Settings — hours

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.hours` | `GET /dashboard/settings/hours` | admin |  |
| `settings.hours.update` | `PUT /dashboard/settings/hours` | admin |  |

## Settings — business exceptions

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.exceptions` | `GET /dashboard/settings/exceptions` | admin |  |
| `settings.exceptions.store` | `POST /dashboard/settings/exceptions` | admin |  |
| `settings.exceptions.update` | `PUT /dashboard/settings/exceptions/{exception}` | admin |  |
| `settings.exceptions.destroy` | `DELETE /dashboard/settings/exceptions/{exception}` | admin |  |

## Settings — services

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.services` | `GET /dashboard/settings/services` | admin |  |
| `settings.services.create` | `GET /dashboard/settings/services/create` | admin |  |
| `settings.services.store` | `POST /dashboard/settings/services` | admin |  |
| `settings.services.edit` | `GET /dashboard/settings/services/{service}` | admin |  |
| `settings.services.update` | `PUT /dashboard/settings/services/{service}` | admin |  |

## Settings — staff & invitations

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.staff` | `GET /dashboard/settings/staff` | admin |  |
| `settings.staff.invite` | `POST /dashboard/settings/staff/invite` | admin |  |
| `settings.staff.resend-invitation` | `POST /dashboard/settings/staff/invitations/{invitation}/resend` | admin |  |
| `settings.staff.cancel-invitation` | `DELETE /dashboard/settings/staff/invitations/{invitation}` | admin |  |
| `settings.staff.show` | `GET /dashboard/settings/staff/{user}` | admin |  |
| `settings.staff.upload-avatar` | `POST /dashboard/settings/staff/{user}/avatar` | admin |  |

## Settings — providers

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.providers.toggle` | `POST /dashboard/settings/providers/{provider}/toggle` | admin |  |
| `settings.providers.update-schedule` | `PUT /dashboard/settings/providers/{provider}/schedule` | admin |  |
| `settings.providers.sync-services` | `PUT /dashboard/settings/providers/{provider}/services` | admin |  |
| `settings.providers.store-exception` | `POST /dashboard/settings/providers/{provider}/exceptions` | admin |  |
| `settings.providers.update-exception` | `PUT /dashboard/settings/providers/{provider}/exceptions/{exception}` | admin |  |
| `settings.providers.destroy-exception` | `DELETE /dashboard/settings/providers/{provider}/exceptions/{exception}` | admin |  |

## Settings — account (admin-as-provider, D-062)

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.account` | `GET /dashboard/settings/account` | admin |  |
| `settings.account.toggle-provider` | `POST /dashboard/settings/account/toggle-provider` | admin |  |
| `settings.account.update-schedule` | `PUT /dashboard/settings/account/schedule` | admin |  |
| `settings.account.store-exception` | `POST /dashboard/settings/account/exceptions` | admin |  |
| `settings.account.update-exception` | `PUT /dashboard/settings/account/exceptions/{exception}` | admin |  |
| `settings.account.destroy-exception` | `DELETE /dashboard/settings/account/exceptions/{exception}` | admin |  |
| `settings.account.update-services` | `PUT /dashboard/settings/account/services` | admin |  |

## Settings — embed & share

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `settings.embed` | `GET /dashboard/settings/embed` | admin |  |

## Customer area

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `customer.bookings` | `GET /my-bookings` | customer |  |
| `customer.bookings.cancel` | `POST /my-bookings/{booking}/cancel` | customer |  |

## Public booking API (JSON)

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `booking.providers` | `GET /booking/{slug}/providers` | public |  |
| `booking.available-dates` | `GET /booking/{slug}/available-dates` | public |  |
| `booking.slots` | `GET /booking/{slug}/slots` | public |  |
| `booking.store` | `POST /booking/{slug}/book` | public |  |

## Public booking pages & management

| Name | Method + Path | Role | Covered by |
|---|---|---|---|
| `booking.show` | `GET /{slug}/{serviceSlug?}` | public |  |
| `bookings.show` | `GET /bookings/{token}` | public (token) |  |
| `bookings.cancel` | `POST /bookings/{token}/cancel` | public (token) |  |
