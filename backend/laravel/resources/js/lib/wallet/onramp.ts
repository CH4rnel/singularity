/**
 * Buying crypto with a bank card.
 *
 * Every other way into this wallet assumes somebody already holds something:
 * the bridge moves what you have, the swap trades what you have, the gas
 * station tops up what you have so it can move. Somebody holding a card and
 * nothing else has no door at all, and this is it.
 *
 * Three facts shape the whole screen, and none of them is hidden behind
 * friendly wording:
 *
 *  - **We are not the seller.** A provider takes the card, does the KYC and
 *    settles the crypto. Cyberia holds no card data, no money and no custody,
 *    and the destination is the address this browser derived. That is stated
 *    where somebody is about to leave for a page that is not ours.
 *  - **It does not land on Cyberia.** No on-ramp settles to chain 49406, so a
 *    purchase arrives on a chain that has one — the same address, a network
 *    this wallet already reads — and getting it to Cyberia is a second act the
 *    wallet performs with its own cross-chain swap. Two steps said out loud
 *    beat one step that quietly became two.
 *  - **Мир is not served, and we say why.** It runs on rails that stop at the
 *    borders of the countries wired into them, and every regulated provider
 *    refuses the network. The option stays on the screen with that sentence
 *    rather than being silently absent, because half this project's audience
 *    holds exactly that card.
 *
 * The pure parts below are pinned in `tests/Frontend/WalletOnrampTest.mjs`.
 */

/** What a provider might refuse with. One vocabulary, rendered per language. */
export type OnrampReason =
    | 'provider_off'
    | 'unconfigured'
    | 'country_restricted'
    | 'fiat_unsupported'
    | 'method_unsupported'
    | 'provider_unreachable'
    | 'no_quote'
    | 'mir_unserved';

export type OnrampMethod =
    | 'card'
    | 'bank'
    | 'apple_pay'
    | 'google_pay'
    | 'paypal';

/** Where a purchase can land. Never Cyberia — see the note above. */
export type OnrampDelivery = {
    key: string;
    chain: string;
    asset: string;
    kind: 'coin' | 'token';
};

export type OnrampProviderInfo = {
    key: string;
    label: string;
    kind: 'widget' | 'aggregator';
    methods: OnrampMethod[];
    fiat: string[];
    available: boolean;
    reason: OnrampReason | null;
    /** `webhook` = this wallet will learn how it ended; `none` = it will not. */
    tracking: 'webhook' | 'none';
    /** What the operator says they set inside that provider's dashboard. */
    declaredFeeBps: number;
};

export type OnrampCatalogue = {
    enabled: boolean;
    country: string | null;
    delivery: OnrampDelivery[];
    defaultDelivery: string;
    providers: OnrampProviderInfo[];
    mir: {
        available: boolean;
        url: string;
        operator: string;
        reason: OnrampReason;
    };
};

export type OnrampOffer = {
    provider: string;
    label: string;
    kind: 'widget' | 'aggregator';
    available: boolean;
    reason: OnrampReason | null;
    /** What arrives, as a decimal string. Null when nothing was quoted. */
    cryptoAmount: string | null;
    fiatAmount: string | null;
    rate: string | null;
    fee: string | null;
    /** For an aggregator: which on-ramp underneath won. */
    via: string | null;
    tracking: 'webhook' | 'none';
    best: boolean;
};

export type OnrampOrder = {
    reference: string;
    provider: string;
    status:
        | 'pending'
        | 'paid'
        | 'delivering'
        | 'delivered'
        | 'failed'
        | 'expired';
    fiat: string;
    fiatAmount: string;
    cryptoAmount: string | null;
    chain: string;
    asset: string;
    address: string;
    method: OnrampMethod;
    tx: string | null;
    at: string | null;
    deliveredAt: string | null;
};

export type OnrampPurchase = {
    fiat: string;
    amount: string;
    country: string | null;
    method: OnrampMethod;
    delivery: string;
    address: string;
};

/**
 * The amount, as the server will accept it.
 *
 * Two decimal places and nothing else: a fiat amount is a price in somebody's
 * own currency, four providers are about to be asked about this exact string,
 * and a locale that types `1,50` must not become `1`. A comma is read as the
 * decimal separator it is in most of the languages this wallet speaks.
 */
export const parseFiatAmount = (input: string): string | null => {
    const cleaned = input.trim().replace(/\s/g, '').replace(',', '.');

    if (!/^[0-9]{1,9}(\.[0-9]{1,2})?$/.test(cleaned)) {
        return null;
    }

    return Number(cleaned) > 0 ? cleaned : null;
};

/**
 * Where the browser says it is, or null.
 *
 * Read off the language tag and nothing else — no IP lookup, no geolocation
 * prompt, no third-party "where are you" service. A bare `ru` says a language
 * and not a country, so it answers null and every provider is offered; the
 * provider's own check is the one that decides anyway, and guessing wrong
 * would hide a route somebody can actually use.
 */
export const countryFromLanguage = (tag: string): string | null => {
    const region = /^[A-Za-z]{2,3}[-_]([A-Za-z]{2})\b/.exec(tag.trim());

    return region ? region[1].toUpperCase() : null;
};

/** The currency somebody in this country most likely holds. */
export const fiatForCountry = (country: string | null): string => {
    const known: Record<string, string> = {
        RU: 'RUB',
        BY: 'RUB',
        US: 'USD',
        GB: 'GBP',
        AU: 'AUD',
        CA: 'CAD',
        JP: 'JPY',
        IN: 'INR',
        BR: 'BRL',
        TR: 'TRY',
        CN: 'USD',
        KZ: 'USD',
    };

    if (country === null) {
        return 'USD';
    }

    return known[country] ?? (EURO_COUNTRIES.has(country) ? 'EUR' : 'USD');
};

const EURO_COUNTRIES = new Set([
    'AT',
    'BE',
    'CY',
    'DE',
    'EE',
    'ES',
    'FI',
    'FR',
    'GR',
    'HR',
    'IE',
    'IT',
    'LT',
    'LU',
    'LV',
    'MT',
    'NL',
    'PT',
    'SI',
    'SK',
]);

/**
 * Whether the Мир corridor is the thing this person came here for.
 *
 * It stays on the screen for everybody — a corridor with a reason is
 * information — but for somebody in the countries those rails serve it is the
 * first row rather than a footnote under four providers that will refuse them.
 */
export const mirIsTheQuestion = (
    country: string | null,
    fiat: string,
): boolean => country === 'RU' || country === 'BY' || fiat === 'RUB';

/**
 * Does this purchase need a second act to reach Cyberia?
 *
 * True for everything, today, and written as a function rather than as `true`
 * because the day an on-ramp settles to Cyberia directly, this is the one
 * place that has to change.
 */
export const needsSecondStep = (chain: string): boolean => chain !== 'cyberia';

/** The offer a screen should put first, or null when nobody could quote. */
export const bestOffer = (offers: OnrampOffer[]): OnrampOffer | null =>
    offers.find((offer) => offer.available) ?? null;

/**
 * How much more the winner pays than the runner-up, in percent.
 *
 * Only meaningful between two real quotes for the same purchase, which is why
 * a single answer returns null instead of "100% better than nothing".
 */
export const offerAdvantagePct = (offers: OnrampOffer[]): number | null => {
    const quoted = offers.filter(
        (offer) => offer.available && offer.cryptoAmount !== null,
    );

    if (quoted.length < 2) {
        return null;
    }

    const best = Number(quoted[0].cryptoAmount);
    const next = Number(quoted[1].cryptoAmount);

    return next > 0 ? ((best - next) / next) * 100 : null;
};

const json = async (response: Response): Promise<Record<string, unknown>> => {
    const body = (await response.json().catch(() => ({}))) as Record<
        string,
        unknown
    >;

    if (!response.ok) {
        throw new Error(
            typeof body.error === 'string'
                ? body.error
                : `The purchase service answered ${response.status}.`,
        );
    }

    return body;
};

const readOffer = (row: Record<string, unknown>): OnrampOffer => ({
    provider: String(row.provider ?? ''),
    label: String(row.label ?? ''),
    kind: row.kind === 'aggregator' ? 'aggregator' : 'widget',
    available: row.available === true,
    reason: (row.reason as OnrampReason | null) ?? null,
    cryptoAmount:
        typeof row.crypto_amount === 'string' ? row.crypto_amount : null,
    fiatAmount: typeof row.fiat_amount === 'string' ? row.fiat_amount : null,
    rate: typeof row.rate === 'string' ? row.rate : null,
    fee: typeof row.fee === 'string' ? row.fee : null,
    via: typeof row.via === 'string' ? row.via : null,
    tracking: row.tracking === 'webhook' ? 'webhook' : 'none',
    best: row.best === true,
});

const readOrder = (row: Record<string, unknown>): OnrampOrder => ({
    reference: String(row.reference ?? ''),
    provider: String(row.provider ?? ''),
    status: (row.status as OnrampOrder['status']) ?? 'pending',
    fiat: String(row.fiat ?? ''),
    fiatAmount: String(row.fiat_amount ?? ''),
    cryptoAmount:
        typeof row.crypto_amount === 'string' ? row.crypto_amount : null,
    chain: String(row.chain ?? ''),
    asset: String(row.asset ?? ''),
    address: String(row.address ?? ''),
    method: (row.method as OnrampMethod) ?? 'card',
    tx: typeof row.tx === 'string' ? row.tx : null,
    at: typeof row.at === 'string' ? row.at : null,
    deliveredAt: typeof row.delivered_at === 'string' ? row.delivered_at : null,
});

export const fetchOnrampCatalogue = async (
    country: string | null,
): Promise<OnrampCatalogue> => {
    const query =
        country === null ? '' : `?country=${encodeURIComponent(country)}`;
    const body = await json(
        await fetch(`/api/wallet/onramp${query}`, {
            headers: { Accept: 'application/json' },
        }),
    );

    const mir = (body.mir ?? {}) as Record<string, unknown>;

    return {
        enabled: body.enabled === true,
        country: typeof body.country === 'string' ? body.country : null,
        delivery: ((body.delivery ?? []) as Record<string, unknown>[]).map(
            (row) => ({
                key: String(row.key ?? ''),
                chain: String(row.chain ?? ''),
                asset: String(row.asset ?? ''),
                kind: row.kind === 'token' ? 'token' : 'coin',
            }),
        ),
        defaultDelivery: String(body.default_delivery ?? ''),
        providers: ((body.providers ?? []) as Record<string, unknown>[]).map(
            (row) => ({
                key: String(row.key ?? ''),
                label: String(row.label ?? ''),
                kind: row.kind === 'aggregator' ? 'aggregator' : 'widget',
                methods: (row.methods ?? []) as string[] as OnrampMethod[],
                fiat: (row.fiat ?? []) as string[],
                available: row.available === true,
                reason: (row.reason as OnrampReason | null) ?? null,
                tracking: row.tracking === 'webhook' ? 'webhook' : 'none',
                declaredFeeBps: Number(row.declared_fee_bps ?? 0),
            }),
        ),
        mir: {
            available: mir.available === true,
            url: String(mir.url ?? ''),
            operator: String(mir.operator ?? ''),
            reason: (mir.reason as OnrampReason) ?? 'mir_unserved',
        },
    };
};

export const quoteOnramp = async (
    purchase: OnrampPurchase,
): Promise<OnrampOffer[]> => {
    const body = await json(
        await fetch('/api/wallet/onramp/quote', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(purchase),
        }),
    );

    return ((body.offers ?? []) as Record<string, unknown>[]).map(readOffer);
};

/**
 * Start a purchase and get the URL to send somebody to.
 *
 * The reference comes back from the server, which minted it: it is what ties a
 * webhook arriving an hour from now to this order, and a browser that could
 * name it could name somebody else's.
 */
export const startOnrampCheckout = async (
    purchase: OnrampPurchase & { provider: string },
): Promise<{ url: string; reference: string; order: OnrampOrder }> => {
    const body = await json(
        await fetch('/api/wallet/onramp/checkout', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(purchase),
        }),
    );

    return {
        url: String(body.url ?? ''),
        reference: String(body.reference ?? ''),
        order: readOrder((body.order ?? {}) as Record<string, unknown>),
    };
};

export const readOnrampOrder = async (
    reference: string,
): Promise<OnrampOrder> => {
    const body = await json(
        await fetch(
            `/api/wallet/onramp/order/${encodeURIComponent(reference)}`,
            {
                headers: { Accept: 'application/json' },
            },
        ),
    );

    return readOrder((body.order ?? {}) as Record<string, unknown>);
};

/** Purchases this browser started, so a screen can show one after a redirect. */
const STORE = 'cyberia.wallet.onramp.v1';

export const rememberOnrampOrder = (reference: string): void => {
    try {
        const kept = readRememberedOnramp();

        localStorage.setItem(
            STORE,
            JSON.stringify(
                [reference, ...kept.filter((one) => one !== reference)].slice(
                    0,
                    10,
                ),
            ),
        );
    } catch {
        // A browser with storage switched off still buys; it just cannot be
        // shown the order when it comes back.
    }
};

export const readRememberedOnramp = (): string[] => {
    try {
        const raw = JSON.parse(localStorage.getItem(STORE) ?? '[]');

        return Array.isArray(raw)
            ? raw.filter((one) => typeof one === 'string')
            : [];
    } catch {
        return [];
    }
};
