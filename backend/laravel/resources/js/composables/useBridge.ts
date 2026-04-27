import { BrowserProvider, Contract, formatUnits, parseUnits, JsonRpcProvider, Network } from 'ethers';
import {
    Connection,
    PublicKey,
    Transaction,
} from '@solana/web3.js';
import {
    getAssociatedTokenAddress,
    getAccount,
    createTransferInstruction,
    createAssociatedTokenAccountInstruction,
} from '@solana/spl-token';
import { ref } from 'vue';

const CYBERIA_RPC = 'https://rpc.cyberia.church';
const CYBERIA_CHAIN_ID = 49406;
const cyberiaNetwork = new Network('cyberia', CYBERIA_CHAIN_ID);

function getCyberiaProvider(): JsonRpcProvider {
    return new JsonRpcProvider(CYBERIA_RPC, cyberiaNetwork, { staticNetwork: cyberiaNetwork });
}

const TOKEN_EXTENSIONS_PROGRAM_ID = 'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb';

const SOLANA_RPC = 'https://mainnet.helius-rpc.com/?api-key=7e740762-a25d-4d37-b854-de4cec9815ed';
const SOLANA_NATIVE_MINT = new PublicKey('E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump');
// const SOLANA_NATIVE_MINT = new PublicKey('6SvS85B6ufx8YA6wjGNdRvGZ4RbYUhXQjnaLgEbcfH8o');
const SOLANA_NATIVE_DECIMALS = 6;

// Hot wallet — relayer's Solana address (receives deposits, sends withdrawals)
const BRIDGE_HOT_WALLET = new PublicKey('E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w');

// EVM (Cyberia) — only CYBER.sol (bridged token)
// TODO: Update after redeploying EVM contracts with deploy-all.ts
const BRIDGE_ADDRESS = '0x0065AA95709ABB09dA8293F469FA9713f79544Eb';
const CYBERSOL_ERC20_ADDRESS = '0x98e3bC45CE5275caBB752F8e3c632C1d0fa0BF7E';

const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function decimals() view returns (uint8)',
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

export const useBridge = () => {
    // ---------------------------------------------------------------
    //  EVM balance — read directly from Cyberia RPC, no MetaMask needed
    // ---------------------------------------------------------------

    const fetchCyberSolBalance = async (address: string): Promise<void> => {
        try {
            console.log('[bridge] fetchCyberSolBalance: querying', { address, contract: CYBERSOL_ERC20_ADDRESS, rpc: CYBERIA_RPC });
            const provider = getCyberiaProvider();
            const contract = new Contract(CYBERSOL_ERC20_ADDRESS, ERC20_ABI, provider);
            const bal = (await contract.balanceOf(address)) as bigint;
            const dec = (await contract.decimals()) as number;
            console.log('[bridge] fetchCyberSolBalance: result', { bal: bal.toString(), dec });
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

    const fetchSolanaCyberBalance = async (walletAddress: string): Promise<void> => {
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
            console.log('[bridge] raw response:', JSON.stringify(json, null, 2));

            const accounts = json.result?.value ?? [];
            if (accounts.length === 0) {
                solanaCyberBalance.value = '0';
                return;
            }

            const amount = accounts[0].account.data.parsed.info.tokenAmount.uiAmount ?? '0';
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

    const ensureCyberiaNetwork = async (): Promise<boolean> => {
        if (!window.ethereum) return false;
        try {
            const chainIdHex = (await window.ethereum.request({ method: 'eth_chainId' })) as string;
            if (parseInt(chainIdHex, 16) === CYBERIA_CHAIN_ID) { wrongNetwork.value = false; return true; }
            try {
                await window.ethereum.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: '0x' + CYBERIA_CHAIN_ID.toString(16) }] });
                wrongNetwork.value = false; return true;
            } catch {
                try {
                    await window.ethereum.request({ method: 'wallet_addEthereumChain', params: [{ chainId: '0x' + CYBERIA_CHAIN_ID.toString(16), chainName: 'Cyberia', nativeCurrency: { name: 'Cyber', symbol: 'CYBER', decimals: 18 }, rpcUrls: [CYBERIA_RPC] }] });
                    wrongNetwork.value = false; return true;
                } catch { wrongNetwork.value = true; return false; }
            }
        } catch { wrongNetwork.value = true; return false; }
    };

    const redeemCyberSolOnEvm = async (amount: string, solanaRecipientBase58: string): Promise<{ txHash: string; nonce: number } | null> => {
        if (!window.ethereum) return null;
        if (!(await ensureCyberiaNetwork())) throw new Error('Please switch to Cyberia network');
        const provider = new BrowserProvider(window.ethereum);
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

    const lockNativeOnSolana = async (amount: string, _evmRecipientHex: string): Promise<{ txHash: string; nonce: number } | null> => {
        const phantom = getPhantom();
        if (!phantom?.publicKey) throw new Error('Phantom wallet not connected');

        const connection = new Connection(SOLANA_RPC, 'confirmed');
        const userPubkey = new PublicKey(phantom.publicKey.toBase58());
        const TOKEN_EXT_PROGRAM = new PublicKey(TOKEN_EXTENSIONS_PROGRAM_ID);

        const amountRaw = BigInt(Math.round(parseFloat(amount) * 10 ** SOLANA_NATIVE_DECIMALS));

        const userAta = await getAssociatedTokenAddress(
            SOLANA_NATIVE_MINT, userPubkey,
            false,               // allowOwnerOffCurve
            TOKEN_EXT_PROGRAM,   // ← Token-2022
        );
        const hotWalletAta = await getAssociatedTokenAddress(
            SOLANA_NATIVE_MINT, BRIDGE_HOT_WALLET,
            false,
            TOKEN_EXT_PROGRAM,
        );

        const tx = new Transaction();

        // Создаём ATA hot wallet если не существует
        try {
            await getAccount(connection, hotWalletAta, 'confirmed', TOKEN_EXT_PROGRAM);
        } catch {
            tx.add(
                createAssociatedTokenAccountInstruction(
                    userPubkey,
                    hotWalletAta,
                    BRIDGE_HOT_WALLET,
                    SOLANA_NATIVE_MINT,
                    TOKEN_EXT_PROGRAM,   // ← Token-2022
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
                [],              // multiSigners
                TOKEN_EXT_PROGRAM, // ← ЭТОГО и не хватало!
            ),
        );

        const { blockhash, lastValidBlockHeight } = await connection.getLatestBlockhash('finalized');
        tx.recentBlockhash = blockhash;
        tx.feePayer = userPubkey;

        const { signature } = await phantom.signAndSendTransaction(tx);

        await connection.confirmTransaction(
            { signature, blockhash, lastValidBlockHeight },
            'confirmed',
        );

        return { txHash: signature, nonce: 0 };
    };

    return {
        cyberSolBalance, solanaCyberBalance, wrongNetwork,
        fetchCyberSolBalance, fetchSolanaCyberBalance,
        lockNativeOnSolana, redeemCyberSolOnEvm,
    };
};

// ---------------------------------------------------------------
//  Helpers
// ---------------------------------------------------------------

const B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

function solanaBase58ToBytes32(base58: string): string {
    let num = 0n;
    for (const c of base58) { const i = B58.indexOf(c); if (i === -1) throw new Error('bad b58'); num = num * 58n + BigInt(i); }
    return '0x' + num.toString(16).padStart(64, '0');
}

function parseEvmNonce(receipt: { logs: Array<{ topics: string[]; data: string }> }): number {
    for (const log of receipt.logs) {
        if (log.data?.length >= 2 + 64 * 3) return parseInt(log.data.slice(2 + 64 * 2, 2 + 64 * 3), 16);
    }
    return 0;
}

function getPhantom() {
    type Phantom = {
        isPhantom: boolean;
        publicKey: { toBase58(): string; toBytes(): Uint8Array } | null;
        signAndSendTransaction(tx: Transaction): Promise<{ signature: string }>;
    };
    return (window as unknown as { phantom?: { solana?: Phantom } }).phantom?.solana ?? null;
}
