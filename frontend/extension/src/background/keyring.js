/**
 * Keys — derived, used for one signature, dropped.
 *
 * The phrase never leaves the service worker, and no signer is cached: an
 * account is re-derived for the call that needs it, exactly as the site's
 * wallet does. That costs a few milliseconds and removes a live private key
 * from memory between signatures.
 *
 * EVM only. This is the surface a dapp talks to, and a dapp speaks EIP-1193;
 * Solana, Monero and the Bitcoin family live in the wallet on the site, where
 * they have screens instead of a provider.
 */
import { HDNodeWallet, Mnemonic, Wallet, getAddress, isAddress } from 'ethers';

/**
 * BIP-44 account 0, external chain, address `index` — MetaMask's default, and
 * the same path the site derives, so one phrase gives the same addresses in
 * both places.
 */
export const pathFor = (index) => `m/44'/60'/0'/0/${index}`;

export const normalisePhrase = (phrase) =>
    String(phrase ?? '')
        .trim()
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean)
        .join(' ');

export const isValidPhrase = (phrase) => {
    try {
        return Mnemonic.isValidMnemonic(normalisePhrase(phrase));
    } catch {
        return false;
    }
};

/** A new 12-word phrase from the platform CSPRNG, generated on this device. */
export const newPhrase = () => Wallet.createRandom().mnemonic.phrase;

const walletFor = (phrase, index) =>
    HDNodeWallet.fromPhrase(normalisePhrase(phrase), undefined, pathFor(index));

export const addressFor = (phrase, index) => walletFor(phrase, index).address;

export const checksum = (address) => (isAddress(address) ? getAddress(address) : address);

export const signMessage = async (phrase, index, message) =>
    walletFor(phrase, index).signMessage(message);

export const signTypedData = async (phrase, index, { domain, types, message }) => {
    // ethers refuses to sign an EIP-712 payload that still carries EIP712Domain
    // in `types`; every dapp sends it, so it is dropped rather than rejected.
    const { EIP712Domain: _ignored, ...rest } = types ?? {};

    return walletFor(phrase, index).signTypedData(domain, rest, message);
};

/** A fully populated transaction, signed offline and returned as raw hex. */
export const signTransaction = async (phrase, index, transaction) =>
    walletFor(phrase, index).signTransaction(transaction);
