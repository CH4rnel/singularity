import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useSolanaWallet } from '@/composables/useSolanaWallet';
import { useWallet } from '@/composables/useWallet';
import { useWalletAuth } from '@/composables/useWalletAuth';

/**
 * Full wallet sign-in flow (connect → nonce → sign → verify → session),
 * shared by the header WalletMenu and the /login/web3 page. State is
 * module-level so both surfaces reflect one authentication attempt.
 */

const isAuthenticating = ref(false);
const error = ref<string | null>(null);

export const useWeb3Login = () => {
    const evmWallet = useWallet();
    const solanaWallet = useSolanaWallet();
    const walletAuth = useWalletAuth();

    const establishSession = (token: string): void => {
        router.post(
            '/login/web3',
            { token },
            {
                onError: (err: Record<string, string>) => {
                    error.value = err.message || 'Authentication failed';
                },
                onFinish: () => {
                    isAuthenticating.value = false;
                },
            },
        );
    };

    const loginWithEvm = async (providerId?: string): Promise<void> => {
        // Callers may bind both select and click events — collapse the pair.
        if (isAuthenticating.value) {
            return;
        }

        error.value = null;
        isAuthenticating.value = true;

        try {
            const address = await evmWallet.connect(providerId);

            if (!address) {
                error.value = evmWallet.error.value || 'Failed to connect';
                isAuthenticating.value = false;

                return;
            }

            const { nonce } = await walletAuth.generateNonce(address);
            const message = `Sign this message to authenticate with your wallet. Nonce: ${nonce}`;
            const signature = await evmWallet.signMessage(message);

            if (!signature) {
                error.value = 'Failed to sign message';
                isAuthenticating.value = false;

                return;
            }

            const { token } = await walletAuth.verifySignature(
                address,
                signature,
            );

            establishSession(token);
        } catch (err) {
            error.value =
                err instanceof Error ? err.message : 'Authentication failed';
            isAuthenticating.value = false;
        }
    };

    const loginWithSolana = async (providerId?: string): Promise<void> => {
        if (isAuthenticating.value) {
            return;
        }

        error.value = null;
        isAuthenticating.value = true;

        try {
            const address = await solanaWallet.connect(providerId);

            if (!address) {
                error.value = solanaWallet.error.value || 'Failed to connect';
                isAuthenticating.value = false;

                return;
            }

            const { nonce } = await walletAuth.generateSolanaNonce(address);
            const message = `Sign this message to authenticate with your wallet. Nonce: ${nonce}`;
            const signature = await solanaWallet.signMessage(message);

            if (!signature) {
                error.value = 'Failed to sign message';
                isAuthenticating.value = false;

                return;
            }

            const { token } = await walletAuth.verifySolanaSignature(
                address,
                signature,
            );

            establishSession(token);
        } catch (err) {
            error.value =
                err instanceof Error ? err.message : 'Authentication failed';
            isAuthenticating.value = false;
        }
    };

    return {
        isAuthenticating,
        error,
        loginWithEvm,
        loginWithSolana,
    };
};
