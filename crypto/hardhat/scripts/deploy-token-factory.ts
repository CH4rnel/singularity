// scripts/deploy-token-factory.ts
import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK is not set in .env");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const account = privateKeyToAccount(pk as `0x${string}`);

const artifact = JSON.parse(
  fs.readFileSync(
    "./artifacts/contracts/TokenFactory.sol/TokenFactory.json",
    "utf8",
  ),
);

const cyberia = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const RPC_URL = process.env.RPC_URL ?? "https://rpc.cyberia.church";

const walletClient = createWalletClient({
  chain: cyberia,
  transport: http(RPC_URL),
  account,
});

const publicClient = createPublicClient({
  chain: cyberia,
  transport: http(RPC_URL),
});

console.log("Deploying TokenFactory from:", account.address);

const hash = await walletClient.deployContract({
  abi: artifact.abi,
  bytecode: artifact.bytecode,
  args: [account.address],
});

console.log("Transaction hash:", hash);

const receipt = await publicClient.waitForTransactionReceipt({ hash });

console.log("TokenFactory deployed at:", receipt.contractAddress);
console.log("Block number:", receipt.blockNumber);
