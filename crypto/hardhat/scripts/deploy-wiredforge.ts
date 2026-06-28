/**
 * Deploys WiredForge — the anti-cheat backbone of the Wired NFT game.
 *
 * The constructor takes the trusted `signer` (the LainOS game server's address)
 * that authorises run entry via EIP-712 tickets (model B). It defaults to the
 * deployer; change it any time with setSigner() once the LainOS signer address
 * is known. The deployer is the contract owner.
 *
 * Usage:
 *   npx hardhat run scripts/deploy-wiredforge.ts --network cyberia
 *   WIRED_SIGNER=0x... npx hardhat run scripts/deploy-wiredforge.ts --network cyberia
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  formatEther,
  http,
  isAddress,
  type Abi,
  type Hex,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");
const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

function loadArtifact(p: string): { abi: Abi; bytecode: Hex } {
  return JSON.parse(fs.readFileSync(path.resolve(p), "utf8")) as { abi: Abi; bytecode: Hex };
}

async function main() {
  const signer = (process.env.WIRED_SIGNER || account.address) as `0x${string}`;
  if (!isAddress(signer)) throw new Error(`WIRED_SIGNER is not a valid address: ${signer}`);

  console.log("=== Deploy WiredForge ===");
  console.log("RPC:     ", RPC_URL);
  console.log("Deployer:", account.address);
  console.log("Signer:  ", signer, signer === account.address ? "(deployer; change later via setSigner)" : "");

  // Preflight: make sure the deployer can pay for gas before broadcasting.
  const balance = await publicClient.getBalance({ address: account.address });
  console.log("Balance: ", formatEther(balance), "CYBER");
  if (balance === 0n) {
    throw new Error("Deployer has 0 CYBER — fund it before deploying.");
  }

  const art = loadArtifact("./artifacts/contracts/WiredForge.sol/WiredForge.json");

  const hash = await walletClient.deployContract({
    abi: art.abi,
    bytecode: art.bytecode,
    args: [signer] as never,
  });
  console.log("\nWiredForge deploy tx:", hash);

  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success" || !receipt.contractAddress) {
    throw new Error("WiredForge deploy reverted");
  }
  console.log("WiredForge:", receipt.contractAddress);
  console.log("gas used: ", receipt.gasUsed.toString());

  const out = {
    chainId: 49406,
    rpc: RPC_URL,
    WiredForge: receipt.contractAddress,
    signer,
    owner: account.address,
    deployer: account.address,
    deployTx: hash,
    timestamp: new Date().toISOString(),
  };
  const outPath = path.resolve("./deployments/cyberia-wiredforge.json");
  fs.writeFileSync(outPath, JSON.stringify(out, null, 2));
  console.log("\nWrote:", outPath);
  console.log("Explorer: https://explorer.cyberia.church/address/" + receipt.contractAddress);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
