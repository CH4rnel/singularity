import { isTelegramMiniApp, telegramWebApp } from '@/lib/telegram';
import type { SealedVaultRecord } from '@/lib/wallet';
import { sessionCall } from '@/lib/wallet/session';

/**
 * Sync: one wallet across the site, Telegram and the apps.
 *
 * The profile keeps the vault sealed under a sync password
 * (`useMultiWallet().sealForProfile`); these are the calls that store, read
 * and drop that copy, and the one that signs a Telegram launch in so the
 * profile is there to read it from.
 */
export type SyncBackup = {
    address: string;
    payload: SealedVaultRecord;
    updatedAt: string | null;
};

export const readBackup = async (): Promise<SyncBackup | null> =>
    (await sessionCall<{ backup: SyncBackup | null }>('/api/wallet/sync'))
        .backup;

/** Store this wallet; `replace` is needed when the profile keeps another. */
export const storeBackup = (
    address: string,
    payload: SealedVaultRecord,
    replace = false,
): Promise<{ address: string }> =>
    sessionCall('/api/wallet/sync', {
        method: 'PUT',
        body: JSON.stringify({ address, payload, replace }),
    });

export const dropBackup = (): Promise<unknown> =>
    sessionCall('/api/wallet/sync', { method: 'DELETE' });

/**
 * Inside Telegram the launch data is the login: Telegram signs it, Laravel
 * checks the signature and opens the profile of that Telegram account.
 * Returns whether a session now exists.
 */
export const signInWithTelegram = async (): Promise<boolean> => {
    const initData = telegramWebApp()?.initData;

    if (!isTelegramMiniApp() || !initData) {
        return false;
    }

    await sessionCall('/login/telegram-webapp', {
        method: 'POST',
        body: JSON.stringify({ init_data: initData }),
    });

    return true;
};
