import assert from 'node:assert/strict';
import test from 'node:test';

const values = new Map();
const events = [];

globalThis.window = {
    localStorage: {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    },
    dispatchEvent: (event) => events.push(event),
    addEventListener: () => {},
    removeEventListener: () => {},
};
globalThis.CustomEvent = class CustomEvent {
    constructor(type, options) {
        this.type = type;
        this.detail = options?.detail;
    }
};

const {
    playWalletSound,
    readWalletPreferences,
    saveWalletPreferences,
    walletNotificationPermission,
} = await import('@/lib/wallet/notifications');

test('sounds start on while notifications remain an explicit choice', () => {
    values.clear();

    assert.deepEqual(readWalletPreferences(), {
        notifications: false,
        sounds: true,
    });
});

test('preferences are merged, persisted and announced locally', () => {
    values.clear();
    events.length = 0;

    saveWalletPreferences({ notifications: true });
    saveWalletPreferences({ sounds: false });

    assert.deepEqual(readWalletPreferences(), {
        notifications: true,
        sounds: false,
    });
    assert.deepEqual(events.at(-1).detail, {
        notifications: true,
        sounds: false,
    });
});

test('unsupported notification and audio APIs fail closed', () => {
    delete globalThis.window.Notification;

    assert.equal(walletNotificationPermission(), 'unsupported');
    assert.equal(playWalletSound('success', true), false);
});
