import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";
import * as path from "path";

/**
 * Deploys the Hatcher ERC20 wrapper (mint-model bridged token) on Cyberia and
 * writes its address back into deployments/cyberia-tokens.json.
 *
 * The deployer becomes the owner/minter unless TOKEN_OWNER (or HATCHER_OWNER)
 * overrides it. For the bridge to drive mint()/burnFrom(), the owner MUST be the
 * relayer EOA (same key the bridge relayer uses, i.e. DEPLOYER_PK by default).
 *
 * Env: DEPLOYER_PK (required), CYBERIA_RPC_URL (optional),
 *      HATCHER_OWNER / TOKEN_OWNER (optional, defaults to deployer).
 */

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");

const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const artifact = JSON.parse(
  fs.readFileSync("./artifacts/contracts/Hatcher.sol/Hatcher.json", "utf8"),
);

const account = privateKeyToAccount(pk);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "CYBER", symbol: "CYBER", decimals: 18 },
};

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

// Owner / minter must be the bridge relayer EOA for mint()/burnFrom() to work.
const owner = (process.env.HATCHER_OWNER ??
  process.env.TOKEN_OWNER ??
  account.address) as `0x${string}`;

console.log("Deploying Hatcher (HATCHER, 9 decimals, mint-model bridge wrapper)...");
console.log("  Deployer:", account.address);
console.log("  Initial owner / minter:", owner);
console.log("  RPC:", RPC_URL);

const hash = await walletClient.deployContract({
  abi: artifact.abi,
  bytecode: artifact.bytecode,
  args: [owner],
});

console.log("Transaction hash:", hash);

const receipt = await publicClient.waitForTransactionReceipt({ hash });
const address = receipt.contractAddress!;
console.log("Hatcher deployed at:", address);
console.log("Block:", receipt.blockNumber);
console.log("Gas used:", receipt.gasUsed.toString());

// Persist into the token registry so token-admin.ts and the bridge config can
// reference it.
const registryPath = path.resolve("deployments/cyberia-tokens.json");
const registry = JSON.parse(fs.readFileSync(registryPath, "utf8"));
const entry = registry.tokens.find((t: { symbol: string }) => t.symbol === "HATCHER");
if (entry) {
  entry.address = address;
  fs.writeFileSync(registryPath, `${JSON.stringify(registry, null, 2)}\n`);
  console.log(`Updated registry: ${registryPath}`);
} else {
  console.log("WARNING: no HATCHER entry in cyberia-tokens.json — add it manually.");
}

console.log("\nNEXT STEPS:");
console.log("  1. Set evm_address to this address in backend/laravel/config/bridge.php");
console.log("     and resources/js/lib/bridgeTokens.ts (HATCHER entry).");
console.log("  2. Fill the Solana mint address (solana_mint / solanaMint) once the");
console.log("     HATCHER SPL token exists, then the relayer can bridge both ways.");
