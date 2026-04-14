type T = (key: string, replacements?: Record<string, string | number>) => string;

export function pad(n: number): string {
    return String(n).padStart(2, '0');
}

export function formatYmd(d: Date): string {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function formatDay(dateStr: string): string {
    const [, , d] = dateStr.split('-').map(Number);
    return pad(d);
}

export function formatMonthShort(dateStr: string): string {
    const [y, m, d] = dateStr.split('-').map(Number);
    return new Intl.DateTimeFormat(undefined, { month: 'short' })
        .format(new Date(y, m - 1, d))
        .toUpperCase();
}

export function formatDateLong(dateStr: string): string {
    const [y, m, d] = dateStr.split('-').map(Number);
    return new Intl.DateTimeFormat(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date(y, m - 1, d));
}

export function formatDateLongWithYear(dateStr: string): string {
    const [y, m, d] = dateStr.split('-').map(Number);
    return new Intl.DateTimeFormat(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(new Date(y, m - 1, d));
}

export function formatPrice(price: number | null, t: T): string {
    if (price === null) return t('On request');
    if (price === 0) return t('Free');
    return `CHF ${Number(price).toFixed(2)}`;
}

export function formatPriceValue(price: number | null, t: T): string {
    if (price === null) return t('On request');
    if (price === 0) return t('Free');
    return Number(price).toFixed(2);
}

export function formatDurationShort(minutes: number, t: T): string {
    if (minutes < 60) return `${minutes} ${t('min')}`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m === 0 ? `${h} ${t('h')}` : `${h} ${t('h')} ${m}`;
}

export function formatDurationFull(minutes: number, t: T): string {
    if (minutes < 60) return `${minutes} ${t('min')}`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m === 0 ? `${h} ${t('h')}` : `${h} ${t('h')} ${m} ${t('min')}`;
}

export function getInitials(name: string): string {
    return name
        .split(' ')
        .map((w) => w[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}
