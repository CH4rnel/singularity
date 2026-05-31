/**
 * Bridge relayer script. Called by Laravel job.
 * Usage: npx tsx scripts/relay-bridge.ts <direction> <recipient> <amount_wei> <nonce> [gas_drop_wei]
 *
 * For sol_to_evm: calls releaseCyberSol(recipient, amount, nonce) on CyberBridge.
 *                 If gas_drop_wei > 0, additionally sends that much native CYBER
 *                 to the recipient (used for first-time recipient onboarding).
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

async function main() {
  const [,, direction, recipient, amountWei, nonceStr, gasDropStr] = process.argv;

  if (!direction || !recipient || !amountWei || !nonceStr) {
    console.error("Usage: relay-bridge.ts <sol_to_evm|evm_to_sol> <recipient> <amount_wei> <nonce> [gas_drop_wei]");
    process.exit(1);
  }

  const nonce = BigInt(nonceStr);
  const amount = BigInt(amountWei);
  const gasDrop = gasDropStr ? BigInt(gasDropStr) : 0n;

  console.log(`Relay: ${direction} -> ${recipient}, amount=${amount}, nonce=${nonce}, gasDrop=${gasDrop}`);

  if (direction === "sol_to_evm") {
    // Mint CYBER.sol on EVM for the recipient. The contract enforces onlyOwner
    // — if the relayer isn't the owner the tx itself reverts with a clear
    // error, so no need to pre-check via a separate owner() RPC call.
    const hash = await walletClient.writeContract({
      address: BRIDGE_ADDRESS,
      abi: bridgeAbi,
      functionName: "releaseCyberSol",
      args: [recipient as `0x${string}`, amount, nonce],
    });

    console.log("TX:", hash);
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    console.log("Block:", receipt.blockNumber);
    console.log("Status:", receipt.status);

    let gasDropTxHash: string | null = null;

    if (gasDrop > 0n) {
      console.log(`GasDrop: sending ${gasDrop} wei native CYBER to ${recipient}`);
      const dropHash = await walletClient.sendTransaction({
        to: recipient as `0x${string}`,
        value: gasDrop,
      });
      const dropReceipt = await publicClient.waitForTransactionReceipt({ hash: dropHash });
      console.log("GasDrop TX:", dropHash, "status:", dropReceipt.status);
      gasDropTxHash = dropHash;
    }

    // Output JSON for Laravel to parse
    console.log(JSON.stringify({ txHash: hash, status: receipt.status, gasDropTxHash }));
  } else if (direction === "evm_to_sol") {
    console.error("evm_to_sol relay not yet implemented");
    process.exit(1);
  }
}

main().catch((e) => { console.error(e.message); process.exit(1); });
