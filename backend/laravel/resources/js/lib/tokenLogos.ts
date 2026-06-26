import { reactive } from 'vue';

// Token logos served from public/tokens/ (copied from the Ritual DEX), keyed by
// on-chain symbol. Used by both the Lending and Farm pages via <TokenIcon>.
//
// To add a missing one: drop the file in public/tokens/ and add a line here —
// both pages pick it up. Tokens with no entry render a lettered gradient avatar.
export const TOKEN_LOGOS: Record<string, string> = {
    WCYBER: '/tokens/cyberia.png',
    'CYBER.sol': '/tokens/CYBER.png',
    ASH: '/tokens/ash.png',
    RUB: '/tokens/rub.png',
    USDC: '/tokens/usdc.svg',
    USDT: '/tokens/usdt.svg',
    BTC: '/tokens/btc.svg',
    LTC: '/tokens/ltc.svg',
    SILVER: '/tokens/silver.png',
    SOL: '/tokens/sol.svg',
};

// Symbols whose <img> failed to load (404). Module-level so a single failure is
// remembered across every icon of that token on the page.
const failed = reactive<Record<string, boolean>>({});

export const logoFor = (symbol: string): string | undefined =>
    TOKEN_LOGOS[symbol];

export const showLogo = (symbol: string): boolean =>
    !!TOKEN_LOGOS[symbol] && !failed[symbol];

export const markLogoFailed = (symbol: string): void => {
    failed[symbol] = true;
};

// Deterministic hue per symbol for the gradient fallback avatar.
export const hueFor = (symbol: string): number => {
    let h = 0;

    for (const c of symbol) {
        h = (h * 31 + c.charCodeAt(0)) % 360;
    }

    return h;
};
