import { Currency } from '@uniswap/sdk';
import { WrappedTokenInfo } from 'state/lists/hooks';
import { WrappedTokenInfo as V3WrappedTokenInfo } from 'state/lists/v3/wrappedTokenInfo';

export const getTokenLogoURL = (address: string, tokenList?: any) => {
  const logoExtensions = ['.png', '.webp', '.jpeg', '.jpg', '.svg'];
  return logoExtensions
    .map((ext) => {
      try {
        // eslint-disable-next-line @typescript-eslint/no-var-requires
        const image = require(`../assets/tokenLogo/${address.toLowerCase()}${ext}`);
        return image;
      } catch (e) {
        return;
      }
    })
    .concat([tokenList?.[address]?.tokenInfo?.logoURI])
    .filter((url) => !!url);
};

/**
 * Resolve a single absolute URL for a token logo, suitable for
 * `wallet_watchAsset` (MetaMask requires an absolute HTTP(S) URL).
 *
 * Priority:
 *   1. `logoURI` from the token list entry (WrappedTokenInfo).
 *   2. Bundled asset under `src/assets/tokenLogo/{address}.{ext}`.
 */
export const getMetamaskTokenImage = (
  currency: Currency | undefined,
): string => {
  if (!currency) return '';

  let logoURI: string | undefined;

  if (
    currency instanceof WrappedTokenInfo ||
    currency instanceof V3WrappedTokenInfo
  ) {
    logoURI =
      (currency as any).logoURI ?? (currency as any).tokenInfo?.logoURI;
  }

  if (!logoURI) {
    const address = (currency as any).address as string | undefined;
    if (address) {
      const logos = getTokenLogoURL(address);
      if (logos.length > 0) logoURI = logos[0];
    }
  }

  if (!logoURI) return '';

  if (typeof window !== 'undefined') {
    try {
      return new URL(logoURI, window.location.origin).toString();
    } catch (e) {
      return logoURI;
    }
  }
  return logoURI;
};
