/**
 * Bridge relayer script. Called by Laravel job.
 * Usage: npx tsx scripts/relay-bridge.ts <direction> <recipient> <amount_wei> <nonce> [gas_drop_wei] [convert]
 *
 * For sol_to_evm: calls releaseCyberSol(recipient, amount, nonce) on CyberBridge.
 *                 If gas_drop_wei > 0, additionally sends that much native CYBER
 *                 to the recipient (used for first-time recipient onboarding).
 *                 If convert=1, the CYBER.sol is minted to the relayer instead,
 *                 burned through CyberSolBurnSwap (1000 CYBER.sol -> 1 CYBER,
 *                 input forwarded to 0x...dEaD) and the resulting native CYBER
 *                 is sent to the recipient. Falls back to a plain CYBER.sol
 *                 delivery when the swap contract lacks payout liquidity.
 * For evm_to_sol: (TODO) calls release_wrapped on Solana bridge program
 */
import "dotenv/config";
import { createWalletClient, createPublicClient, http, parseAbi } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";

const RELAYER_PK = process.env.BRIDGE_RELAYER_PRIVATE_KEY || process.env.DEPLOYER_PK;
if (!RELAYER_PK) {
  console.error("BRIDGE_RELAYER_PRIVATE_KEY or DEPLOYER_PK not set");
  process.exit(1);
}

const pk = (RELAYER_PK.startsWith("0x") ? RELAYER_PK : `0x${RELAYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const configuredBridgeAddress = process.env.BRIDGE_EVM_CONTRACT_ADDRESS || "0x0065AA95709ABB09dA8293F469FA9713f79544Eb";
const BRIDGE_ADDRESS = configuredBridgeAddress as `0x${string}`;

// CyberSolBurnSwap: fixed-rate redeemer, burns CYBER.sol to 0x...dEaD and pays
// out native CYBER at 1000:1 from its own balance (see deployments/
// cyberia-cybersol-burn-swap.json).
const BURN_SWAP_ADDRESS = (process.env.CYBERSOL_BURN_SWAP_ADDRESS ||
  "0xa5Ae36E5b1eDb24BCa2F96783d079B28e0BCfd71") as `0x${string}`;
const CYBER_SOL_ADDRESS = (process.env.CYBER_SOL_TOKEN_ADDRESS ||
  "0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA") as `0x${string}`;
const CONVERT_RATE = 1000n;

const RPC_URL = process.env.CYBERIA_RPC_URL || "http://polygon-edge:8545";

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

// Generous timeout — the internal Cyberia RPC has been observed timing out
// on the default 10s. 60s + a couple of retries keeps the relay resilient.
const transport = http(RPC_URL, { timeout: 60_000, retryCount: 2 });

const walletClient = createWalletClient({
  chain,
  transport,
  account,
});

const publicClient = createPublicClient({
  chain,
  transport,
});

const bridgeAbi = parseAbi([
  "function releaseCyberSol(address to, uint256 amount, uint64 nonce)",
  "function unlockCyber(address to, uint256 amount, uint64 nonce)",
]);

const erc20Abi = parseAbi([
  "function approve(address spender, uint256 value) returns (bool)",
  "function allowance(address owner, address spender) view returns (uint256)",
]);

const burnSwapAbi = parseAbi([
  "function swap(uint256 amountIn)",
]);

async function releaseCyberSol(to: `0x${string}`, amount: bigint, nonce: bigint) {
  const hash = await walletClient.writeContract({
    address: BRIDGE_ADDRESS,
    abi: bridgeAbi,
    functionName: "releaseCyberSol",
    args: [to, amount, nonce],
  });

  console.log("TX:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  console.log("Block:", receipt.blockNumber);
  console.log("Status:", receipt.status);

  if (receipt.status !== "success") {
    throw new Error(`releaseCyberSol reverted: ${hash}`);
  }

  return hash;
}

async function sendGasDrop(recipient: `0x${string}`, gasDrop: bigint): Promise<string | null> {
  if (gasDrop <= 0n) return null;

  console.log(`GasDrop: sending ${gasDrop} wei native CYBER to ${recipient}`);
  const dropHash = await walletClient.sendTransaction({
    to: recipient,
    value: gasDrop,
  });
  const dropReceipt = await publicClient.waitForTransactionReceipt({ hash: dropHash });
  console.log("GasDrop TX:", dropHash, "status:", dropReceipt.status);

  return dropHash;
}

/**
 * Convert path: mint the bridged CYBER.sol to the relayer itself, burn it
 * through CyberSolBurnSwap and forward the native CYBER payout to the
 * recipient. Returns null when the swap contract cannot cover the payout, so
 * the caller can fall back to a plain CYBER.sol delivery.
 */
async function releaseAndConvert(
  recipient: `0x${string}`,
  amount: bigint,
  nonce: bigint,
): Promise<{ txHash: string; amountOut: bigint } | null> {
  // swap() requires amountIn to be an exact multiple of RATE (in wei); the
  // dust below 1000 wei of an 18-decimals amount is negligible.
  const amountIn = (amount / CONVERT_RATE) * CONVERT_RATE;
  const amountOut = amountIn / CONVERT_RATE;

  if (amountOut === 0n) {
    console.log("Convert: amount too small for the 1000:1 swap, falling back");
    return null;
  }

  const liquidity = await publicClient.getBalance({ address: BURN_SWAP_ADDRESS });
  console.log(`Convert: burn-swap liquidity=${liquidity}, payout needed=${amountOut}`);

  if (liquidity < amountOut) {
    console.log("Convert: insufficient native CYBER liquidity, falling back");
    return null;
  }

  // 1. Mint CYBER.sol to the relayer (consumes the bridge nonce as usual).
  await releaseCyberSol(account.address, amountIn, nonce);

  // 2. Approve the burn-swap for the freshly minted balance.
  const allowance = await publicClient.readContract({
    address: CYBER_SOL_ADDRESS,
    abi: erc20Abi,
    functionName: "allowance",
    args: [account.address, BURN_SWAP_ADDRESS],
  });

  if (allowance < amountIn) {
    const approveHash = await walletClient.writeContract({
      address: CYBER_SOL_ADDRESS,
      abi: erc20Abi,
      functionName: "approve",
      args: [BURN_SWAP_ADDRESS, amountIn],
    });
    const approveReceipt = await publicClient.waitForTransactionReceipt({ hash: approveHash });
    console.log("Approve TX:", approveHash, "status:", approveReceipt.status);
    if (approveReceipt.status !== "success") {
      throw new Error(`approve reverted: ${approveHash}`);
    }
  }

  // 3. Swap: CYBER.sol goes straight to 0x...dEaD, native CYBER lands on the relayer.
  const swapHash = await walletClient.writeContract({
    address: BURN_SWAP_ADDRESS,
    abi: burnSwapAbi,
    functionName: "swap",
    args: [amountIn],
  });
  const swapReceipt = await publicClient.waitForTransactionReceipt({ hash: swapHash });
  console.log("Swap TX:", swapHash, "status:", swapReceipt.status);
  if (swapReceipt.status !== "success") {
    throw new Error(`burn-swap reverted: ${swapHash}`);
  }

  // 4. Forward the native CYBER payout to the recipient.
  const payoutHash = await walletClient.sendTransaction({
    to: recipient,
    value: amountOut,
  });
  const payoutReceipt = await publicClient.waitForTransactionReceipt({ hash: payoutHash });
  console.log("Payout TX:", payoutHash, "status:", payoutReceipt.status);
  if (payoutReceipt.status !== "success") {
    throw new Error(`native payout reverted: ${payoutHash}`);
  }

  return { txHash: payoutHash, amountOut };
}

async function main() {
  const [,, direction, recipient, amountWei, nonceStr, gasDropStr, convertStr] = process.argv;

  if (!direction || !recipient || !amountWei || !nonceStr) {
    console.error("Usage: relay-bridge.ts <sol_to_evm|evm_to_sol> <recipient> <amount_wei> <nonce> [gas_drop_wei] [convert]");
    process.exit(1);
  }

  const nonce = BigInt(nonceStr);
  const amount = BigInt(amountWei);
  const gasDrop = gasDropStr ? BigInt(gasDropStr) : 0n;
  const convert = convertStr === "1";

  console.log(`Relay: ${direction} -> ${recipient}, amount=${amount}, nonce=${nonce}, gasDrop=${gasDrop}, convert=${convert}`);

  if (direction === "sol_to_evm") {
    const to = recipient as `0x${string}`;

    if (convert) {
      const result = await releaseAndConvert(to, amount, nonce);

      if (result) {
        // Recipient already holds native CYBER — no gas drop needed.
        console.log(JSON.stringify({
          txHash: result.txHash,
          status: "success",
          converted: true,
          convertedAmountWei: result.amountOut.toString(),
          gasDropTxHash: null,
        }));
        return;
      }

      // Fall through to the plain CYBER.sol delivery below.
      console.log("Convert unavailable — delivering CYBER.sol instead");
    }

    // Mint CYBER.sol on EVM for the recipient. The contract enforces onlyOwner
    // — if the relayer isn't the owner the tx itself reverts with a clear
    // error, so no need to pre-check via a separate owner() RPC call.
    const hash = await releaseCyberSol(to, amount, nonce);

    const gasDropTxHash = await sendGasDrop(to, gasDrop);

    // Output JSON for Laravel to parse
    console.log(JSON.stringify({
      txHash: hash,
      status: "success",
      converted: convert ? false : undefined,
      conversionFallback: convert ? "insufficient_liquidity" : undefined,
      gasDropTxHash,
    }));
  } else if (direction === "evm_to_sol") {
    console.error("evm_to_sol relay not yet implemented");
    process.exit(1);
  }
}

main().catch((e) => { console.error(e.message); process.exit(1); });
