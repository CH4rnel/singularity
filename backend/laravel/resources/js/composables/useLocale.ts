import { ref } from 'vue';

export type Locale = 'en' | 'ru';

const STORAGE_KEY = 'locale';

function initialLocale(): Locale {
    if (typeof window === 'undefined') {
        return 'en';
    }

    const stored = window.localStorage.getItem(STORAGE_KEY);

    if (stored === 'en' || stored === 'ru') {
        return stored;
    }

    return window.navigator.language?.toLowerCase().startsWith('ru')
        ? 'ru'
        : 'en';
}

// Module-level so every component shares one reactive locale.
const locale = ref<Locale>(initialLocale());

export type Messages = Record<Locale, Record<string, string>>;

export function useLocale(messages?: Messages) {
    function setLocale(next: Locale) {
        locale.value = next;
        window.localStorage.setItem(STORAGE_KEY, next);
    }

    function toggleLocale() {
        setLocale(locale.value === 'ru' ? 'en' : 'ru');
    }

    /**
     * Reading locale.value inside t() keeps template usages reactive.
     *
     * `{name}` placeholders are filled from `params`, so a sentence that has to
     * name an amount or a chain stays one translatable string instead of being
     * concatenated from fragments that only line up in English.
     */
    function t(key: string, params?: Record<string, string | number>): string {
        const message =
            messages?.[locale.value][key] ?? messages?.en[key] ?? key;

        if (!params) {
            return message;
        }

        return message.replace(/\{(\w+)\}/g, (whole, name: string) =>
            name in params ? String(params[name]) : whole,
        );
    }

    return { locale, setLocale, toggleLocale, t };
}
