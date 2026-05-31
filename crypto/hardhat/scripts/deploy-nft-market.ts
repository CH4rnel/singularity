/**
 * Deploys CyberiaNFT (shared ERC-721 collection) and NFTMarket (fixed-price)
 * on Cyberia.
 *
 * Usage:
 *   npx hardhat run scripts/deploy-nft-market.ts --network cyberia
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
const FEE_BPS = 100n; // 1%

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
  console.log("=== Deploy CyberiaNFT + NFTMarket ===");
  console.log("Deployer:", account.address);
  console.log("RPC:     ", RPC_URL);

  const nftArt = loadArtifact("./artifacts/contracts/CyberiaNFT.sol/CyberiaNFT.json");
  const mktArt = loadArtifact("./artifacts/contracts/NFTMarket.sol/NFTMarket.json");

  const nft = await deploy("CyberiaNFT", nftArt, []);
  const market = await deploy("NFTMarket", mktArt, [account.address, FEE_BPS]);

  const out = {
    chainId: 49406,
    rpc: RPC_URL,
    CyberiaNFT: nft.address,
    NFTMarket: market.address,
    feeRecipient: account.address,
    feeBps: FEE_BPS.toString(),
    deployer: account.address,
    deployTxs: { nft: nft.tx, market: market.tx },
    timestamp: new Date().toISOString(),
  };
  const outPath = path.resolve("./deployments/cyberia-nft-market.json");
  fs.writeFileSync(outPath, JSON.stringify(out, null, 2));
  console.log("\nWrote:", outPath);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
