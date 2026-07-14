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

    // Reading locale.value inside t() keeps template usages reactive.
    function t(key: string): string {
        return messages?.[locale.value][key] ?? messages?.en[key] ?? key;
    }

    return { locale, setLocale, toggleLocale, t };
}
