import "dotenv/config";
import { createWalletClient, createPublicClient, http, decodeEventLog } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const cyberia = { ...mainnet, id: 49406, name: "Cyberia", nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 } };
const RPC = process.env.RPC_URL ?? "https://rpc.cyberia.church";
const FACTORY = "0x9e672AC969497edD7D5f6233FF3b237236627151" as `0x${string}`;
const artifact = JSON.parse(fs.readFileSync("./artifacts/contracts/TelegramTokenFactory.sol/TelegramTokenFactory.json", "utf8"));

const pk = process.env.DEPLOYER_PK!.startsWith("0x") ? process.env.DEPLOYER_PK! : "0x" + process.env.DEPLOYER_PK!;
const acct = privateKeyToAccount(pk as `0x${string}`);
const pub = createPublicClient({ chain: cyberia, transport: http(RPC) });
const wallet = createWalletClient({ chain: cyberia, transport: http(RPC), account: acct });

// The chatId from the failed tx: fffffffffffffffffffffffffffffffffffffffffffffffffffffffec868c619
// That is -20488679 as int64. Parse from two's-complement-256 -> int64:
const chatId = -20488679n;
console.log("caller:", acct.address);
console.log("chatId:", chatId.toString());

// Check if already minted
const existing = await pub.readContract({ address: FACTORY, abi: artifact.abi, functionName: "tokenOfChat", args: [chatId] });
console.log("existing tokenOfChat:", existing);
if (existing !== "0x0000000000000000000000000000000000000000") {
  console.log("Already exists, bailing.");
  process.exit(0);
}

const estimated = await pub.estimateContractGas({
  address: FACTORY, abi: artifact.abi, functionName: "createToken",
  args: ["Bots", "BOTS", chatId, acct.address], account: acct,
});
console.log("estimated gas:", estimated.toString());

const { request } = await pub.simulateContract({
  address: FACTORY, abi: artifact.abi, functionName: "createToken",
  args: ["Bots", "BOTS", chatId, acct.address], account: acct,
  gas: estimated * 125n / 100n + 50_000n,
});
const hash = await wallet.writeContract(request);
console.log("tx:", hash);
const r = await pub.waitForTransactionReceipt({ hash });
console.log("status:", r.status, "gasUsed:", r.gasUsed.toString());
for (const log of r.logs) {
  try {
    const ev = decodeEventLog({ abi: artifact.abi, data: log.data, topics: log.topics });
    if (ev.eventName === "TokenCreated") {
      console.log("TOKEN:", (ev.args as any).token);
    }
  } catch {}
}
