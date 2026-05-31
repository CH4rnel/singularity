/**
 * Redeploys the CyberiaNFT collection (now named simply "NFT") on Cyberia and
 * rewires the existing NFTMarket deployment to it. The market is NFT-agnostic
 * (the NFT address is per-listing), so it is reused, not redeployed.
 *
 * Usage:
 *   npx hardhat run scripts/redeploy-nft.ts --network cyberia
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
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
  return JSON.parse(fs.readFileSync(path.resolve(p), "utf8")) as {
    abi: Abi;
    bytecode: Hex;
  };
}

async function deploy(name: string, art: { abi: Abi; bytecode: Hex }, args: unknown[]) {
  const hash = await walletClient.deployContract({
    abi: art.abi,
    bytecode: art.bytecode,
    args: args as never,
  });
  console.log(`${name} deploy tx:`, hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success" || !receipt.contractAddress) {
    throw new Error(`${name} deploy reverted`);
  }
  console.log(`${name}:`, receipt.contractAddress);
  console.log(`gas used:`, receipt.gasUsed.toString());
  return { address: receipt.contractAddress, tx: hash };
}

async function main() {
  const outPath = path.resolve("./deployments/cyberia-nft-market.json");
  const prev = JSON.parse(fs.readFileSync(outPath, "utf8"));

  console.log("=== Redeploy CyberiaNFT (collection name: NFT) ===");
  console.log("Deployer:    ", account.address);
  console.log("RPC:         ", RPC_URL);
  console.log("Reusing market:", prev.NFTMarket);

  const nftArt = loadArtifact("./artifacts/contracts/CyberiaNFT.sol/CyberiaNFT.json");
  const nft = await deploy("CyberiaNFT", nftArt, []);

  const out = {
    ...prev,
    CyberiaNFT: nft.address,
    deployTxs: { ...prev.deployTxs, nft: nft.tx },
    timestamp: new Date().toISOString(),
  };
  fs.writeFileSync(outPath, JSON.stringify(out, null, 2));
  console.log("\nWrote:", outPath);
  console.log("\nUpdate backend/laravel/.env -> VITE_NFT_CONTRACT=" + nft.address);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
