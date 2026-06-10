import "dotenv/config";
import {
  createPublicClient,
  createWalletClient,
  decodeEventLog,
  http,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK is not set in .env");

const TOKEN_FACTORY_ADDRESS = process.env.TOKEN_FACTORY_ADDRESS;
if (!TOKEN_FACTORY_ADDRESS) {
  throw new Error("TOKEN_FACTORY_ADDRESS is not set in .env");
}

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const account = privateKeyToAccount(pk as `0x${string}`);

const tokenName = process.env.TOKEN_NAME ?? "Cyberia Voting Token";
const tokenSymbol = process.env.TOKEN_SYMBOL ?? "CVOTE";
const tokenOwner =
  (process.env.TOKEN_OWNER as `0x${string}` | undefined) ?? account.address;

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

const publicClient = createPublicClient({
  chain: cyberia,
  transport: http(RPC_URL),
});

const walletClient = createWalletClient({
  chain: cyberia,
  transport: http(RPC_URL),
  account,
});

const factoryAddress = TOKEN_FACTORY_ADDRESS as `0x${string}`;

console.log("Factory:", factoryAddress);
console.log("Caller:", account.address);
console.log("Token owner:", tokenOwner);
console.log("Creating:", tokenName, `(${tokenSymbol})`);

const factoryOwner = await publicClient.readContract({
  address: factoryAddress,
  abi: artifact.abi,
  functionName: "owner",
});
console.log("Factory owner:", factoryOwner);

const { request } = await publicClient.simulateContract({
  address: factoryAddress,
  abi: artifact.abi,
  functionName: "createToken",
  args: [tokenName, tokenSymbol, tokenOwner],
  account,
});

const hash = await walletClient.writeContract(request);
console.log("Transaction hash:", hash);

const receipt = await publicClient.waitForTransactionReceipt({ hash });
console.log("Status:", receipt.status);
console.log("Gas used:", receipt.gasUsed.toString());

for (const log of receipt.logs) {
  try {
    const event = decodeEventLog({
      abi: artifact.abi,
      data: log.data,
      topics: log.topics,
    });

    if (event.eventName === "TokenCreated") {
      console.log("Token address:", event.args.token);
    }
  } catch {}
}
