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

/**
 * The pending wipe lives here, not in a component. It used to belong to the
 * screen that copied, and leaving that screen cancelled it — so copying the
 * recovery phrase in Security and going back to the portfolio within 30 s left
 * the phrase on the clipboard for good. Only a newer copy replaces it.
 */
let pendingClear: ReturnType<typeof setTimeout> | null = null;

export const useSecureClipboard = (enabled = clipboardAutoClear) => {
    /** Key of whatever was copied last, so a button can show its own state. */
    const copied = ref<string | null>(null);

    let flashTimer: ReturnType<typeof setTimeout> | null = null;

    /** The "copied" flash belongs to this screen; the wipe does not. */
    const cancel = (): void => {
        if (flashTimer !== null) {
            clearTimeout(flashTimer);
            flashTimer = null;
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

        if (pendingClear !== null) {
            clearTimeout(pendingClear);
        }

        pendingClear = setTimeout(async () => {
            pendingClear = null;

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
