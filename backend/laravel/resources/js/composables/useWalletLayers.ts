import { computed, onMounted, ref } from 'vue';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { loadDailyBoard, signInWithWallet } from '@/lib/wallet/daily';
import type { DailyBoard } from '@/lib/wallet/daily';
import { discoverLayer, foundKeys } from '@/lib/wallet/layers';
import type { LayerId, SecretClaim } from '@/lib/wallet/layers';

/**
 * What the three layer screens share: one board, one sign-in, and the find
 * that opening each layer is.
 *
 * The board is module-level so walking from layer 02 to 03 and back does not
 * ask the server three times what it already said.
 */
const board = ref<DailyBoard | null>(null);

export function useWalletLayers(wallet: MultiWallet, layer: LayerId) {
    const loading = ref(board.value === null);
    const failure = ref<string | null>(null);
    const signingIn = ref(false);
    /** The find that just landed, for the one line that says so. */
    const fresh = ref<SecretClaim | null>(null);

    const apply = (next: DailyBoard): void => {
        board.value = next;
    };

    const read = async (): Promise<void> => {
        failure.value = null;

        try {
            board.value = await loadDailyBoard();

            const found = await discoverLayer(layer, board.value);

            if (found !== null) {
                board.value = found;
                fresh.value = found.claim.fresh ? found.claim : null;
            }
        } catch (error) {
            failure.value =
                error instanceof Error ? error.message : String(error);
        } finally {
            loading.value = false;
        }
    };

    const address = computed(
        () =>
            wallet.accounts.value.find((entry) => entry.chain === 'cyberia')
                ?.address ?? null,
    );

    /** The site's own login challenge, signed by this wallet's key. */
    const signIn = async (): Promise<void> => {
        if (address.value === null || signingIn.value) {
            return;
        }

        signingIn.value = true;
        failure.value = null;

        try {
            await signInWithWallet(address.value, (message) =>
                wallet.signMessage('cyberia', message),
            );
            await read();
        } catch (error) {
            failure.value =
                error instanceof Error ? error.message : String(error);
        } finally {
            signingIn.value = false;
        }
    };

    const signedIn = computed(() => board.value?.signedIn === true);
    const level = computed(() => board.value?.standing?.level ?? null);
    const found = computed(() => foundKeys(board.value?.secrets));

    onMounted(read);

    return {
        board,
        loading,
        failure,
        signingIn,
        fresh,
        signedIn,
        level,
        found,
        address,
        apply,
        read,
        signIn,
    };
}
