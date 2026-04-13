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
    collaborator: { name: string };
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
    collaborator: { name: string };
    business: { name: string };
    can_cancel: boolean;
}

export interface InvitationData {
    token: string;
    email: string;
    role: string;
    business_name: string;
}

export interface PageProps {
    auth: {
        user: User | null;
        role: 'admin' | 'collaborator' | 'customer' | null;
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
