import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");

const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);
const relayerPk = process.env.BRIDGE_RELAYER_PRIVATE_KEY
  ? (process.env.BRIDGE_RELAYER_PRIVATE_KEY.startsWith("0x")
      ? process.env.BRIDGE_RELAYER_PRIVATE_KEY
      : `0x${process.env.BRIDGE_RELAYER_PRIVATE_KEY}`) as `0x${string}`
  : pk;
const relayer = privateKeyToAccount(relayerPk);
const rpcUrl = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const client = createWalletClient({
  chain,
  transport: http(rpcUrl),
  account,
});

const publicClient = createPublicClient({
  chain,
  transport: http(rpcUrl),
});

async function deploy(artifactPath: string, args: unknown[] = []) {
  const artifact = JSON.parse(fs.readFileSync(artifactPath, "utf8"));
  const hash = await client.deployContract({
    abi: artifact.abi,
    bytecode: artifact.bytecode,
    args,
  });
  console.log("  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  console.log("  address:", receipt.contractAddress);
  return { address: receipt.contractAddress!, abi: artifact.abi };
}

async function main() {
  console.log("Deployer:", account.address);
  console.log("Relayer owner:", relayer.address);
  console.log("RPC:", rpcUrl);

  // 1. Deploy CYBER.sol token
  console.log("\n1. Deploying CyberToken (CYBER.sol)...");
  const cyberToken = await deploy(
    "./artifacts/contracts/CyberToken.sol/CyberToken.json"
  );

  // 2. Deploy CyberBridge (uses CYBER.sol as the native token)
  console.log("\n2. Deploying CyberBridge...");
  const bridge = await deploy(
    "./artifacts/contracts/CyberBridge.sol/CyberBridge.json",
    [cyberToken.address, relayer.address]
  );

  // 3. Read WrappedCyberSol address from bridge
  const wrappedAddr = await publicClient.readContract({
    address: bridge.address,
    abi: bridge.abi,
    functionName: "wrappedCyberSol",
  });

  console.log("\n=== Deployed ===");
  console.log("CyberToken (CYBER.sol):", cyberToken.address);
  console.log("CyberBridge:", bridge.address);
  console.log("WrappedCyberSol (CYBER.sol bridged):", wrappedAddr);

  const deployment = {
    chainId: chain.id,
    rpcUrl,
    deployer: account.address,
    relayerOwner: relayer.address,
    cyberToken: cyberToken.address,
    cyberBridge: bridge.address,
    wrappedCyberSol: wrappedAddr,
    deployedAt: new Date().toISOString(),
  };

  fs.mkdirSync("./deployments", { recursive: true });
  fs.writeFileSync(
    "./deployments/cyberia-bridge.json",
    `${JSON.stringify(deployment, null, 2)}\n`,
  );

  console.log("\nSaved deployment: deployments/cyberia-bridge.json");
  console.log("\nUpdate env/frontend:");
  console.log(`BRIDGE_EVM_CONTRACT_ADDRESS=${bridge.address}`);
  console.log(`CYBERSOL_ERC20_ADDRESS=${wrappedAddr}`);
}

main().catch(console.error);
