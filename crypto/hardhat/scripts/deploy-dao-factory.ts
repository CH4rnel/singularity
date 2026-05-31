import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
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

function loadArtifact(artifactPath: string) {
  const full = path.resolve(artifactPath);
  const raw = fs.readFileSync(full, "utf8");
  return JSON.parse(raw) as { abi: unknown[]; bytecode: `0x${string}` };
}

async function deployContract(artifactPath: string, args: unknown[] = []) {
  const artifact = loadArtifact(artifactPath);
  const hash = await walletClient.deployContract({
    abi: artifact.abi,
    bytecode: artifact.bytecode,
    args,
    gas: 10_000_000n,
  } as any);
  console.log("  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (!receipt.contractAddress) throw new Error("No contract address in receipt");
  console.log("  address:", receipt.contractAddress);
  return receipt.contractAddress as `0x${string}`;
}

async function main() {
  console.log("Deployer:", account.address);
  const balance = await publicClient.getBalance({ address: account.address });
  console.log("Balance: ", balance.toString(), "wei\n");

  console.log("Deploying DAOFactory…");
  const factoryAddress = await deployContract(
    "./artifacts/contracts/dao/DAOFactory.sol/DAOFactory.json",
  );

  const summary = {
    chainId: 49406,
    chainName: "Cyberia",
    deployer: account.address,
    DAOFactory: factoryAddress,
    timestamp: new Date().toISOString(),
  };

  console.log("\n═══════════════════════════════════════");
  console.log(JSON.stringify(summary, null, 2));
  console.log("═══════════════════════════════════════");

  const outDir = path.resolve("./deployments");
  fs.mkdirSync(outDir, { recursive: true });
  const outFile = path.join(outDir, "cyberia-dao-factory.json");
  fs.writeFileSync(outFile, JSON.stringify(summary, null, 2));
  console.log(`Saved to ${outFile}`);
}

main().catch((e) => { console.error(e); process.exit(1); });
