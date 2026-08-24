import type { Locale } from '@/composables/useLocale';

/**
 * The console's small shared vocabulary: how a duration is said, how a number
 * is set, and which of the four signals a tone maps to.
 *
 * It lives outside the components because every lens formats the same things
 * and a second implementation of "12 минут" is how two screens end up
 * disagreeing about the same row.
 */

/** Pick a plural form out of `one|few|many`. */
export function plural(locale: Locale, count: number, forms: string): string {
    const parts = forms.split('|');

    if (locale !== 'ru') {
        return Math.abs(count) === 1 ? parts[0] : (parts[1] ?? parts[0]);
    }

    const n = Math.abs(count) % 100;
    const last = n % 10;

    if (n > 10 && n < 20) {
        return parts[2] ?? parts[0];
    }

    if (last === 1) {
        return parts[0];
    }

    if (last >= 2 && last <= 4) {
        return parts[1] ?? parts[0];
    }

    return parts[2] ?? parts[0];
}

export type Age = { value: string; unit: string; count: number; scale: 'second' | 'minute' | 'hour' | 'day' };

/**
 * How long something has been in this state — the console's priority column.
 *
 * Under an hour it is minutes, under a day it is h:mm (three hours and twelve
 * minutes is one glance, 192 minutes is arithmetic), above that it is days.
 */
export function age(seconds: number | null | undefined): Age | null {
    if (seconds === null || seconds === undefined) {
        return null;
    }

    const total = Math.max(0, Math.floor(seconds));

    if (total < 60) {
        return { value: String(total), unit: 'unit.second', count: total, scale: 'second' };
    }

    const minutes = Math.floor(total / 60);

    if (minutes < 60) {
        return { value: String(minutes), unit: 'unit.minute', count: minutes, scale: 'minute' };
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        const rest = minutes % 60;

        return {
            value: `${hours}:${String(rest).padStart(2, '0')}`,
            unit: 'unit.hour',
            count: hours,
            scale: 'hour',
        };
    }

    const days = Math.floor(hours / 24);

    return { value: String(days), unit: 'unit.day', count: days, scale: 'day' };
}

/** Seconds between now and an ISO timestamp, or null if there isn't one. */
export function secondsSince(iso: string | null | undefined): number | null {
    if (!iso) {
        return null;
    }

    const at = Date.parse(iso);

    return Number.isNaN(at) ? null : Math.floor((Date.now() - at) / 1000);
}

/**
 * Thin-spaced thousands and a dot for the decimal, in both languages.
 *
 * Not the reader's locale: the console is read by two people side by side and
 * half of its numbers are money, where a comma decimal reads to the other one
 * as a thousands separator. The grouping is a thin space, which neither
 * language misreads.
 */
export function num(value: number | null | undefined, digits = 0): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    return value
        .toLocaleString('en-US', {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        })
        .replace(/,/g, '\u2009');
}

/** Money, in the shortest form that is still exact enough to act on. */
export function usd(value: number | null | undefined): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    const abs = Math.abs(value);

    if (abs >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(1)}M`;
    }

    if (abs >= 10_000) {
        return `$${num(Math.round(value))}`;
    }

    if (abs >= 1) {
        return `$${num(value, 2)}`;
    }

    // Zero is an answer and reads as one; fractions of a cent are noise in a
    // column somebody is scanning, so they round to a cent too.
    return value === 0 ? '$0' : `$${value.toFixed(2)}`;
}

/**
 * Numbers on their way into a sentence.
 *
 * The server sends parameters, not prose, so `148320` would otherwise land in
 * the middle of a Russian sentence exactly as the column holds it. Whole
 * numbers get the console's grouping; anything with a fraction is a rate or a
 * balance and is left alone, because rounding it here would change what the
 * sentence claims.
 */
export function grouped(
    params: Record<string, string | number | null | undefined>,
): Record<string, string | number> {
    const out: Record<string, string | number> = {};

    for (const [key, value] of Object.entries(params ?? {})) {
        out[key] =
            typeof value === 'number' && Number.isInteger(value) && Math.abs(value) >= 1000
                ? num(value)
                : (value ?? '');
    }

    return out;
}

export function percent(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : `${value}%`;
}

/**
 * The four signals, and nothing else.
 *
 * Anything working is neutral grey on purpose: if forty services glow green,
 * the forty-first glowing red is invisible.
 */
export const TONE: Record<string, string> = {
    critical: 'var(--mk-critical)',
    warning: 'var(--mk-warning)',
    money: 'var(--mk-money)',
    accent: 'var(--mk-accent)',
    good: 'var(--mk-accent)',
    calm: 'var(--mk-calm)',
    neutral: 'var(--mk-dim)',
    plain: 'var(--mk-dim)',
    unknown: 'var(--mk-dim)',
};

export function toneColor(tone: string | null | undefined): string {
    return TONE[tone ?? 'plain'] ?? 'var(--mk-dim)';
}

/** Service state → the colour it is drawn in. `unknown` is hatched instead. */
export const STATUS_TONE: Record<string, string> = {
    up: 'calm',
    degraded: 'warning',
    down: 'critical',
    unknown: 'unknown',
    off: 'plain',
};

export function shortTime(iso: string | null | undefined, tag: string): string {
    if (!iso) {
        return '—';
    }

    const at = new Date(iso);

    return Number.isNaN(at.getTime())
        ? '—'
        : at.toLocaleTimeString(tag, { hour: '2-digit', minute: '2-digit' });
}

export function shortDate(iso: string | null | undefined, tag: string): string {
    if (!iso) {
        return '—';
    }

    const at = new Date(iso);

    return Number.isNaN(at.getTime())
        ? '—'
        : at.toLocaleDateString(tag, { day: '2-digit', month: '2-digit' });
}

export function dateTime(iso: string | null | undefined, tag: string): string {
    if (!iso) {
        return '—';
    }

    const at = new Date(iso);

    return Number.isNaN(at.getTime())
        ? '—'
        : `${at.toLocaleDateString(tag, { day: '2-digit', month: '2-digit' })} ${at.toLocaleTimeString(
              tag,
              { hour: '2-digit', minute: '2-digit' },
          )}`;
}
