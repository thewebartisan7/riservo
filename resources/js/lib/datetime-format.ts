type T = (key: string, replacements?: Record<string, string | number>) => string;

export function formatTimeShort(isoString: string, timezone?: string): string {
    return new Date(isoString).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: timezone,
    });
}

export function formatDateTimeShort(isoString: string, timezone?: string): string {
    return new Date(isoString).toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: timezone,
    });
}

export function formatDateMedium(isoString: string | null): string {
    if (!isoString) return '—';
    return new Date(isoString).toLocaleDateString([], { dateStyle: 'medium' });
}

export function formatDateTimeMedium(isoString: string, timezone?: string): string {
    return new Date(isoString).toLocaleString([], {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: timezone,
    });
}

export function formatDateTimeLong(isoString: string, timezone?: string): string {
    return new Date(isoString).toLocaleString([], {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: timezone,
    });
}

export function formatRelativeDay(isoString: string, t: T, timezone?: string): string {
    const d = new Date(isoString);
    const today = new Date();
    const opts: Intl.DateTimeFormatOptions = { timeZone: timezone };
    const dKey = d.toLocaleDateString('sv', opts);
    const todayKey = today.toLocaleDateString('sv', opts);
    if (dKey === todayKey) return t('Today');
    const tomorrow = new Date(today);
    tomorrow.setDate(today.getDate() + 1);
    if (dKey === tomorrow.toLocaleDateString('sv', opts)) return t('Tomorrow');
    return d.toLocaleDateString([], {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: timezone,
    });
}
