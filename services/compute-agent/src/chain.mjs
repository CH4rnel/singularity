// CyberiaCompute over JSON-RPC. The ABI is the subset the agent and the
// watcher call; the contract is crypto/hardhat/contracts/compute/CyberiaCompute.sol.

import { Contract, JsonRpcProvider, Wallet } from "ethers";

export const COMPUTE_ABI = [
  "function lease(uint256 id) view returns (tuple(address payer, address provider, address manager, uint128 rate, uint128 balance, uint64 settledAt, uint64 pausedAt, bool closed, bytes32 specHash, string specURI))",
  "function leasesOfProvider(address provider) view returns (uint256[])",
  "function claimable(address who) view returns (uint256)",
  "function settle(uint256 id)",
  "function withdraw()",
  "function pause(uint256 id)",
  "function resume(uint256 id)",
  "function reportUsage(uint256 id, uint64 periodStart, uint64 periodEnd, bytes32 usageHash, string uri)",
];

export function connect({ rpcUrl, address, privateKey }) {
  const provider = new JsonRpcProvider(rpcUrl);
  const signer = privateKey ? new Wallet(privateKey, provider) : null;
  const contract = new Contract(address, COMPUTE_ABI, signer ?? provider);
  return { provider, signer, contract };
}

/** A lease as lease.mjs expects it: bigints and a plain boolean. */
export function toLease(raw) {
  return {
    payer: raw.payer,
    provider: raw.provider,
    manager: raw.manager,
    rate: BigInt(raw.rate),
    balance: BigInt(raw.balance),
    settledAt: BigInt(raw.settledAt),
    pausedAt: BigInt(raw.pausedAt),
    closed: Boolean(raw.closed),
    specHash: raw.specHash,
    specURI: raw.specURI,
  };
}
