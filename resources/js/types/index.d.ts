export interface User {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
}

export interface PageProps {
    auth: {
        user: User | null;
    };
    flash: {
        success: string | null;
        error: string | null;
    };
    locale: string;
    translations: Record<string, string>;
    [key: string]: unknown;
}
