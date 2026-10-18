import assert from 'node:assert/strict';
import test, { mock } from 'node:test';
import { effectScope } from 'vue';
import { useSecureClipboard } from '@/composables/useSecureClipboard';

/**
 * The 30-second wipe of whatever the wallet copied.
 *
 * It used to belong to the screen that copied, and leaving that screen
 * cancelled it: copy the recovery phrase in Security, go back to the portfolio,
 * and the phrase stayed on the clipboard for the rest of the session.
 */

const fakeClipboard = () => {
    const state = { text: '' };

    globalThis.navigator ??= {};
    Object.defineProperty(globalThis.navigator, 'clipboard', {
        configurable: true,
        value: {
            writeText: async (value) => {
                state.text = value;
            },
            readText: async () => state.text,
        },
    });
    globalThis.document = { hasFocus: () => true };

    return state;
};

const settle = async () => {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
    }
};

test('the wipe still happens after the screen that copied is gone', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    const clipboard = fakeClipboard();
    const scope = effectScope();
    const { copy, clearAfterMs } = scope.run(() => useSecureClipboard());

    await copy('abandon abandon … about', 'phrase');
    assert.equal(clipboard.text, 'abandon abandon … about');

    scope.stop();
    mock.timers.tick(clearAfterMs);
    await settle();

    assert.equal(clipboard.text, '', 'the phrase must not outlive the 30 s');
    mock.timers.reset();
});

test('something the user copied afterwards is left alone', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    const clipboard = fakeClipboard();
    const scope = effectScope();
    const { copy, clearAfterMs } = scope.run(() => useSecureClipboard());

    await copy('0xabc', 'address');
    clipboard.text = 'their own note';
    mock.timers.tick(clearAfterMs);
    await settle();

    assert.equal(clipboard.text, 'their own note');
    scope.stop();
    mock.timers.reset();
});
