import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) {
  throw new Error("DEPLOYER_PK is not set in .env");
}

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;

const artifact = JSON.parse(
  fs.readFileSync(
    "./artifacts/contracts/TelegramTokenFactory.sol/TelegramTokenFactory.json",
    "utf8",
  ),
);

const account = privateKeyToAccount(pk as `0x${string}`);

const cyberia = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const RPC_URL = process.env.RPC_URL ?? "https://rpc.cyberia.church";

const client = createWalletClient({
  chain: cyberia,
  transport: http(RPC_URL),
  account,
});

console.log("Deploying TelegramTokenFactory from:", client.account.address);

const hash = await client.deployContract({
  abi: artifact.abi,
  bytecode: artifact.bytecode,
  args: [client.account.address],
});

console.log("Transaction hash:", hash);

const publicClient = createPublicClient({
  chain: cyberia,
  transport: http(RPC_URL),
});

const receipt = await publicClient.waitForTransactionReceipt({ hash });
console.log("TelegramTokenFactory deployed at:", receipt.contractAddress);
console.log("Block number:", receipt.blockNumber);
console.log(
  "\nAdd to scripts/python/.env:\n  TELEGRAM_TOKEN_FACTORY=" +
    receipt.contractAddress,
);
