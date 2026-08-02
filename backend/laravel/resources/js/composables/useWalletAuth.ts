export const useWalletAuth = () => {
    const generateNonce = async (walletAddress: string) => {
        const response = await fetch('/api/wallet/nonce', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ wallet_address: walletAddress }),
        });

        if (!response.ok) {
            throw new Error('Failed to generate nonce');
        }

        return response.json() as Promise<{ nonce: string; message: string }>;
    };

    const verifySignature = async (
        walletAddress: string,
        signature: string,
    ) => {
        const response = await fetch('/api/wallet/verify', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ wallet_address: walletAddress, signature }),
        });

        if (!response.ok) {
            const error = await response.json();

            throw new Error(error.message || 'Authentication failed');
        }

        return response.json() as Promise<{
            message: string;
            user: {
                id: number;
                name: string;
                email: string;
                wallet_address: string;
            };
            token: string;
        }>;
    };

    const generateSolanaNonce = async (walletAddress: string) => {
        const response = await fetch('/api/solana-wallet/nonce', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ wallet_address: walletAddress }),
        });

        if (!response.ok) {
            throw new Error('Failed to generate nonce');
        }

        return response.json() as Promise<{ nonce: string; message: string }>;
    };

    const verifySolanaSignature = async (
        walletAddress: string,
        signature: string,
    ) => {
        const response = await fetch('/api/solana-wallet/verify', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                wallet_address: walletAddress,
                signature,
            }),
        });

        if (!response.ok) {
            const error = await response.json();

            throw new Error(error.message || 'Authentication failed');
        }

        return response.json() as Promise<{
            message: string;
            user: {
                id: number;
                name: string;
                email: string;
                solana_wallet_address: string;
            };
            token: string;
        }>;
    };

    const attachEvmWallet = async (
        walletAddress: string,
        signature: string,
    ) => {
        const response = await fetch('/wallets/evm/attach', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                wallet_address: walletAddress,
                signature,
            }),
        });

        if (!response.ok) {
            const error = await response.json();

            throw new Error(error.message || 'Failed to attach EVM wallet');
        }

        return response.json() as Promise<{
            message: string;
            wallet_address: string;
            merged: boolean;
        }>;
    };

    const attachSolanaWallet = async (
        walletAddress: string,
        signature: string,
    ) => {
        const response = await fetch('/wallets/solana/attach', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                wallet_address: walletAddress,
                signature,
            }),
        });

        if (!response.ok) {
            const error = await response.json();

            throw new Error(error.message || 'Failed to attach Solana wallet');
        }

        return response.json() as Promise<{
            message: string;
            solana_wallet_address: string;
            merged: boolean;
        }>;
    };

    /**
     * Save the user's native Monero address. Monero has no browser wallet and
     * no in-browser signing, so there is no nonce/signature step here: this is
     * a payout destination, not a login. The server re-checks the Keccak
     * checksum (ValidMoneroAddress) before storing it.
     */
    const attachMoneroWallet = async (walletAddress: string) => {
        const response = await fetch('/wallets/monero/attach', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ wallet_address: walletAddress }),
        });

        const payload = await response.json();

        if (!response.ok) {
            throw new Error(
                payload.errors?.wallet_address?.[0] ||
                    payload.message ||
                    'Failed to save Monero wallet',
            );
        }

        return payload as {
            message: string;
            monero_wallet_address: string;
            kind: string | null;
        };
    };

    const detachMoneroWallet = async () => {
        const response = await fetch('/wallets/monero/detach', {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Failed to remove Monero wallet');
        }

        return response.json() as Promise<{ message: string }>;
    };

    const detachEvmWallet = async () => {
        const response = await fetch('/wallets/evm/detach', {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Failed to detach EVM wallet');
        }

        return response.json() as Promise<{ message: string }>;
    };

    const detachSolanaWallet = async () => {
        const response = await fetch('/wallets/solana/detach', {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Failed to detach Solana wallet');
        }

        return response.json() as Promise<{ message: string }>;
    };

    return {
        generateNonce,
        verifySignature,
        generateSolanaNonce,
        verifySolanaSignature,
        attachEvmWallet,
        attachSolanaWallet,
        attachMoneroWallet,
        detachEvmWallet,
        detachSolanaWallet,
        detachMoneroWallet,
    };
};

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
