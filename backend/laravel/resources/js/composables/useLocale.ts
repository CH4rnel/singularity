import { computed, ref, watch } from 'vue';

export const LOCALES = ['en', 'ru', 'zh'] as const;

export type Locale = (typeof LOCALES)[number];

/**
 * Named in their own language, the way a language list is read by someone who
 * cannot read the current one. `zh` is Simplified Chinese and says so.
 */
export const LOCALE_LABELS: Record<Locale, string> = {
    en: 'English',
    ru: 'Русский',
    zh: '简体中文',
};

/**
 * What a one-button switch prints for each language. "ZH" means nothing to the
 * reader it is for, so the Chinese button is written in Chinese.
 */
export const LOCALE_TAGS: Record<Locale, string> = {
    en: 'EN',
    ru: 'RU',
    zh: '中文',
};

/**
 * BCP-47 tags for `Intl` and for the `lang` attribute. Chinese carries its
 * region on purpose: Han characters are unified across languages, so a browser
 * given a bare `zh` — or nothing at all — can pick Japanese or Korean glyph
 * shapes for characters this wallet means as Simplified Chinese.
 */
export const LOCALE_TAGS_BCP47: Record<Locale, string> = {
    en: 'en-GB',
    ru: 'ru-RU',
    zh: 'zh-CN',
};

const STORAGE_KEY = 'locale';

function isLocale(value: unknown): value is Locale {
    return LOCALES.includes(value as Locale);
}

function initialLocale(): Locale {
    if (typeof window === 'undefined') {
        return 'en';
    }

    const stored = window.localStorage.getItem(STORAGE_KEY);

    if (isLocale(stored)) {
        return stored;
    }

    const browser = window.navigator.language?.toLowerCase() ?? '';

    if (browser.startsWith('ru')) {
        return 'ru';
    }

    // Every `zh-*` lands on Simplified, including the Traditional regions. It
    // is the wrong script for a reader in Taipei or Hong Kong, but it is far
    // closer than English, and the switch is one click away.
    if (browser.startsWith('zh')) {
        return 'zh';
    }

    return 'en';
}

// Module-level so every component shares one reactive locale.
const locale = ref<Locale>(initialLocale());

if (typeof document !== 'undefined') {
    // The server renders `lang` from Laravel's locale, which never changes —
    // the language here is a browser-side choice, so this is the only place
    // the document can learn about it. Font fallback for Chinese depends on it.
    watch(
        locale,
        (next) => {
            document.documentElement.lang = LOCALE_TAGS_BCP47[next];
        },
        { immediate: true },
    );
}

/**
 * English is required and every other language is optional: a dictionary that
 * has not been translated yet still type-checks, and `t()` falls through to
 * English rather than rendering a blank. `useLocale` cycles only through the
 * languages a dictionary actually carries, so a screen with no Chinese offers
 * no Chinese instead of offering an English screen under a Chinese label.
 */
export type Messages = { en: Record<string, string> } & Partial<
    Record<Locale, Record<string, string>>
>;

export function useLocale(messages?: Messages) {
    const available = computed<readonly Locale[]>(() => {
        if (!messages) {
            return LOCALES;
        }

        const translated = LOCALES.filter((id) => messages[id] !== undefined);

        return translated.length > 0 ? translated : ['en'];
    });

    const nextLocale = computed<Locale>(() => {
        const list = available.value;
        const at = list.indexOf(locale.value);

        // A locale this dictionary does not carry has no successor in the
        // list, so the cycle restarts rather than sticking.
        return list[(at + 1) % list.length];
    });

    const nextTag = computed(() => LOCALE_TAGS[nextLocale.value]);

    // For `Intl` and anything else that wants a real language tag.
    const tag = computed(() => LOCALE_TAGS_BCP47[locale.value]);

    function setLocale(next: Locale) {
        locale.value = next;
        window.localStorage.setItem(STORAGE_KEY, next);
    }

    function toggleLocale() {
        setLocale(nextLocale.value);
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
            messages?.[locale.value]?.[key] ?? messages?.en[key] ?? key;

        if (!params) {
            return message;
        }

        return message.replace(/\{(\w+)\}/g, (whole, name: string) =>
            name in params ? String(params[name]) : whole,
        );
    }

    return {
        locale,
        available,
        nextLocale,
        nextTag,
        tag,
        setLocale,
        toggleLocale,
        t,
    };
}
