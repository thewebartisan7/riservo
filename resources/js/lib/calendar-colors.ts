/**
 * Provider color palette for the calendar view (D-059).
 * Tailwind utility classes for consistent event styling across light and dark themes.
 *
 * Colors lean on Tailwind's 500-level base for dots (bright, unambiguous), with
 * lightened backgrounds for light mode and tinted dark-mode variants that survive
 * the shift to the dark palette without going washed out or neon.
 */
export interface ProviderColor {
    bg: string;
    hoverBg: string;
    text: string;
    accent: string;
    dot: string;
}

const PROVIDER_COLORS: ProviderColor[] = [
    {
        bg: 'bg-blue-50 dark:bg-blue-500/15',
        hoverBg: 'hover:bg-blue-100 dark:hover:bg-blue-500/22',
        text: 'text-blue-700 dark:text-blue-200',
        accent: 'text-blue-600/80 dark:text-blue-300/80',
        dot: 'bg-blue-500',
    },
    {
        bg: 'bg-pink-50 dark:bg-pink-500/15',
        hoverBg: 'hover:bg-pink-100 dark:hover:bg-pink-500/22',
        text: 'text-pink-700 dark:text-pink-200',
        accent: 'text-pink-600/80 dark:text-pink-300/80',
        dot: 'bg-pink-500',
    },
    {
        bg: 'bg-indigo-50 dark:bg-indigo-500/15',
        hoverBg: 'hover:bg-indigo-100 dark:hover:bg-indigo-500/22',
        text: 'text-indigo-700 dark:text-indigo-200',
        accent: 'text-indigo-600/80 dark:text-indigo-300/80',
        dot: 'bg-indigo-500',
    },
    {
        bg: 'bg-emerald-50 dark:bg-emerald-500/15',
        hoverBg: 'hover:bg-emerald-100 dark:hover:bg-emerald-500/22',
        text: 'text-emerald-700 dark:text-emerald-200',
        accent: 'text-emerald-600/80 dark:text-emerald-300/80',
        dot: 'bg-emerald-500',
    },
    {
        bg: 'bg-amber-50 dark:bg-amber-500/15',
        hoverBg: 'hover:bg-amber-100 dark:hover:bg-amber-500/22',
        text: 'text-amber-800 dark:text-amber-200',
        accent: 'text-amber-700/80 dark:text-amber-300/80',
        dot: 'bg-amber-500',
    },
    {
        bg: 'bg-violet-50 dark:bg-violet-500/15',
        hoverBg: 'hover:bg-violet-100 dark:hover:bg-violet-500/22',
        text: 'text-violet-700 dark:text-violet-200',
        accent: 'text-violet-600/80 dark:text-violet-300/80',
        dot: 'bg-violet-500',
    },
    {
        bg: 'bg-teal-50 dark:bg-teal-500/15',
        hoverBg: 'hover:bg-teal-100 dark:hover:bg-teal-500/22',
        text: 'text-teal-700 dark:text-teal-200',
        accent: 'text-teal-600/80 dark:text-teal-300/80',
        dot: 'bg-teal-500',
    },
    {
        bg: 'bg-rose-50 dark:bg-rose-500/15',
        hoverBg: 'hover:bg-rose-100 dark:hover:bg-rose-500/22',
        text: 'text-rose-700 dark:text-rose-200',
        accent: 'text-rose-600/80 dark:text-rose-300/80',
        dot: 'bg-rose-500',
    },
];

export function getProviderColor(index: number): ProviderColor {
    return PROVIDER_COLORS[index % PROVIDER_COLORS.length];
}

export function getProviderColorMap(providerIds: number[]): Map<number, ProviderColor> {
    const map = new Map<number, ProviderColor>();
    providerIds.forEach((id, index) => {
        map.set(id, getProviderColor(index));
    });
    return map;
}
