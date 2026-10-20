import { computed, ref } from 'vue';
import {
    BLOCKLIST_KEY,
    isBlocked,
    parseBlocklist,
    withBlocked,
    withoutBlocked,
} from '@/lib/wallet/blocklist';

/**
 * The device's blocklist as one shared reactive list (`lib/wallet/blocklist.ts`).
 *
 * Shared at module level so the feed, the chat and the mailbox that rings the
 * badge agree the moment somebody is blocked: a block that still rang the
 * toast would be a block in name only.
 */

const read = (): string[] => {
    try {
        return parseBlocklist(
            typeof window === 'undefined'
                ? null
                : window.localStorage.getItem(BLOCKLIST_KEY),
        );
    } catch {
        return [];
    }
};

const blocked = ref<string[]>(read());

const write = (next: string[]): void => {
    blocked.value = next;

    try {
        window.localStorage.setItem(BLOCKLIST_KEY, JSON.stringify(next));
    } catch {
        // Storage refused (private window, quota): the block still holds for
        // this session, which is the part the person is looking at.
    }
};

export function useWalletBlocklist() {
    return {
        blocked: computed(() => blocked.value),
        isBlocked: (address: string | null | undefined): boolean =>
            isBlocked(blocked.value, address),
        block: (address: string): void =>
            write(withBlocked(blocked.value, address)),
        unblock: (address: string): void =>
            write(withoutBlocked(blocked.value, address)),
        unblockAll: (): void => write([]),
    };
}
