import "dotenv/config";
import * as fs from "fs";
import * as path from "path";
import { createPublicClient, createWalletClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";

/**
 * Deploys the TRX (bridged Tron) OZ-style ERC20 and writes its address back
 * into deployments/cyberia-tokens.json.
 *
 * Env: DEPLOYER_PK (required), CYBERIA_RPC_URL (optional),
 *      TOKEN_OWNER (optional, defaults to deployer).
 */

const SYMBOL = "TRX";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");
const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
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

const owner = (process.env.TOKEN_OWNER ?? account.address) as `0x${string}`;
console.log("Deployer:", account.address);
console.log("Initial owner / minter:", owner);
console.log("RPC:", RPC_URL);

const artifactPath = path.resolve(`artifacts/contracts/${SYMBOL}.sol/${SYMBOL}.json`);
const artifact = JSON.parse(fs.readFileSync(artifactPath, "utf8"));

console.log(`\nDeploying ${SYMBOL}...`);
const hash = await walletClient.deployContract({
  abi: artifact.abi,
  bytecode: artifact.bytecode,
  args: [owner],
});
console.log("  tx:", hash);

const receipt = await publicClient.waitForTransactionReceipt({ hash });
const address = receipt.contractAddress!;
console.log("  address:", address);
console.log("  block:", receipt.blockNumber, "gas:", receipt.gasUsed.toString());

const registryPath = path.resolve("deployments/cyberia-tokens.json");
const registry = JSON.parse(fs.readFileSync(registryPath, "utf8"));
const entry = registry.tokens.find((t: { symbol: string }) => t.symbol === SYMBOL);
if (entry) {
  entry.address = address;
  fs.writeFileSync(registryPath, `${JSON.stringify(registry, null, 2)}\n`);
  console.log(`\nUpdated registry: ${registryPath}`);
} else {
  console.warn(`\nWARNING: no ${SYMBOL} entry in registry; address not persisted`);
}
