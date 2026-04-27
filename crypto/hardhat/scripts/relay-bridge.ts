/**
 * Bridge relayer script. Called by Laravel job.
 * Usage: npx tsx scripts/relay-bridge.ts <direction> <recipient> <amount_wei> <nonce>
 *
 * For sol_to_evm: calls releaseCyberSol(recipient, amount, nonce) on CyberBridge
 * For evm_to_sol: (TODO) calls release_wrapped on Solana bridge program
 */
import "dotenv/config";
import { createWalletClient, createPublicClient, http, parseAbi, getContract } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) { console.error("DEPLOYER_PK not set"); process.exit(1); }

const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const BRIDGE_ADDRESS = "0x0065AA95709ABB09dA8293F469FA9713f79544Eb" as const;

const RPC_URL = process.env.CYBERIA_RPC_URL || "http://polygon-edge:8545";

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const walletClient = createWalletClient({
  chain,
  transport: http(RPC_URL),
  account,
});

const publicClient = createPublicClient({
  chain,
  transport: http(RPC_URL),
});

const bridgeAbi = parseAbi([
  "function releaseCyberSol(address to, uint256 amount, uint64 nonce)",
  "function unlockCyber(address to, uint256 amount, uint64 nonce)",
]);

async function main() {
  const [,, direction, recipient, amountWei, nonceStr] = process.argv;

  if (!direction || !recipient || !amountWei || !nonceStr) {
    console.error("Usage: relay-bridge.ts <sol_to_evm|evm_to_sol> <recipient> <amount_wei> <nonce>");
    process.exit(1);
  }

  const nonce = BigInt(nonceStr);
  const amount = BigInt(amountWei);

  console.log(`Relay: ${direction} -> ${recipient}, amount=${amount}, nonce=${nonce}`);

  if (direction === "sol_to_evm") {
    // Mint CYBER.sol on EVM for the recipient
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

    // Output JSON for Laravel to parse
    console.log(JSON.stringify({ txHash: hash, status: receipt.status }));
  } else if (direction === "evm_to_sol") {
    console.error("evm_to_sol relay not yet implemented");
    process.exit(1);
  }
}

main().catch((e) => { console.error(e.message); process.exit(1); });
