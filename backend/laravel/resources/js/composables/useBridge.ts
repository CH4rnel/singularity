import {
    getAssociatedTokenAddress,
    getAccount,
    createTransferInstruction,
    createAssociatedTokenAccountInstruction,
    TOKEN_PROGRAM_ID,
} from '@solana/spl-token';
import {
    Connection,
    PublicKey,
    SystemProgram,
    Transaction,
} from '@solana/web3.js';
import {
    BrowserProvider,
    Contract,
    formatUnits,
    parseUnits,
    JsonRpcProvider,
    Network,
} from 'ethers';
import { ref } from 'vue';

import type { BridgeChain } from '@/lib/addressValidation';
import type { DestinationCapacity } from '@/lib/bridgeCapacity';
import {
    LOADING_CAPACITY,
    parseCapacity,
    unreadableCapacity,
} from '@/lib/bridgeCapacity';
import { bridgeChainInfo, tokenOnChain } from '@/lib/bridgeConfig';
import { BRIDGE_TOKENS } from '@/lib/bridgeTokens';
import type { BridgeTokenInfo, BridgeTokenSymbol } from '@/lib/bridgeTokens';
import { getSelectedEvmProvider } from '@/lib/evmProvider';
import { confirmSignature, solanaRpcUrl } from '@/lib/solanaRpc';
import { getSelectedSolanaTransactionProvider } from '@/lib/solanaWalletProvider';
import {
    fetchTonJettonBalance,
    fetchTonNativeBalance,
    fromRawUnits,
} from '@/lib/tonBridge';

const CYBERIA_RPC = 'https://rpc.cyberia.church';
const CYBERIA_CHAIN_ID = 49406;
const cyberiaNetwork = new Network('cyberia', CYBERIA_CHAIN_ID);

function getCyberiaProvider(): JsonRpcProvider {
    return new JsonRpcProvider(CYBERIA_RPC, cyberiaNetwork, {
        staticNetwork: cyberiaNetwork,
    });
}

const TOKEN_EXTENSIONS_PROGRAM_ID =
    'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb';

// This app's relay, not a keyed endpoint. The key it used to carry was in
// every visitor's bundle for anyone to spend, and the public cluster it could
// fall back to refuses browsers outright. See `@/lib/solanaRpc`.
const SOLANA_RPC = solanaRpcUrl();
const SOLANA_NATIVE_MINT = new PublicKey(
    'E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump',
);
// const SOLANA_NATIVE_MINT = new PublicKey('6SvS85B6ufx8YA6wjGNdRvGZ4RbYUhXQjnaLgEbcfH8o');
const SOLANA_NATIVE_DECIMALS = 6;
const SOLANA_TX_SEND_OPTIONS = {
    preflightCommitment: 'confirmed' as const,
    maxRetries: 5,
};

// Hot wallet — relayer's Solana address (receives deposits, sends withdrawals)
const BRIDGE_HOT_WALLET = new PublicKey(
    'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
);

// EVM (Cyberia) — only CYBER.sol (bridged token)
// TODO: Update after redeploying EVM contracts with deploy-all.ts
const BRIDGE_ADDRESS = '0xEf2c8E731006EEDD8F44f5Ea03A389635BB28f90';
const CYBERSOL_ERC20_ADDRESS = '0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA';

const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function decimals() view returns (uint8)',
    'function transfer(address to, uint256 amount) returns (bool)',
];

const BRIDGE_ABI = [
    'function redeemCyberSol(uint256 amount, bytes32 solanaRecipient)',
    'event RedeemCyberSol(address indexed sender, uint256 amount, bytes32 solanaRecipient, uint64 nonce)',
];

// State
const cyberSolBalance = ref<string | null>(null);
const solanaCyberBalance = ref<string | null>(null);
const cyberSolDecimals = ref(18);
const wrongNetwork = ref(false);
const evmNativeBalances = ref<Record<string, string | null>>({});
const evmNativeMaxAmounts = ref<Record<string, string | null>>({});

// Token-keyed balances, populated by fetchTokenBalance.
const tokenBalances = ref<Record<string, string | null>>({});

// Live per-destination withdrawal capacity, keyed by `${direction}:${symbol}`.
// Never a bare number: the state says whether there IS a ceiling, and a read
// that failed is `unavailable` rather than an absent limit. See
// `@/lib/bridgeCapacity`.
const destinationCapacities = ref<Record<string, DestinationCapacity>>({});

const capacityKey = (direction: string, symbol: string): string =>
    `${direction}:${symbol}`;

const balanceKey = (
    symbol: BridgeTokenSymbol,
    chain: 'evm' | 'solana' | 'ton',
): string => `${symbol}:${chain}`;

const tokenProgramId = (token: BridgeTokenInfo): PublicKey =>
    token.solanaTokenProgram === 'token-2022'
        ? new PublicKey(TOKEN_EXTENSIONS_PROGRAM_ID)
        : TOKEN_PROGRAM_ID;

export const useBridge = () => {
    const fetchEvmNativeBalance = async (
        chainKey: BridgeChain,
        address: string,
    ): Promise<void> => {
        const chain = bridgeChainInfo(chainKey);

        if (!chain?.rpcUrl || chain.evmChainId === null) {
            evmNativeBalances.value = {
                ...evmNativeBalances.value,
                [chainKey]: null,
            };
            evmNativeMaxAmounts.value = {
                ...evmNativeMaxAmounts.value,
                [chainKey]: null,
            };

            return;
        }

        try {
            const network = new Network(chain.key, chain.evmChainId);
            const provider = new JsonRpcProvider(chain.rpcUrl, network, {
                staticNetwork: network,
            });
            const [balance, feeData] = await Promise.all([
                provider.getBalance(address),
                provider.getFeeData(),
            ]);
            const decimals = chain.nativeCurrency?.decimals ?? 18;
            const gasPrice = feeData.maxFeePerGas ?? feeData.gasPrice ?? 0n;
            const gasReserve = (21_000n * gasPrice * 110n) / 100n;
            const maxAmount = balance > gasReserve ? balance - gasReserve : 0n;

            evmNativeBalances.value = {
                ...evmNativeBalances.value,
                [chainKey]: formatUnits(balance, decimals),
            };
            evmNativeMaxAmounts.value = {
                ...evmNativeMaxAmounts.value,
                [chainKey]: formatUnits(maxAmount, decimals),
            };
        } catch (e) {
            console.error('[bridge] fetchEvmNativeBalance failed', e);
            evmNativeBalances.value = {
                ...evmNativeBalances.value,
                [chainKey]: null,
            };
            evmNativeMaxAmounts.value = {
                ...evmNativeMaxAmounts.value,
                [chainKey]: null,
            };
        }
    };

    const getEvmNativeBalance = (chainKey: BridgeChain): string | null =>
        evmNativeBalances.value[chainKey] ?? null;

    const getEvmNativeMaxAmount = (chainKey: BridgeChain): string | null =>
        evmNativeMaxAmounts.value[chainKey] ?? null;

    // ---------------------------------------------------------------
    //  EVM balance — read directly from Cyberia RPC, no MetaMask needed
    // ---------------------------------------------------------------

    const fetchCyberSolBalance = async (address: string): Promise<void> => {
        try {
            console.log('[bridge] fetchCyberSolBalance: querying', {
                address,
                contract: CYBERSOL_ERC20_ADDRESS,
                rpc: CYBERIA_RPC,
            });
            const provider = getCyberiaProvider();
            const contract = new Contract(
                CYBERSOL_ERC20_ADDRESS,
                ERC20_ABI,
                provider,
            );
            const bal = (await contract.balanceOf(address)) as bigint;
            const dec = (await contract.decimals()) as number;
            console.log('[bridge] fetchCyberSolBalance: result', {
                bal: bal.toString(),
                dec,
            });
            cyberSolDecimals.value = dec;
            cyberSolBalance.value = formatUnits(bal, dec);
        } catch (e) {
            console.error('[bridge] fetchCyberSolBalance failed:', e);
            cyberSolBalance.value = null;
        }
    };

    // ---------------------------------------------------------------
    //  Solana balance
    // ---------------------------------------------------------------

    const fetchSolanaCyberBalance = async (
        walletAddress: string,
    ): Promise<void> => {
        try {
            const res = await fetch(SOLANA_RPC, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    jsonrpc: '2.0',
                    id: 1,
                    method: 'getTokenAccountsByOwner',
                    params: [
                        walletAddress,
                        { mint: SOLANA_NATIVE_MINT.toBase58() },
                        { encoding: 'jsonParsed' },
                    ],
                }),
            });
            const json = await res.json();
            console.log(
                '[bridge] raw response:',
                JSON.stringify(json, null, 2),
            );

            const accounts = json.result?.value ?? [];

            if (accounts.length === 0) {
                solanaCyberBalance.value = '0';

                return;
            }

            const amount =
                accounts[0].account.data.parsed.info.tokenAmount.uiAmount ??
                '0';
            console.log('[bridge] account fetched:', { amount });
            solanaCyberBalance.value = amount.toString();
        } catch (e) {
            console.error('[bridge] fetchSolanaCyberBalance failed:', e);
            solanaCyberBalance.value = '0';
        }
    };

    // ---------------------------------------------------------------
    //  EVM -> Solana: burn CYBER.sol ERC20, relayer unlocks SPL on Solana
    // ---------------------------------------------------------------

    /**
     * Switch (or add + switch) the selected EVM wallet to an arbitrary EVM chain described by
     * the server bridge config. Chain params all come from config/bridge.php,
     * so new EVM chains need no frontend changes.
     */
    const ensureNetwork = async (chainKey: BridgeChain): Promise<boolean> => {
        const injected = getSelectedEvmProvider();
        const chain = bridgeChainInfo(chainKey);

        if (!injected || !chain?.evmChainId) {
            return false;
        }

        const chainIdHexTarget = '0x' + chain.evmChainId.toString(16);

        try {
            const chainIdHex = (await injected.request({
                method: 'eth_chainId',
            })) as string;

            if (parseInt(chainIdHex, 16) === chain.evmChainId) {
                wrongNetwork.value = false;

                return true;
            }

            try {
                await injected.request({
                    method: 'wallet_switchEthereumChain',
                    params: [{ chainId: chainIdHexTarget }],
                });
                wrongNetwork.value = false;

                return true;
            } catch {
                try {
                    const explorerOrigin = chain.explorerTx
                        ? new URL(chain.explorerTx.replace('{hash}', 'x'))
                              .origin
                        : null;

                    await injected.request({
                        method: 'wallet_addEthereumChain',
                        params: [
                            {
                                chainId: chainIdHexTarget,
                                chainName: chain.label,
                                nativeCurrency: chain.nativeCurrency ?? {
                                    name: chain.label,
                                    symbol: chain.label.slice(0, 5),
                                    decimals: 18,
                                },
                                rpcUrls: chain.rpcUrl ? [chain.rpcUrl] : [],
                                blockExplorerUrls: explorerOrigin
                                    ? [explorerOrigin]
                                    : [],
                            },
                        ],
                    });
                    wrongNetwork.value = false;

                    return true;
                } catch {
                    wrongNetwork.value = true;

                    return false;
                }
            }
        } catch {
            wrongNetwork.value = true;

            return false;
        }
    };

    const ensureCyberiaNetwork = (): Promise<boolean> =>
        ensureNetwork('cyberia');

    const redeemCyberSolOnEvm = async (
        amount: string,
        solanaRecipientBase58: string,
    ): Promise<{ txHash: string; nonce: number } | null> => {
        const injected = getSelectedEvmProvider();

        if (!injected) {
            return null;
        }

        if (!(await ensureCyberiaNetwork())) {
            throw new Error('Please switch to Cyberia network');
        }

        const provider = new BrowserProvider(injected);
        const signer = await provider.getSigner();
        const amountWei = parseUnits(String(amount), cyberSolDecimals.value);
        const solRecipient = solanaBase58ToBytes32(solanaRecipientBase58);
        const bridge = new Contract(BRIDGE_ADDRESS, BRIDGE_ABI, signer);
        const tx = await bridge.redeemCyberSol(amountWei, solRecipient);
        const receipt = await tx.wait();
        const nonce = parseEvmNonce(receipt);

        return { txHash: receipt.hash, nonce };
    };

    // ---------------------------------------------------------------
    //  Solana -> EVM: SPL transfer to hot wallet, relayer mints ERC20 on EVM
    // ---------------------------------------------------------------

    const lockNativeOnSolana = async (
        amount: string,
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        _evmRecipientHex: string,
    ): Promise<{ txHash: string; nonce: number } | null> => {
        const solana = getSelectedSolanaTransactionProvider('mainnet');

        if (!solana?.publicKey) {
            throw new Error('Solana wallet not connected');
        }

        const connection = new Connection(SOLANA_RPC, 'confirmed');
        const userPubkey = new PublicKey(solana.publicKey.toBase58());
        const TOKEN_EXT_PROGRAM = new PublicKey(TOKEN_EXTENSIONS_PROGRAM_ID);

        const amountRaw = BigInt(
            Math.round(parseFloat(amount) * 10 ** SOLANA_NATIVE_DECIMALS),
        );

        const userAta = await getAssociatedTokenAddress(
            SOLANA_NATIVE_MINT,
            userPubkey,
            false, // allowOwnerOffCurve
            TOKEN_EXT_PROGRAM, // ← Token-2022
        );
        const hotWalletAta = await getAssociatedTokenAddress(
            SOLANA_NATIVE_MINT,
            BRIDGE_HOT_WALLET,
            false,
            TOKEN_EXT_PROGRAM,
        );

        const tx = new Transaction();

        // Создаём ATA hot wallet если не существует
        try {
            await getAccount(
                connection,
                hotWalletAta,
                'confirmed',
                TOKEN_EXT_PROGRAM,
            );
        } catch {
            tx.add(
                createAssociatedTokenAccountInstruction(
                    userPubkey,
                    hotWalletAta,
                    BRIDGE_HOT_WALLET,
                    SOLANA_NATIVE_MINT,
                    TOKEN_EXT_PROGRAM, // ← Token-2022
                ),
            );
        }

        // Transfer: userAta → hotWalletAta
        tx.add(
            createTransferInstruction(
                userAta,
                hotWalletAta,
                userPubkey,
                amountRaw,
                [], // multiSigners
                TOKEN_EXT_PROGRAM, // ← ЭТОГО и не хватало!
            ),
        );

        const { blockhash, lastValidBlockHeight } =
            await connection.getLatestBlockhash('confirmed');
        tx.recentBlockhash = blockhash;
        tx.feePayer = userPubkey;

        const { signature } = await solana.signAndSendTransaction(
            tx,
            SOLANA_TX_SEND_OPTIONS,
        );

        try {
            await confirmSignature(connection, signature, {
                lastValidBlockHeight,
            });
        } catch (error) {
            const status = await connection.getSignatureStatus(signature, {
                searchTransactionHistory: true,
            });

            if (
                status.value?.confirmationStatus === 'confirmed' ||
                status.value?.confirmationStatus === 'finalized'
            ) {
                return { txHash: signature, nonce: 0 };
            }

            if (
                error instanceof Error &&
                error.message.includes('block height exceeded')
            ) {
                throw new Error(
                    'Solana transaction expired before confirmation. Please try again.',
                );
            }

            throw error;
        }

        return { txHash: signature, nonce: 0 };
    };

    // ---------------------------------------------------------------
    //  Multi-token (USDC/USDT/...) — direct transfer model
    // ---------------------------------------------------------------

    const fetchTokenBalanceEvm = async (
        symbol: BridgeTokenSymbol,
        address: string,
        chainKey: BridgeChain = 'cyberia',
    ): Promise<void> => {
        const token = tokenOnChain(symbol, chainKey);
        const chain = bridgeChainInfo(chainKey);
        const key = balanceKey(symbol, 'evm');

        try {
            if (
                !token?.address ||
                !chain?.rpcUrl ||
                chain.evmChainId === null
            ) {
                throw new Error(`${symbol} is not configured on ${chainKey}`);
            }

            const network = new Network(chain.key, chain.evmChainId);
            const provider = new JsonRpcProvider(chain.rpcUrl, network, {
                staticNetwork: network,
            });
            const contract = new Contract(token.address, ERC20_ABI, provider);
            const bal = (await contract.balanceOf(address)) as bigint;

            tokenBalances.value = {
                ...tokenBalances.value,
                [key]: formatUnits(bal, token.decimals),
            };
        } catch (e) {
            console.error('[bridge] fetchTokenBalanceEvm failed', e);
            tokenBalances.value = { ...tokenBalances.value, [key]: null };
        }
    };

    const fetchTokenBalanceSolana = async (
        symbol: BridgeTokenSymbol,
        owner: string,
    ): Promise<void> => {
        const token = BRIDGE_TOKENS[symbol];
        const key = balanceKey(symbol, 'solana');

        // Native SOL: lamport balance of the wallet itself, no token account.
        if (tokenOnChain(symbol, 'solana')?.native) {
            try {
                const connection = new Connection(SOLANA_RPC, 'confirmed');
                const lamports = await connection.getBalance(
                    new PublicKey(owner),
                );

                tokenBalances.value = {
                    ...tokenBalances.value,
                    [key]: (lamports / 1e9).toString(),
                };
            } catch (e) {
                console.error('[bridge] fetchTokenBalanceSolana failed', e);
                tokenBalances.value = { ...tokenBalances.value, [key]: '0' };
            }

            return;
        }

        try {
            const res = await fetch(SOLANA_RPC, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    jsonrpc: '2.0',
                    id: 1,
                    method: 'getTokenAccountsByOwner',
                    params: [
                        owner,
                        { mint: token.solanaMint },
                        { encoding: 'jsonParsed' },
                    ],
                }),
            });
            const json = await res.json();
            const accounts = json.result?.value ?? [];

            const ui =
                accounts[0]?.account?.data?.parsed?.info?.tokenAmount?.uiAmount;

            tokenBalances.value = {
                ...tokenBalances.value,
                [key]: ui != null ? ui.toString() : '0',
            };
        } catch (e) {
            console.error('[bridge] fetchTokenBalanceSolana failed', e);
            tokenBalances.value = { ...tokenBalances.value, [key]: '0' };
        }
    };

    const getTokenBalance = (
        symbol: BridgeTokenSymbol,
        chain: 'evm' | 'solana' | 'ton',
    ): string | null => tokenBalances.value[balanceKey(symbol, chain)] ?? null;

    /**
     * TON-side balance of the connected TON Connect wallet: native Toncoin or
     * a jetton, depending on the token's TON entry. `owner` is the raw
     * ("0:hex") account address.
     */
    const fetchTokenBalanceTon = async (
        symbol: BridgeTokenSymbol,
        owner: string,
    ): Promise<void> => {
        const token = tokenOnChain(symbol, 'ton');
        const key = balanceKey(symbol, 'ton');

        try {
            if (!token) {
                throw new Error(`${symbol} is not configured on TON`);
            }

            if (token.native) {
                tokenBalances.value = {
                    ...tokenBalances.value,
                    [key]: await fetchTonNativeBalance(owner),
                };

                return;
            }

            if (!token.master) {
                throw new Error(`${symbol} has no jetton master configured`);
            }

            const { balance } = await fetchTonJettonBalance(
                owner,
                token.master,
            );

            tokenBalances.value = {
                ...tokenBalances.value,
                [key]: fromRawUnits(balance, token.decimals),
            };
        } catch (e) {
            console.error('[bridge] fetchTokenBalanceTon failed', e);
            tokenBalances.value = { ...tokenBalances.value, [key]: null };
        }
    };

    /**
     * EVM → Solana direct transfer: ERC20.transfer(relayerEvm, amount).
     * Returns the EVM tx hash. Used for USDC/USDT (not the CYBER bridge contract path).
     */
    const erc20TransferToRelayer = async (
        symbol: BridgeTokenSymbol,
        amount: string,
        relayerEvmAddress: string,
        chainKey: BridgeChain = 'cyberia',
    ): Promise<{ txHash: string; nonce: number } | null> => {
        const injected = getSelectedEvmProvider();

        if (!injected) {
            return null;
        }

        if (!(await ensureNetwork(chainKey))) {
            throw new Error(
                `Please switch to the ${bridgeChainInfo(chainKey)?.label ?? chainKey} network`,
            );
        }

        const token = tokenOnChain(symbol, chainKey);

        if (!token?.address) {
            throw new Error(`${symbol} is not configured on ${chainKey}`);
        }

        const provider = new BrowserProvider(injected);
        const signer = await provider.getSigner();
        const contract = new Contract(token.address, ERC20_ABI, signer);
        const amountRaw = parseUnits(String(amount), token.decimals);

        const tx = await contract.transfer(relayerEvmAddress, amountRaw);
        const receipt = await tx.wait();

        return { txHash: receipt.hash, nonce: 0 };
    };

    /**
     * Native-coin deposit to the relayer EOA on an EVM chain (e.g. BNB on
     * BSC): plain value transfer signed with the selected EVM wallet.
     */
    const nativeTransferToRelayer = async (
        chainKey: BridgeChain,
        amount: string,
        relayerEvmAddress: string,
    ): Promise<{ txHash: string; nonce: number } | null> => {
        const injected = getSelectedEvmProvider();

        if (!injected) {
            return null;
        }

        if (!(await ensureNetwork(chainKey))) {
            throw new Error(
                `Please switch to the ${bridgeChainInfo(chainKey)?.label ?? chainKey} network`,
            );
        }

        const provider = new BrowserProvider(injected);
        const signer = await provider.getSigner();
        const decimals =
            bridgeChainInfo(chainKey)?.nativeCurrency?.decimals ?? 18;
        const symbol =
            bridgeChainInfo(chainKey)?.nativeCurrency?.symbol ?? 'native coin';
        const amountRaw = parseUnits(String(amount), decimals);
        const signerAddress = await signer.getAddress();
        const [balance, feeData] = await Promise.all([
            provider.getBalance(signerAddress),
            provider.getFeeData(),
        ]);
        const gasPrice = feeData.maxFeePerGas ?? feeData.gasPrice ?? 0n;
        const estimatedFee = 21_000n * gasPrice;

        if (amountRaw + estimatedFee > balance) {
            throw new Error(
                `Insufficient ${symbol} balance. Available: ${formatUnits(balance, decimals)} ${symbol} (gas fee is paid separately).`,
            );
        }

        const tx = await signer.sendTransaction({
            to: relayerEvmAddress,
            value: amountRaw,
        });
        const receipt = await tx.wait();

        if (!receipt) {
            return null;
        }

        return { txHash: receipt.hash, nonce: 0 };
    };

    /**
     * Solana → EVM direct transfer: SPL transfer to the bridge hot wallet's ATA for the token's mint.
     * Returns the Solana signature. Used for USDC/USDT.
     */
    const splTransferToHotWallet = async (
        symbol: BridgeTokenSymbol,
        amount: string,
    ): Promise<{ txHash: string; nonce: number } | null> => {
        const solana = getSelectedSolanaTransactionProvider('mainnet');

        if (!solana?.publicKey) {
            throw new Error('Solana wallet not connected');
        }

        const token = BRIDGE_TOKENS[symbol];
        const mint = new PublicKey(token.solanaMint);
        const programId = tokenProgramId(token);

        const connection = new Connection(SOLANA_RPC, 'confirmed');
        const userPubkey = new PublicKey(solana.publicKey.toBase58());

        const amountRaw = BigInt(
            Math.round(parseFloat(amount) * 10 ** token.solanaDecimals),
        );

        const userAta = await getAssociatedTokenAddress(
            mint,
            userPubkey,
            false,
            programId,
        );
        const hotWalletAta = await getAssociatedTokenAddress(
            mint,
            BRIDGE_HOT_WALLET,
            false,
            programId,
        );

        const tx = new Transaction();

        try {
            await getAccount(connection, hotWalletAta, 'confirmed', programId);
        } catch {
            tx.add(
                createAssociatedTokenAccountInstruction(
                    userPubkey,
                    hotWalletAta,
                    BRIDGE_HOT_WALLET,
                    mint,
                    programId,
                ),
            );
        }

        tx.add(
            createTransferInstruction(
                userAta,
                hotWalletAta,
                userPubkey,
                amountRaw,
                [],
                programId,
            ),
        );

        const { blockhash, lastValidBlockHeight } =
            await connection.getLatestBlockhash('confirmed');
        tx.recentBlockhash = blockhash;
        tx.feePayer = userPubkey;

        const { signature } = await solana.signAndSendTransaction(
            tx,
            SOLANA_TX_SEND_OPTIONS,
        );

        try {
            await confirmSignature(connection, signature, {
                lastValidBlockHeight,
            });
        } catch (error) {
            const status = await connection.getSignatureStatus(signature, {
                searchTransactionHistory: true,
            });

            if (
                status.value?.confirmationStatus === 'confirmed' ||
                status.value?.confirmationStatus === 'finalized'
            ) {
                return { txHash: signature, nonce: 0 };
            }

            if (
                error instanceof Error &&
                error.message.includes('block height exceeded')
            ) {
                throw new Error(
                    'Solana transaction expired before confirmation. Please try again.',
                );
            }

            throw error;
        }

        return { txHash: signature, nonce: 0 };
    };

    /**
     * Solana → EVM native SOL deposit: plain SystemProgram.transfer to the
     * bridge hot wallet. Returns the Solana signature.
     */
    const nativeSolTransferToHotWallet = async (
        amount: string,
    ): Promise<{ txHash: string; nonce: number } | null> => {
        const solana = getSelectedSolanaTransactionProvider('mainnet');

        if (!solana?.publicKey) {
            throw new Error('Solana wallet not connected');
        }

        const connection = new Connection(SOLANA_RPC, 'confirmed');
        const userPubkey = new PublicKey(solana.publicKey.toBase58());
        const lamports = BigInt(Math.round(parseFloat(amount) * 1e9));

        const tx = new Transaction().add(
            SystemProgram.transfer({
                fromPubkey: userPubkey,
                toPubkey: BRIDGE_HOT_WALLET,
                lamports,
            }),
        );

        const { blockhash, lastValidBlockHeight } =
            await connection.getLatestBlockhash('confirmed');
        tx.recentBlockhash = blockhash;
        tx.feePayer = userPubkey;

        const { signature } = await solana.signAndSendTransaction(
            tx,
            SOLANA_TX_SEND_OPTIONS,
        );

        try {
            await confirmSignature(connection, signature, {
                lastValidBlockHeight,
            });
        } catch (error) {
            const status = await connection.getSignatureStatus(signature, {
                searchTransactionHistory: true,
            });

            if (
                status.value?.confirmationStatus === 'confirmed' ||
                status.value?.confirmationStatus === 'finalized'
            ) {
                return { txHash: signature, nonce: 0 };
            }

            if (
                error instanceof Error &&
                error.message.includes('block height exceeded')
            ) {
                throw new Error(
                    'Solana transaction expired before confirmation. Please try again.',
                );
            }

            throw error;
        }

        return { txHash: signature, nonce: 0 };
    };

    /**
     * How much of `symbol` the relayer can deliver to `direction`'s destination
     * chain right now, net of what is already promised to other transfers.
     *
     * A failed fetch is `unavailable`, not "no limit". Before the answer
     * arrives the entry is `loading`, which also blocks — the wizard must not
     * walk somebody into a wallet prompt on a number it has not got.
     */
    const fetchDestinationCapacity = async (
        direction: string,
        symbol: BridgeTokenSymbol,
    ): Promise<void> => {
        const key = capacityKey(direction, symbol);

        destinationCapacities.value = {
            ...destinationCapacities.value,
            [key]: LOADING_CAPACITY,
        };

        try {
            const res = await fetch(
                `/bridge/capacity?direction=${encodeURIComponent(direction)}&token=${encodeURIComponent(symbol)}`,
                { headers: { Accept: 'application/json' } },
            );

            destinationCapacities.value = {
                ...destinationCapacities.value,
                [key]: res.ok
                    ? parseCapacity(await res.json())
                    : unreadableCapacity(
                          `capacity request failed (HTTP ${res.status})`,
                      ),
            };
        } catch (e) {
            console.error('[bridge] fetchDestinationCapacity failed', e);
            destinationCapacities.value = {
                ...destinationCapacities.value,
                [key]: unreadableCapacity('capacity request failed'),
            };
        }
    };

    const getDestinationCapacity = (
        direction: string,
        symbol: BridgeTokenSymbol,
    ): DestinationCapacity =>
        destinationCapacities.value[capacityKey(direction, symbol)] ??
        LOADING_CAPACITY;

    /**
     * Claim the destination capacity for this exact transfer, immediately
     * before the wallet is opened.
     *
     * This is the step that closes the window a UI check cannot: between
     * reading a balance and signing against it there is a wallet prompt, a
     * person, and possibly somebody else spending the same reserve. The server
     * writes the claim under a lock; what comes back is a reference that
     * `/bridge/submit` consumes once.
     */
    const reserveDestinationCapacity = async (params: {
        direction: string;
        token: BridgeTokenSymbol;
        amount: string;
        senderAddress: string | null;
        recipientAddress: string;
    }): Promise<
        | { ok: true; reference: string; expiresAt: string | null }
        | { ok: false; message: string; reason: string }
    > => {
        const csrfToken = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1];

        try {
            const res = await fetch('/bridge/reserve', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken
                        ? decodeURIComponent(csrfToken)
                        : '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    direction: params.direction,
                    token: params.token,
                    sender_address: params.senderAddress,
                    recipient_address: params.recipientAddress,
                    amount: params.amount,
                }),
            });

            const json = await res.json().catch(() => ({}));

            if (!res.ok) {
                // Show the number that caused the refusal, not the stale one
                // the screen was drawn with.
                if (json?.capacity) {
                    destinationCapacities.value = {
                        ...destinationCapacities.value,
                        [capacityKey(params.direction, params.token)]:
                            parseCapacity(json.capacity),
                    };
                }

                return {
                    ok: false,
                    reason:
                        typeof json?.reason === 'string'
                            ? json.reason
                            : 'refused',
                    message:
                        typeof json?.message === 'string'
                            ? json.message
                            : 'The bridge cannot take this transfer right now.',
                };
            }

            return {
                ok: true,
                reference: json.reservation.reference,
                expiresAt: json.reservation.expires_at ?? null,
            };
        } catch (e) {
            console.error('[bridge] reserveDestinationCapacity failed', e);

            // Never fall through to a signature on a failed reservation: the
            // point of the whole mechanism is that an irreversible source-side
            // step must not begin uncovered.
            return {
                ok: false,
                reason: 'unreachable',
                message:
                    'Could not reach the bridge to hold liquidity for this transfer. Nothing was sent — try again.',
            };
        }
    };

    return {
        cyberSolBalance,
        solanaCyberBalance,
        wrongNetwork,
        evmNativeBalances,
        evmNativeMaxAmounts,
        tokenBalances,
        destinationCapacities,
        fetchDestinationCapacity,
        getDestinationCapacity,
        reserveDestinationCapacity,
        fetchEvmNativeBalance,
        getEvmNativeBalance,
        getEvmNativeMaxAmount,
        fetchCyberSolBalance,
        fetchSolanaCyberBalance,
        fetchTokenBalanceEvm,
        fetchTokenBalanceSolana,
        fetchTokenBalanceTon,
        getTokenBalance,
        lockNativeOnSolana,
        redeemCyberSolOnEvm,
        erc20TransferToRelayer,
        nativeTransferToRelayer,
        ensureNetwork,
        ensureCyberiaNetwork,
        splTransferToHotWallet,
        nativeSolTransferToHotWallet,
    };
};

// ---------------------------------------------------------------
//  Helpers
// ---------------------------------------------------------------

const B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

function solanaBase58ToBytes32(base58: string): string {
    let num = 0n;

    for (const c of base58) {
        const i = B58.indexOf(c);

        if (i === -1) {
            throw new Error('bad b58');
        }

        num = num * 58n + BigInt(i);
    }

    return '0x' + num.toString(16).padStart(64, '0');
}

function parseEvmNonce(receipt: {
    logs: Array<{ topics: string[]; data: string }>;
}): number {
    for (const log of receipt.logs) {
        if (log.data?.length >= 2 + 64 * 3) {
            return parseInt(log.data.slice(2 + 64 * 2, 2 + 64 * 3), 16);
        }
    }

    return 0;
}
