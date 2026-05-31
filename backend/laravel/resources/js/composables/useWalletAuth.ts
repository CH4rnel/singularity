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
        }>;
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
        detachEvmWallet,
        detachSolanaWallet,
    };
};

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}
