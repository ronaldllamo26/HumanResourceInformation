import clsx, { type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Merge conditional class names, letting later Tailwind utilities win. */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}

/** "Juan Dela Cruz" -> "JD" */
export function initials(name: string | null | undefined = ''): string {
    return (
        (name ?? '')
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            // `noUncheckedIndexedAccess` is on, so `part[0]` is string | undefined
            // even after filter(Boolean) — the compiler cannot know the string is
            // non-empty. The fallback is not defensive noise: `"  a".split(' ')`
            // really does yield empty strings.
            .map((part) => part[0]?.toUpperCase() ?? '')
            .join('')
    );
}

export function formatDate(
    value: string | number | Date | null | undefined,
    options: Intl.DateTimeFormatOptions = {},
): string {
    if (!value) return '—';

    return new Date(value).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        ...options,
    });
}

export function formatCurrency(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
    }).format(Number(value));
}

type FilterValue = string | number | null | undefined;

/**
 * A link to the same screen, narrowed by one more thing.
 *
 * Summary tiles count something and then have to be able to *show* it, and the
 * only honest way to do that is to send the reader to the rows the figure was
 * counted from — inside whatever range and filters were already applied. A tile
 * that counted 53 late days in March and then opened an unfiltered list has not
 * answered the question it raised, it has replaced it.
 *
 * `clear` exists because most screens have a set of filters that narrow the
 * same axis — a status dropdown beside a tile-only flag, say. Leaving one
 * behind quietly ANDs them together and returns the rows that are both, which
 * is usually none of them.
 *
 * Falsy values are dropped so the URL carries only what is actually set.
 */
export function withFilters(
    path: string,
    filters: Record<string, FilterValue> = {},
    changes: Record<string, FilterValue> = {},
    clear: string[] = [],
): string {
    const merged: Record<string, FilterValue> = {
        ...filters,
        ...Object.fromEntries(clear.map((key) => [key, undefined])),
        ...changes,
    };

    const query = new URLSearchParams(
        Object.entries(merged)
            .filter(([, value]) => value !== null && value !== undefined && value !== '')
            .map(([key, value]) => [key, String(value)]),
    ).toString();

    return query ? `${path}?${query}` : path;
}
