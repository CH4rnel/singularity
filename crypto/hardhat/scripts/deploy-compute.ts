/**
 * Deploys CyberiaCompute — containers paid for by the second in native CYBER —
 * and, optionally, the first ServiceTreasury that pays for one.
 *
 * The deployer becomes the owner of both. Ownership moves to the DAO's
 * timelock afterwards (`transferOwnership`), once a provider is approved and
 * the first lease has run: until then the owner is who approves providers and
 * slashes bonds, and that should be somebody who can act the same day.
 *
 * Uses ethers rather than viem, like the other Cyberia deploy scripts: the
 * polygon-edge JSON-RPC rejects part of viem's request pipeline.
 *
 * Usage:
 *   npx hardhat compile && npx tsx scripts/deploy-compute.ts [service]
 *
 * env:
 *   DEPLOYER_PK                 — owner EOA
 *   CYBERIA_RPC_URL             — defaults to https://rpc.cyberia.church
 *   COMPUTE_FEE_RECIPIENT       — protocol fee sink (default: the deployer; meant to be the node-rewards pool)
 *   COMPUTE_FEE_BPS             — default 200 (2%), contract ceiling 1000
 *   COMPUTE_MIN_BOND_CYBER      — default 100
 *   COMPUTE_GUARDIAN            — the service's operations key (treasury only)
 *   COMPUTE_RUNWAY_DAYS         — default 14
 *   COMPUTE_MAX_REFILL_CYBER    — per UTC day, default 5
 */
import "dotenv/config";
import { ethers } from "ethers";
import * as fs from "fs";
import * as path from "path";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const artifact = (name: string) =>
  JSON.parse(fs.readFileSync(`./artifacts/contracts/compute/${name}.sol/${name}.json`, "utf8"));

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const CHAIN_ID = Number(process.env.EVM_CHAIN_ID ?? 49406);
const network = new ethers.Network("cyberia", CHAIN_ID);
const provider = new ethers.JsonRpcProvider(RPC_URL, network, { staticNetwork: network });
const wallet = new ethers.Wallet(pk, provider);

const service = process.argv[2];
const file = path.join("deployments", "cyberia-compute.json");

async function main() {
  const existing = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, "utf8")) : null;
  let computeAddress: string = existing?.address;

  if (!computeAddress) {
    const feeRecipient = process.env.COMPUTE_FEE_RECIPIENT ?? wallet.address;
    const feeBps = Number(process.env.COMPUTE_FEE_BPS ?? 200);
    const minBond = ethers.parseEther(process.env.COMPUTE_MIN_BOND_CYBER ?? "100");
    console.log("Deploying CyberiaCompute…");
    console.log("  owner:", wallet.address, " fee:", feeBps, "bps to", feeRecipient);
    const a = artifact("CyberiaCompute");
    // polygon-edge rejects eth_estimateGas for deployments (to: null).
    const c = await new ethers.ContractFactory(a.abi, a.bytecode, wallet).deploy(
      wallet.address, feeRecipient, feeBps, minBond, { gasLimit: 3_000_000 },
    );
    await c.waitForDeployment();
    computeAddress = await c.getAddress();
    console.log("CyberiaCompute deployed at:", computeAddress);
  }

  const treasuries: Record<string, string> = { ...(existing?.treasuries ?? {}) };
  if (service && !treasuries[service]) {
    const guardian = process.env.COMPUTE_GUARDIAN ?? wallet.address;
    const runway = BigInt(Number(process.env.COMPUTE_RUNWAY_DAYS ?? 14) * 86_400);
    const maxPerDay = ethers.parseEther(process.env.COMPUTE_MAX_REFILL_CYBER ?? "5");
    console.log(`Deploying ServiceTreasury for ${service}…`);
    const a = artifact("ServiceTreasury");
    const t = await new ethers.ContractFactory(a.abi, a.bytecode, wallet).deploy(
      computeAddress, service, wallet.address, guardian, runway, maxPerDay, 0n, { gasLimit: 2_500_000 },
    );
    await t.waitForDeployment();
    treasuries[service] = await t.getAddress();
    console.log(`ServiceTreasury(${service}) deployed at:`, treasuries[service]);
  }

  fs.writeFileSync(
    file,
    `${JSON.stringify(
      {
        chainId: CHAIN_ID,
        rpc: RPC_URL,
        _doc: "Compute leases paid by the second in native CYBER (CyberiaCompute) and the per-service treasuries that pay them. Provider agent: services/compute-agent.",
        address: computeAddress,
        owner: existing?.owner ?? wallet.address,
        deployedAt: existing?.deployedAt ?? new Date().toISOString(),
        treasuries,
      },
      null,
      2,
    )}\n`,
  );
  console.log("\nWrote", file);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
