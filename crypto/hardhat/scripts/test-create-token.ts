import "dotenv/config";
import { createWalletClient, createPublicClient, http, decodeEventLog } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK!;
const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;

const artifact = JSON.parse(fs.readFileSync("./artifacts/contracts/TelegramTokenFactory.sol/TelegramTokenFactory.json", "utf8"));

const FACTORY = "0x9e672AC969497edD7D5f6233FF3b237236627151" as `0x${string}`;

const cyberia = { ...mainnet, id: 49406, name: "Cyberia", nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 } };
const RPC = process.env.RPC_URL ?? "https://rpc.cyberia.church";

const account = privateKeyToAccount(pk as `0x${string}`);
const pub = createPublicClient({ chain: cyberia, transport: http(RPC) });
const wallet = createWalletClient({ chain: cyberia, transport: http(RPC), account });

console.log("caller:", account.address);
const owner = await pub.readContract({ address: FACTORY, abi: artifact.abi, functionName: "owner" });
console.log("factory owner:", owner);

const chatId = -100_000_000_000n + BigInt(Math.floor(Math.random() * 1000));
console.log("chatId:", chatId.toString());

try {
  // simulate first to get revert reason
  const { request } = await pub.simulateContract({
    address: FACTORY,
    abi: artifact.abi,
    functionName: "createToken",
    args: ["TestToken", "TEST", chatId, account.address],
    account,
  });
  console.log("simulate OK");
  const hash = await wallet.writeContract(request);
  console.log("tx:", hash);
  const r = await pub.waitForTransactionReceipt({ hash });
  console.log("status:", r.status);
  for (const log of r.logs) {
    try {
      const ev = decodeEventLog({ abi: artifact.abi, data: log.data, topics: log.topics });
      console.log("event:", ev.eventName, ev.args);
    } catch {}
  }
} catch (e: any) {
  console.error("ERROR:", e.shortMessage ?? e.message);
  if (e.cause) console.error("cause:", e.cause.shortMessage ?? e.cause.message);
  if (e.metaMessages) console.error(e.metaMessages.join("\n"));
}
