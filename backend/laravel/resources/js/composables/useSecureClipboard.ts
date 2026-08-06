import { onScopeDispose, ref } from 'vue';

/**
 * Copying that does not leave an address or a seed phrase sitting in the
 * clipboard for the rest of the session.
 *
 * Clearing is best-effort and the UI should say so: the Clipboard API only
 * lets a focused document write, so a user who copies and immediately switches
 * to another app keeps the value until they come back. That still removes the
 * common case — a wallet copied, a browser left open on a shared machine.
 */

const CLEAR_AFTER_MS = 30_000;

/**
 * Shared preference, so the switch in Security governs every copy button on
 * the page rather than only the one next to it.
 */
export const clipboardAutoClear = ref(true);

export const useSecureClipboard = (enabled = clipboardAutoClear) => {
    /** Key of whatever was copied last, so a button can show its own state. */
    const copied = ref<string | null>(null);

    let flashTimer: ReturnType<typeof setTimeout> | null = null;
    let clearTimer: ReturnType<typeof setTimeout> | null = null;

    const cancel = (): void => {
        if (flashTimer !== null) {
            clearTimeout(flashTimer);
            flashTimer = null;
        }

        if (clearTimer !== null) {
            clearTimeout(clearTimer);
            clearTimer = null;
        }
    };

    const copy = async (value: string, key = value): Promise<void> => {
        await navigator.clipboard.writeText(value);

        cancel();
        copied.value = key;
        flashTimer = setTimeout(() => {
            copied.value = null;
        }, 2_000);

        if (!enabled.value) {
            return;
        }

        clearTimer = setTimeout(async () => {
            try {
                // Only wipe what we put there: if the user has copied
                // something else since, that is theirs to keep.
                if (
                    document.hasFocus() &&
                    (await navigator.clipboard.readText()) === value
                ) {
                    await navigator.clipboard.writeText('');
                }
            } catch {
                // Denied permission or an unfocused document — nothing to do
                // but leave the clipboard alone.
            }
        }, CLEAR_AFTER_MS);
    };

    onScopeDispose(cancel);

    return { copied, copy, clearAfterMs: CLEAR_AFTER_MS };
};
