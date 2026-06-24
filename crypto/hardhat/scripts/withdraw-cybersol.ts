import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  parseEther,
  formatEther,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

// Owner withdrawal from the CyberSolSwap redeemer. Pulls native CYBER liquidity
// (withdrawNative) or collected CYBER.sol (withdrawTokens). Configured via env
// because `hardhat run` does not forward positional args:
//
//   ASSET   native (default) | token       what to withdraw
//   AMOUNT  human units, or "all" (default) to drain the full balance
//   TO      recipient address (default: the caller / owner)
//
// Examples:
//   npx hardhat run scripts/withdraw-cybersol.ts --network cyberia                 # all native CYBER -> self
//   ASSET=token npx hardhat run scripts/withdraw-cybersol.ts --network cyberia     # all CYBER.sol -> self
//   ASSET=native AMOUNT=0.01 TO=0xabc... npx hardhat run scripts/withdraw-cybersol.ts --network cyberia
//
// The swap contract address is read from CYBERSOL_SWAP or
// deployments/cyberia-cybersol-swap.json; CYBER.sol from the bridge registry.

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");
const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "CYBER", symbol: "CYBER", decimals: 18 },
};

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

function resolveSwapAddress(): `0x${string}` {
  if (process.env.CYBERSOL_SWAP) return process.env.CYBERSOL_SWAP as `0x${string}`;
  const path = "./deployments/cyberia-cybersol-swap.json";
  if (!fs.existsSync(path)) {
    throw new Error(
      "CyberSolSwap address not found: set CYBERSOL_SWAP or deploy first (deploy-cybersol-swap.ts)",
    );
  }
  return JSON.parse(fs.readFileSync(path, "utf8")).address as `0x${string}`;
}

const swapAddress = resolveSwapAddress();
const bridge = JSON.parse(fs.readFileSync("./deployments/cyberia-bridge.json", "utf8"));
const cyberSol = (process.env.CYBER_SOL ?? bridge.wrappedCyberSol) as `0x${string}`;
if (!cyberSol) throw new Error("CYBER.sol address not found");

const asset = (process.env.ASSET ?? "native").toLowerCase();
if (asset !== "native" && asset !== "token") {
  throw new Error(`ASSET must be "native" or "token", got "${asset}"`);
}
const to = (process.env.TO ?? account.address) as `0x${string}`;

const erc20Abi = [
  { name: "balanceOf", type: "function", stateMutability: "view", inputs: [{ name: "a", type: "address" }], outputs: [{ type: "uint256" }] },
] as const;

const swapAbi = [
  { name: "owner", type: "function", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
  { name: "withdrawNative", type: "function", stateMutability: "nonpayable", inputs: [{ name: "to", type: "address" }, { name: "amount", type: "uint256" }], outputs: [] },
  { name: "withdrawTokens", type: "function", stateMutability: "nonpayable", inputs: [{ name: "to", type: "address" }, { name: "amount", type: "uint256" }], outputs: [] },
] as const;

async function main() {
  // Guard: both withdraw functions are onlyOwner, so fail early with a clear
  // message instead of a raw revert if the deployer key is not the owner.
  const owner = await publicClient.readContract({
    address: swapAddress, abi: swapAbi, functionName: "owner",
  });
  if (owner.toLowerCase() !== account.address.toLowerCase()) {
    throw new Error(`caller ${account.address} is not the contract owner (${owner})`);
  }

  // Available balance of the chosen asset held by the contract.
  const available = asset === "native"
    ? await publicClient.getBalance({ address: swapAddress })
    : await publicClient.readContract({ address: cyberSol, abi: erc20Abi, functionName: "balanceOf", args: [swapAddress] });

  const amountArg = process.env.AMOUNT ?? "all";
  const drain = ["all", "max", ""].includes(amountArg.toLowerCase());
  const amount = drain ? available : parseEther(amountArg);

  const label = asset === "native" ? "CYBER" : "CYBER.sol";
  console.log("CyberSolSwap withdrawal");
  console.log("  swap contract :", swapAddress);
  console.log("  asset         :", asset, `(${label})`);
  console.log("  recipient     :", to);
  console.log("  available     :", formatEther(available), label);
  console.log("  withdrawing   :", formatEther(amount), label);

  if (amount <= 0n) throw new Error("nothing to withdraw (amount is 0)");
  if (amount > available) {
    throw new Error(`amount ${formatEther(amount)} exceeds available ${formatEther(available)} ${label}`);
  }

  const fn = asset === "native" ? "withdrawNative" : "withdrawTokens";
  const hash = await walletClient.writeContract({
    address: swapAddress, abi: swapAbi, functionName: fn, args: [to, amount],
  });
  console.log("\n  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") throw new Error(`withdrawal reverted: ${hash}`);

  const remaining = asset === "native"
    ? await publicClient.getBalance({ address: swapAddress })
    : await publicClient.readContract({ address: cyberSol, abi: erc20Abi, functionName: "balanceOf", args: [swapAddress] });
  console.log("  status:", receipt.status, "gasUsed:", receipt.gasUsed.toString());
  console.log("  contract", label, "remaining:", formatEther(remaining));
}

main().catch((error) => {
  console.error(`FATAL: ${error.shortMessage || error.message}`);
  process.exit(1);
});
