import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const TELEGRAM_ADDRESS = "0x02Bad7dCaD174D92FCE2baBBd0cE1A653b487f04";
const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK is not set in .env");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const artifact = JSON.parse(fs.readFileSync("./artifacts/contracts/Telegram.sol/Telegram.json", "utf8"));

const account = privateKeyToAccount(pk as `0x${string}`);

const client = createWalletClient({
  chain: {
    ...mainnet,
    id: 49406,
    name: "Cyberia",
    nativeCurrency: { name: "Ether", symbol: "ETH", decimals: 18 },
  },
  transport: http("https://rpc.cyberia.church"),
  account,
});

const RECIPIENT = client.account.address;
const AMOUNT = 1_000_000n * 10n ** 18n; // 1M tokens

console.log("Minting", AMOUNT, "TG to", RECIPIENT);

const hash = await client.writeContract({
  address: TELEGRAM_ADDRESS,
  abi: artifact.abi,
  functionName: "mint",
  args: [RECIPIENT, AMOUNT],
});

console.log("Transaction hash:", hash);

const publicClient = createPublicClient({
  chain: {
    ...mainnet,
    id: 49406,
    name: "Cyberia",
  },
  transport: http("https://rpc.cyberia.church"),
});

const receipt = await publicClient.waitForTransactionReceipt({ hash });
console.log("Minted! Block:", receipt.blockNumber);