export interface User {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
}

export interface SubscriptionState {
    status: 'trial' | 'active' | 'past_due' | 'canceled' | 'read_only';
    trial_ends_at: string | null;
    current_period_ends_at: string | null;
}

export interface ConnectedAccountState {
    status: 'not_connected' | 'pending' | 'incomplete' | 'active' | 'disabled';
    country: string | null;
    can_accept_online_payments: boolean;
    payment_mode_mismatch: boolean;
}

export interface Business {
    id: number;
    name: string;
    slug: string;
    subscription: SubscriptionState;
    connected_account: ConnectedAccountState;
}

export interface BookingDetail {
    id: number;
    token: string;
    starts_at: string;
    ends_at: string;
    status: string;
    notes: string | null;
    service: { name: string; duration_minutes: number; price: number | null };
    provider: { name: string; is_active: boolean };
    business: { name: string; timezone: string; cancellation_window_hours: number };
    customer: { name: string };
    can_cancel: boolean;
    // PAYMENTS Session 2a — the bookings/show page renders a payment badge,
    // paid-amount row, and a resume-link for awaiting-payment bookings.
    payment: {
        status:
            | 'not_applicable'
            | 'awaiting_payment'
            | 'paid'
            | 'unpaid'
            | 'refunded'
            | 'partially_refunded'
            | 'refund_failed';
        paid_amount_cents: number | null;
        currency: string | null;
        paid_at: string | null;
        expires_at: string | null;
        stripe_checkout_session_id: string | null;
    };
}

export interface BookingSummary {
    id: number;
    token: string;
    starts_at: string;
    ends_at: string;
    status: string;
    service: { name: string };
    provider: { name: string; is_active: boolean };
    business: { name: string; timezone: string };
    can_cancel: boolean;
}

export interface InvitationData {
    token: string;
    email: string;
    role: string;
    business_name: string;
}

export interface PublicBusiness {
    name: string;
    slug: string;
    description: string | null;
    logo_url: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    timezone: string;
    allow_provider_choice: boolean;
    confirmation_mode: 'auto' | 'manual';
    // PAYMENTS Session 2a — public booking UI branches on these three to
    // render the "Continue to payment" CTA and the customer_choice
    // pay-now / pay-on-site pill.
    payment_mode: 'offline' | 'online' | 'customer_choice';
    can_accept_online_payments: boolean;
    currency: string | null;
}

export interface PublicService {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    duration_minutes: number;
    price: number | null;
}

export interface PublicProvider {
    id: number;
    name: string;
    avatar_url: string | null;
}

// Dashboard types
export interface DashboardBooking {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    source: string;
    external: boolean;
    external_title: string | null;
    external_html_link: string | null;
    notes: string | null;
    internal_notes: string | null;
    created_at: string;
    cancellation_token: string;
    service: { id: number; name: string; duration_minutes: number; price: number | null } | null;
    provider: { id: number; name: string; avatar_url: string | null; is_active: boolean };
    customer: { id: number; name: string; email: string; phone: string | null } | null;
    // PAYMENTS Session 2b — payment panel on the booking-detail sheet +
    // Payment column on the bookings list. Admin-only; the backend
    // returns null for non-admin viewers (Codex Round 1 F2 — staff see
    // their own bookings and must not receive Stripe ids).
    payment: {
        status:
            | 'not_applicable'
            | 'awaiting_payment'
            | 'paid'
            | 'unpaid'
            | 'refunded'
            | 'partially_refunded'
            | 'refund_failed';
        paid_amount_cents: number | null;
        currency: string | null;
        paid_at: string | null;
        stripe_charge_id: string | null;
        stripe_payment_intent_id: string | null;
        stripe_connected_account_id: string | null;
    } | null;
    pending_payment_action: PendingPaymentAction | null;
}

export interface PendingPaymentAction {
    id: number;
    type: 'payment.cancelled_after_payment' | 'payment.refund_failed';
    payload: Record<string, unknown>;
    created_at: string;
}

export interface TodayBooking {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    external: boolean;
    external_title: string | null;
    service: { name: string; duration_minutes: number } | null;
    provider: { id: number; name: string; is_active: boolean };
    customer: { name: string } | null;
}

export interface CalendarPendingAction {
    id: number;
    type: 'riservo_event_deleted_in_google' | 'external_booking_conflict';
    payload: Record<string, unknown> & {
        external_event_id?: string;
        external_summary?: string | null;
        external_start?: string;
        external_end?: string;
    };
    created_at: string;
    booking: {
        id: number;
        starts_at: string;
        customer_name: string | null;
        service_name: string | null;
    } | null;
}

export interface DashboardStats {
    today_count: number;
    week_count: number;
    upcoming_count: number;
    pending_count: number;
}

export interface DashboardCustomer {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    bookings_count: number;
    last_booking_at: string | null;
}

export interface DashboardCustomerDetail {
    id: number;
    name: string;
    email: string;
    phone: string | null;
}

export interface CustomerBookingHistory {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    source: string;
    service: { name: string; duration_minutes: number; price: number | null };
    provider: { id: number; name: string; is_active: boolean };
}

export interface FilterOption {
    id: number;
    name: string;
}

export interface ServiceWithProviders extends FilterOption {
    duration_minutes: number;
    price: number | null;
    providers: FilterOption[];
}

// useHttp response shapes
export interface SlugCheckResponse {
    available: boolean;
}

export interface FileUploadResponse {
    path: string;
    url: string;
}

export interface AvatarUploadResponse {
    url: string;
}

export interface AvailableDatesResponse {
    dates: Record<string, boolean>;
}

export interface AvailableSlotsResponse {
    slots: string[];
}

export interface BookingStoreResponse {
    token: string;
    status: string;
    // PAYMENTS Session 2a: internal URL on the offline path; absolute
    // Stripe Checkout URL on the online path. The summary component
    // dispatches on `external_redirect` (Codex Round 2, D-161) —
    // a `https://` prefix heuristic would match HTTPS-deployed riservo
    // internal URLs too.
    redirect_url: string;
    external_redirect: boolean;
}

export interface CustomerSearchResponse {
    customers: Array<{
        id: number;
        name: string;
        email: string;
        phone: string | null;
    }>;
}

// Calendar types
export interface CalendarProvider {
    id: number;
    name: string;
    avatar_url: string | null;
}

export interface PageProps {
    auth: {
        user: User | null;
        role: 'admin' | 'staff' | 'customer' | null;
        business: Business | null;
        email_verified: boolean;
        has_active_provider: boolean;
    };
    flash: {
        success: string | null;
        error: string | null;
    };
    bookability: {
        unbookableServices: Array<{ id: number; name: string }>;
    };
    calendarPendingActionsCount: number;
    locale: string;
    translations: Record<string, string>;
    [key: string]: unknown;
}
