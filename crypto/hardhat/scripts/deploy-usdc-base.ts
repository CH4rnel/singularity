import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const artifact = JSON.parse(
  fs.readFileSync("./artifacts/contracts/USDCBase.sol/USDCBase.json", "utf8"),
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

// Optional override: USDC_BASE_OWNER env var (defaults to deployer).
const owner = (process.env.USDC_BASE_OWNER ??
  walletClient.account.address) as `0x${string}`;

console.log("Deploying USD Coin (Base) — USDC.BASE wrapper...");
console.log("  Deployer:", walletClient.account.address);
console.log("  Initial owner / minter:", owner);
console.log("  RPC:", RPC_URL);

const hash = await walletClient.deployContract({
  abi: artifact.abi,
  bytecode: artifact.bytecode,
  args: [owner],
});

console.log("Transaction hash:", hash);

const receipt = await publicClient.waitForTransactionReceipt({ hash });
console.log("USDC.BASE deployed at:", receipt.contractAddress);
console.log("Block:", receipt.blockNumber);
console.log("Gas used:", receipt.gasUsed.toString());
console.log(
  "\nSet BRIDGE_USDC_BASE_WRAPPER_ADDRESS in backend/laravel/.env and add the",
);
console.log("address to deployments/cyberia-tokens.json (USDC.BASE entry).");
