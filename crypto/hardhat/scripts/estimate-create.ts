import "dotenv/config";
import { createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const cyberia = { ...mainnet, id: 49406, name: "Cyberia", nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 } };
const pub = createPublicClient({ chain: cyberia, transport: http("https://rpc.cyberia.church") });
const FACTORY = "0x9e672AC969497edD7D5f6233FF3b237236627151" as `0x${string}`;
const artifact = JSON.parse(fs.readFileSync("./artifacts/contracts/TelegramTokenFactory.sol/TelegramTokenFactory.json", "utf8"));

const acct = privateKeyToAccount((process.env.DEPLOYER_PK!.startsWith("0x") ? process.env.DEPLOYER_PK! : "0x" + process.env.DEPLOYER_PK!) as `0x${string}`);

// Simulate with DIFFERENT chatId (fresh one)
const chatId = -1234567890n;
const gas = await pub.estimateContractGas({
  address: FACTORY,
  abi: artifact.abi,
  functionName: "createToken",
  args: ["Bots", "BOTS", chatId, acct.address],
  account: acct,
});
console.log("estimated gas for createToken:", gas.toString());
