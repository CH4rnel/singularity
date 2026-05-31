import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");

const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const FACTORY_ADDRESS = "0xB0aC30907c04b61F1482e62eA66eF4562a690917" as const;
const FEE_TO = "0x54BB8a86Fc001d5BB3e6C7ad2e2f153E5Fa6d7f6" as const;

const ABI = [
  { name: "feeTo", type: "function", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
  { name: "feeToSetter", type: "function", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
  { name: "setFeeTo", type: "function", stateMutability: "nonpayable", inputs: [{ name: "_feeTo", type: "address" }], outputs: [] },
] as const;

async function main() {
  console.log("Deployer:", account.address);

  const currentFeeTo = await publicClient.readContract({ address: FACTORY_ADDRESS, abi: ABI, functionName: "feeTo" });
  const feeToSetter = await publicClient.readContract({ address: FACTORY_ADDRESS, abi: ABI, functionName: "feeToSetter" });

  console.log("Factory:", FACTORY_ADDRESS);
  console.log("feeToSetter:", feeToSetter);
  console.log("feeTo (current):", currentFeeTo);
  console.log("feeTo (new):", FEE_TO);

  if (account.address.toLowerCase() !== (feeToSetter as string).toLowerCase()) {
    throw new Error(`Deployer is not feeToSetter. feeToSetter = ${feeToSetter}`);
  }

  const hash = await walletClient.writeContract({
    address: FACTORY_ADDRESS,
    abi: ABI,
    functionName: "setFeeTo",
    args: [FEE_TO],
  });

  console.log("tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  console.log("status:", receipt.status);

  const newFeeTo = await publicClient.readContract({ address: FACTORY_ADDRESS, abi: ABI, functionName: "feeTo" });
  console.log("feeTo (confirmed):", newFeeTo);
}

main().catch((e) => { console.error(e); process.exit(1); });
