import "dotenv/config";
import { createPublicClient, http } from "viem";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const cyberia = { ...mainnet, id: 49406, name: "Cyberia", nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 } };
const pub = createPublicClient({ chain: cyberia, transport: http("https://rpc.cyberia.church") });
const FACTORY = "0x9e672AC969497edD7D5f6233FF3b237236627151" as `0x${string}`;
const artifact = JSON.parse(fs.readFileSync("./artifacts/contracts/TelegramTokenFactory.sol/TelegramTokenFactory.json", "utf8"));

const owner = await pub.readContract({ address: FACTORY, abi: artifact.abi, functionName: "owner" });
console.log("current owner:", owner);

// block of failed tx
const failedTxBlock = 5892979n; // we do not know, but let's replay at specific block
const hash = "0x03f6afb49219a65ebb539a58bd47e8f22593b9b5748f067f19d9c9d2525d64ef" as `0x${string}`;
const r = await pub.getTransactionReceipt({ hash });
console.log("failed tx block:", r.blockNumber);

const ownerAt = await pub.readContract({ address: FACTORY, abi: artifact.abi, functionName: "owner", blockNumber: r.blockNumber - 1n });
console.log("owner right before failed tx:", ownerAt);
