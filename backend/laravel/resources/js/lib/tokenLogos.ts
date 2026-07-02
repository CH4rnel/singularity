import { reactive } from 'vue';

// Token logos served from public/token-icons/ (copied from the Ritual DEX), keyed by
// on-chain symbol. Used by both the Lending and Farm pages via <TokenIcon>.
//
// To add a missing one: drop the file in public/token-icons/ and add a line here —
// both pages pick it up. Tokens with no entry render a lettered gradient avatar.
export const TOKEN_LOGOS: Record<string, string> = {
    WCYBER: '/token-icons/cyberia.png',
    'CYBER.sol': '/token-icons/CYBER.png',
    ASH: '/token-icons/ash.png',
    RUB: '/token-icons/rub.png',
    USDC: '/token-icons/usdc.svg',
    USDT: '/token-icons/usdt.svg',
    BTC: '/token-icons/btc.svg',
    LTC: '/token-icons/ltc.svg',
    SILVER: '/token-icons/silver.png',
    SOL: '/token-icons/sol.svg',
    ETH: '/token-icons/eth.svg',
    XMR: '/token-icons/monero.svg',
    TRX: '/token-icons/tron.svg',
    GOLD: '/token-icons/gold.png',
    TRUR: '/token-icons/trur.png',
    TGLD: '/token-icons/tgld.png',
    TMOS: '/token-icons/tmos.png',
    TOFZ: '/token-icons/tofz.png',
    HATCHER: '/token-icons/hatcher.jpg',
    KRSQ: '/token-icons/karasique.webp',
    YTN: '/token-icons/yenten.png',
    LAIN: '/token-icons/lain.jpg',
    MINE: '/token-icons/mine.jpg',
    GOAL: '/token-icons/goal.webp',
    TG: '/token-icons/telegram.svg',
};

// Symbols whose <img> failed to load (404). Module-level so a single failure is
// remembered across every icon of that token on the page.
const failed = reactive<Record<string, boolean>>({});

export const logoFor = (symbol: string): string | undefined =>
    TOKEN_LOGOS[symbol];

export const showLogo = (symbol: string): boolean =>
    !!TOKEN_LOGOS[symbol] && !failed[symbol];

// Keyed by whatever string identifies the image (symbol or explicit URL), so
// TokenIcon can track failures for both the built-in map and registry logos.
export const logoFailed = (key: string): boolean => !!failed[key];

export const markLogoFailed = (key: string): void => {
    failed[key] = true;
};

// Deterministic hue per symbol for the gradient fallback avatar.
export const hueFor = (symbol: string): number => {
    let h = 0;

    for (const c of symbol) {
        h = (h * 31 + c.charCodeAt(0)) % 360;
    }

    return h;
};
