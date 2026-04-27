import "dotenv/config";
import { createWalletClient, createPublicClient, http, parseEventLogs } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) {
  throw new Error("DEPLOYER_PK is not set in .env");
}

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;

const artifact = JSON.parse(fs.readFileSync("./artifacts/contracts/Telegram.sol/Telegram.json", "utf8"));

const account = privateKeyToAccount(pk as `0x${string}`);

const client = createWalletClient({
  chain: {
    ...mainnet,
    id: 49406,
    name: "Cyberia",
    nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
  },
  transport: http("https://rpc.cyberia.church"),
  account,
});

console.log("Deploying Telegram from:", client.account.address);

const hash = await client.deployContract({
  abi: artifact.abi,
  bytecode: artifact.bytecode,
  args: [client.account.address],
});

console.log("Transaction hash:", hash);

const publicClient = createPublicClient({
  chain: {
    ...mainnet,
    id: 49406,
    name: "Cyberia",
    nativeCurrency: { name: "Ether", symbol: "ETH", decimals: 18 },
  },
  transport: http("https://rpc.cyberia.church"),
});

const receipt = await publicClient.waitForTransactionReceipt({ hash });
console.log("Contract deployed at:", receipt.contractAddress);
console.log("Block number:", receipt.blockNumber);
