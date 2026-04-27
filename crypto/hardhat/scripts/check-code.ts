import { createPublicClient, http, keccak256 } from "viem";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const cyberia = { ...mainnet, id: 49406, name: "Cyberia", nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 } };
const pub = createPublicClient({ chain: cyberia, transport: http("https://rpc.cyberia.church") });
const FACTORY = "0x9e672AC969497edD7D5f6233FF3b237236627151" as `0x${string}`;

const code = await pub.getCode({ address: FACTORY });
console.log("bytecode size:", code?.length);
console.log("bytecode hash:", code ? keccak256(code) : null);

const artifact = JSON.parse(fs.readFileSync("./artifacts/contracts/TelegramTokenFactory.sol/TelegramTokenFactory.json", "utf8"));
console.log("expected deployed bytecode hash:", keccak256(artifact.deployedBytecode));
