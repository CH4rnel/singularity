/** Local notification and sound preferences for the non-custodial wallet. */

export type WalletSound = 'error' | 'incoming' | 'message' | 'success';

export interface WalletPreferences {
    notifications: boolean;
    sounds: boolean;
}

export interface WalletAnnouncement {
    title: string;
    body: string;
    sound: WalletSound;
    tag?: string;
}

export type WalletNotificationPermission =
    | 'denied'
    | 'granted'
    | 'prompt'
    | 'unsupported';

const STORAGE_KEY = 'cyberia.wallet.preferences.v1';
const PREFERENCES_EVENT = 'cyberia:wallet-preferences';
const DEFAULTS: WalletPreferences = { notifications: false, sounds: true };

let audioContext: AudioContext | null = null;

export function readWalletPreferences(): WalletPreferences {
    try {
        const stored = JSON.parse(
            window.localStorage.getItem(STORAGE_KEY) ?? 'null',
        ) as Partial<WalletPreferences> | null;

        return {
            notifications: stored?.notifications === true,
            sounds: stored?.sounds !== false,
        };
    } catch {
        return { ...DEFAULTS };
    }
}

export function saveWalletPreferences(
    next: Partial<WalletPreferences>,
): WalletPreferences {
    const preferences = { ...readWalletPreferences(), ...next };

    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(preferences));
        window.dispatchEvent(
            new CustomEvent(PREFERENCES_EVENT, { detail: preferences }),
        );
    } catch {
        // A browser refusing storage still gets the setting for this call. The
        // UI reads it back and makes the refusal visible instead of crashing.
    }

    return preferences;
}

export function walletNotificationPermission(): WalletNotificationPermission {
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return 'unsupported';
    }

    return window.Notification.permission === 'default'
        ? 'prompt'
        : window.Notification.permission;
}

export async function enableWalletNotifications(): Promise<boolean> {
    const permission = walletNotificationPermission();

    if (permission === 'unsupported' || permission === 'denied') {
        saveWalletPreferences({ notifications: false });

        return false;
    }

    const granted =
        permission === 'granted' ||
        (await window.Notification.requestPermission()) === 'granted';

    saveWalletPreferences({ notifications: granted });

    return granted;
}

/**
 * Minimal synthesized cues: no remote files, no decoder and no sound that can
 * fail to ship in one of the native bundles.
 */
export function playWalletSound(sound: WalletSound, force = false): boolean {
    if (!force && !readWalletPreferences().sounds) {
        return false;
    }

    if (
        typeof window === 'undefined' ||
        typeof window.AudioContext !== 'function'
    ) {
        return false;
    }

    try {
        audioContext ??= new window.AudioContext();

        const now = audioContext.currentTime;
        const notes: Record<WalletSound, Array<[number, number, number]>> = {
            success: [
                [520, 0, 0.09],
                [780, 0.08, 0.16],
            ],
            incoming: [
                [420, 0, 0.08],
                [620, 0.07, 0.1],
                [920, 0.15, 0.15],
            ],
            message: [
                [680, 0, 0.07],
                [840, 0.06, 0.1],
            ],
            error: [
                [220, 0, 0.12],
                [160, 0.1, 0.2],
            ],
        };

        for (const [frequency, offset, duration] of notes[sound]) {
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            const start = now + offset;

            oscillator.type = sound === 'error' ? 'sawtooth' : 'sine';
            oscillator.frequency.setValueAtTime(frequency, start);
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.075, start + 0.012);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start(start);
            oscillator.stop(start + duration + 0.01);
        }

        return true;
    } catch {
        return false;
    }
}

export function showWalletNotification(
    announcement: Omit<WalletAnnouncement, 'sound'>,
    force = false,
): boolean {
    if (
        (!force && !readWalletPreferences().notifications) ||
        walletNotificationPermission() !== 'granted'
    ) {
        return false;
    }

    try {
        const notification = new window.Notification(announcement.title, {
            body: announcement.body,
            tag: announcement.tag,
            silent: true,
        });

        notification.onclick = () => {
            window.focus();
            notification.close();
        };

        return true;
    } catch {
        return false;
    }
}

export function announceWalletEvent(announcement: WalletAnnouncement): void {
    playWalletSound(announcement.sound);
    showWalletNotification(announcement);
}

export function subscribeWalletPreferences(
    listener: (preferences: WalletPreferences) => void,
): () => void {
    if (typeof window === 'undefined') {
        return () => {};
    }

    const handler = (event: Event): void => {
        listener((event as CustomEvent<WalletPreferences>).detail);
    };

    window.addEventListener(PREFERENCES_EVENT, handler);

    return () => window.removeEventListener(PREFERENCES_EVENT, handler);
}
