export interface User {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
}

export interface Business {
    id: number;
    name: string;
    slug: string;
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
    notes: string | null;
    internal_notes: string | null;
    created_at: string;
    cancellation_token: string;
    service: { id: number; name: string; duration_minutes: number; price: number | null };
    provider: { id: number; name: string; avatar_url: string | null; is_active: boolean };
    customer: { id: number; name: string; email: string; phone: string | null };
}

export interface TodayBooking {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    service: { name: string; duration_minutes: number };
    provider: { id: number; name: string; is_active: boolean };
    customer: { name: string };
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
    };
    flash: {
        success: string | null;
        error: string | null;
    };
    locale: string;
    translations: Record<string, string>;
    [key: string]: unknown;
}
