/**
 * Redeploy only the ASH token (the previous deployment reverted due to the
 * `mintingAllowedAfter >= block.timestamp` constructor check firing at mining
 * time). This time we add a 5-minute buffer.
 *
 * Existing UniswapV2Factory (0x2327Cfa8832aA1bCc1FE527Db16E237229b8D4D5) is
 * reused and the summary file is updated in place.
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  keccak256,
  type Abi,
  type Hex,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

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
const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const ASH_MINTER = (process.env.ASH_MINTER as `0x${string}` | undefined) ?? account.address;
const ASH_PREMINT_RECIPIENT =
  (process.env.ASH_PREMINT_RECIPIENT as `0x${string}` | undefined) ?? account.address;

function loadArtifact(p: string) {
  const artifact = JSON.parse(fs.readFileSync(path.resolve(p), "utf8"));
  return {
    abi: artifact.abi as Abi,
    bytecode: artifact.bytecode as Hex,
    contractName: artifact.contractName as string,
  };
}

async function main() {
  console.log("=== Redeploy ASH on Cyberia ===");
  console.log("Deployer:", account.address);

  const latestBlock = await publicClient.getBlock({ blockTag: "latest" });
  const mintingAllowedAfter = latestBlock.timestamp + 300n;
  console.log("latest block:", latestBlock.number, "timestamp:", latestBlock.timestamp);
  console.log("mintingAllowedAfter:", mintingAllowedAfter);

  const ashArt = loadArtifact("./artifacts/contracts/quickswap/ASH.sol/ASH.json");

  const hash = await walletClient.deployContract({
    abi: ashArt.abi,
    bytecode: ashArt.bytecode,
    args: [ASH_PREMINT_RECIPIENT, ASH_MINTER, mintingAllowedAfter],
    gas: 2_500_000n,
  });
  console.log("tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") {
    console.error("revert. gas used:", receipt.gasUsed);
    throw new Error("ASH deployment reverted");
  }
  const address = receipt.contractAddress!;
  console.log("ASH address:", address);
  console.log("gas used:   ", receipt.gasUsed);

  const code = await publicClient.getCode({ address });
  console.log("code len:", code?.length ?? 0);

  // Sanity: read name/symbol/totalSupply
  const nameVal = await publicClient.readContract({
    address,
    abi: ashArt.abi,
    functionName: "name",
  });
  const symbolVal = await publicClient.readContract({
    address,
    abi: ashArt.abi,
    functionName: "symbol",
  });
  const totalSupply = await publicClient.readContract({
    address,
    abi: ashArt.abi,
    functionName: "totalSupply",
  });
  const decimals = await publicClient.readContract({
    address,
    abi: ashArt.abi,
    functionName: "decimals",
  });
  const minter = await publicClient.readContract({
    address,
    abi: ashArt.abi,
    functionName: "minter",
  });
  console.log("name:", nameVal);
  console.log("symbol:", symbolVal);
  console.log("decimals:", decimals);
  console.log("totalSupply:", totalSupply);
  console.log("minter:", minter);

  // Update deployments summary
  const outDir = path.resolve("./deployments");
  fs.mkdirSync(outDir, { recursive: true });
  const outFile = path.join(outDir, "cyberia-quickswap.json");

  const FACTORY = "0x2327Cfa8832aA1bCc1FE527Db16E237229b8D4D5";
  const pairArt = loadArtifact(
    "./artifacts/contracts/quickswap/UniswapV2Pair.sol/UniswapV2Pair.json",
  );
  const INIT_CODE_HASH = keccak256(pairArt.bytecode);

  const summary = {
    chainId: 49406,
    chainName: "Cyberia",
    rpc: RPC_URL,
    deployer: account.address,
    ASH: address,
    UniswapV2Factory: FACTORY,
    feeToSetter: account.address,
    INIT_CODE_HASH,
    ashMinter: ASH_MINTER,
    ashPremintRecipient: ASH_PREMINT_RECIPIENT,
    initialPair: null as null | string,
    timestamp: new Date().toISOString(),
  };
  fs.writeFileSync(outFile, JSON.stringify(summary, null, 2));
  console.log("\nSaved to", outFile);
  console.log(JSON.stringify(summary, null, 2));
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
