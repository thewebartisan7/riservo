import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

export function useTrans() {
    const { translations } = usePage<PageProps>().props;

    function t(
        key: string,
        replacements?: Record<string, string | number>,
    ): string {
        let value = translations[key] ?? key;

        if (replacements) {
            for (const [placeholder, replacement] of Object.entries(
                replacements,
            )) {
                value = value.replaceAll(`:${placeholder}`, String(replacement));
            }
        }

        return value;
    }

    return { t };
}
