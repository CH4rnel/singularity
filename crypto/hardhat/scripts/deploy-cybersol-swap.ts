import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const artifact = JSON.parse(
  fs.readFileSync("./artifacts/contracts/CyberSolSwap.sol/CyberSolSwap.json", "utf8"),
);

const account = privateKeyToAccount(pk as `0x${string}`);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "CYBER", symbol: "CYBER", decimals: 18 },
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

// CYBER.sol (bridged) ERC20 address, taken from the bridge deployment registry.
const bridge = JSON.parse(
  fs.readFileSync("./deployments/cyberia-bridge.json", "utf8"),
);
const cyberSol = (process.env.CYBER_SOL ?? bridge.wrappedCyberSol) as `0x${string}`;
if (!cyberSol) throw new Error("CYBER.sol address not found");

console.log("Deploying CyberSolSwap (1000 CYBER.sol -> 1 CYBER)...");
console.log("  Deployer:", walletClient.account.address);
console.log("  CYBER.sol token:", cyberSol);
console.log("  RPC:", RPC_URL);

const hash = await walletClient.deployContract({
  abi: artifact.abi,
  bytecode: artifact.bytecode,
  args: [cyberSol],
});

console.log("Transaction hash:", hash);

const receipt = await publicClient.waitForTransactionReceipt({ hash });
console.log("CyberSolSwap deployed at:", receipt.contractAddress);
console.log("Block:", receipt.blockNumber);
console.log("Gas used:", receipt.gasUsed.toString());
console.log("\nNOTE: not funded with CYBER. Send native CYBER to the contract");
console.log("to provide payout liquidity before swaps can succeed.");
