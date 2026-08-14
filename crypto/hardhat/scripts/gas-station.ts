/**
 * Operator and ops CLI for CyberiaGasStation.
 *
 * The Laravel backend calls `claim` through this script with the *sponsor* key
 * (GAS_SPONSOR_PRIVATE_KEY) — deliberately not the bridge relayer, which is
 * shared with the Telegram minter and the DCA bot and whose transactions
 * already race each other for nonces. Everything else here is for a human.
 *
 * Reads need no key at all, so a status page never touches one.
 *
 * Usage:
 *   npx tsx scripts/gas-station.ts status     <station>
 *   npx tsx scripts/gas-station.ts can-claim  <station> <address>
 *   npx tsx scripts/gas-station.ts claim      <station> <address>
 *   npx tsx scripts/gas-station.ts fund       <station> <cyber>
 *   npx tsx scripts/gas-station.ts operator   <station> <address> <true|false>
 *   npx tsx scripts/gas-station.ts policy     <station> <drip> <ceiling> <cooldownHours> <dailyCap>
 *   npx tsx scripts/gas-station.ts pause      <station> <true|false>
 *   npx tsx scripts/gas-station.ts withdraw   <station> <address> <cyber>
 *
 * env:
 *   GAS_SPONSOR_PRIVATE_KEY  — operator EOA (claim)
 *   DEPLOYER_PK              — owner EOA (fund/operator/policy/pause/withdraw)
 *   CYBERIA_RPC_URL          — defaults to https://rpc.cyberia.church
 *
 * stdout (last line): JSON, so the caller parses one line and ignores the rest.
 */
import "dotenv/config";
import { ethers } from "ethers";

const RPC_URL =
  process.env.EVM_RPC_URL ||
  process.env.CYBERIA_RPC_URL ||
  "https://rpc.cyberia.church";
const CHAIN_ID = Number(process.env.EVM_CHAIN_ID || 49406);

const STATION_ABI = [
  "event Sponsored(address indexed to, uint256 amount, address indexed operator)",
  "function claim(address to) returns (uint256)",
  "function canClaim(address to) view returns (bool ok, string reason)",
  "function cooldownRemaining(address to) view returns (uint256)",
  "function claimCount(address to) view returns (uint256)",
  "function dripsLeft() view returns (uint256)",
  "function summary() view returns (uint256 tank, uint256 drip, uint256 ceiling, uint256 wait, uint256 cap, uint256 remaining, uint256 servedTotal, uint256 spentTotal, bool isPaused)",
  "function setOperator(address operator, bool allowed)",
  "function setPolicy(uint256 drip, uint256 ceiling, uint256 cooldown, uint256 dailyCap)",
  "function setPaused(bool paused)",
  "function withdraw(address to, uint256 amount)",
  "function isOperator(address) view returns (bool)",
  "function owner() view returns (address)",
];

/**
 * The claim itself: a value transfer plus four storage writes. Stated rather
 * than estimated so a node having a bad minute cannot turn a 0.01 CYBER gift
 * into an unbounded one.
 */
const CLAIM_GAS_LIMIT = 200_000n;

const network = new ethers.Network("cyberia", CHAIN_ID);
const provider = new ethers.JsonRpcProvider(RPC_URL, network, {
  staticNetwork: network,
});

const signerFrom = (raw: string | undefined, name: string): ethers.Wallet => {
  if (!raw) {
    console.error(`${name} not set`);
    process.exit(1);
  }

  return new ethers.Wallet(
    raw.startsWith("0x") ? raw : `0x${raw}`,
    provider,
  );
};

const operatorSigner = (): ethers.Wallet =>
  signerFrom(
    process.env.GAS_SPONSOR_PRIVATE_KEY ||
      process.env.BRIDGE_RELAYER_PRIVATE_KEY ||
      process.env.DEPLOYER_PK,
    "GAS_SPONSOR_PRIVATE_KEY",
  );

const ownerSigner = (): ethers.Wallet =>
  signerFrom(
    process.env.DEPLOYER_PK || process.env.BRIDGE_RELAYER_PRIVATE_KEY,
    "DEPLOYER_PK",
  );

const emit = (payload: Record<string, unknown>): void => {
  console.log(JSON.stringify(payload));
};

async function main() {
  const [, , action, stationAddr, ...rest] = process.argv;

  if (!action || !stationAddr) {
    console.error(
      "Usage: gas-station.ts <status|can-claim|claim|fund|operator|policy|pause|withdraw> <station> [args]",
    );
    process.exit(1);
  }

  const read = new ethers.Contract(stationAddr, STATION_ABI, provider);

  switch (action) {
    case "status": {
      const [
        tank,
        drip,
        ceiling,
        wait,
        cap,
        remaining,
        servedTotal,
        spentTotal,
        isPaused,
      ] = await read.summary();

      emit({
        station: stationAddr,
        tank: tank.toString(),
        tankCyber: ethers.formatEther(tank),
        drip: drip.toString(),
        dripCyber: ethers.formatEther(drip),
        ceiling: ceiling.toString(),
        cooldownSeconds: Number(wait),
        dailyCap: cap.toString(),
        remainingToday: remaining.toString(),
        dripsLeft: (await read.dripsLeft()).toString(),
        served: Number(servedTotal),
        spent: spentTotal.toString(),
        paused: isPaused,
      });

      return;
    }

    case "can-claim": {
      const [address] = rest;
      if (!address) throw new Error("address required");

      const [ok, reason] = await read.canClaim(address);

      emit({
        address,
        ok,
        reason,
        cooldownRemaining: Number(await read.cooldownRemaining(address)),
        claims: Number(await read.claimCount(address)),
      });

      return;
    }

    case "claim": {
      const [address] = rest;
      if (!address) throw new Error("address required");

      const signer = operatorSigner();
      const station = new ethers.Contract(stationAddr, STATION_ABI, signer);

      // Asked before it is paid for: a refusal should cost nothing, and the
      // reason should reach the caller as a reason rather than as a revert.
      const [ok, reason] = await read.canClaim(address);

      if (!ok) {
        emit({ status: "refused", address, reason });
        process.exit(2);
      }

      const tx = await station.claim(address, { gasLimit: CLAIM_GAS_LIMIT });
      const receipt = await tx.wait(1);

      // What was actually paid, taken from the receipt rather than from a
      // second read of the policy: the two can differ if policy changed in
      // between, and the caller is entitled to the number that moved.
      const sponsored = receipt?.logs
        .map((log) => {
          try {
            return station.interface.parseLog(log);
          } catch {
            return null;
          }
        })
        .find((parsed) => parsed?.name === "Sponsored");

      const amount = (sponsored?.args?.[1] ?? 0n) as bigint;

      emit({
        status: receipt?.status === 1 ? "success" : "failed",
        txHash: tx.hash,
        address,
        amount: amount.toString(),
        amountCyber: ethers.formatEther(amount),
        operator: signer.address,
      });

      return;
    }

    case "fund": {
      const [amount] = rest;
      if (!amount) throw new Error("amount in CYBER required");

      const signer = ownerSigner();
      const tx = await signer.sendTransaction({
        to: stationAddr,
        value: ethers.parseEther(amount),
        gasLimit: 60_000,
      });
      await tx.wait(1);

      const [tank] = await read.summary();
      emit({
        status: "success",
        txHash: tx.hash,
        tank: tank.toString(),
        tankCyber: ethers.formatEther(tank),
      });

      return;
    }

    case "operator": {
      const [address, allowed] = rest;
      if (!address || allowed === undefined) {
        throw new Error("address and true|false required");
      }

      const station = new ethers.Contract(stationAddr, STATION_ABI, ownerSigner());
      const tx = await station.setOperator(address, allowed === "true", {
        gasLimit: 80_000,
      });
      await tx.wait(1);

      emit({ status: "success", txHash: tx.hash, operator: address, allowed });

      return;
    }

    case "policy": {
      const [drip, ceiling, cooldownHours, dailyCap] = rest;
      if (!drip || !ceiling || !cooldownHours || !dailyCap) {
        throw new Error("drip, ceiling, cooldownHours and dailyCap required");
      }

      const station = new ethers.Contract(stationAddr, STATION_ABI, ownerSigner());
      const tx = await station.setPolicy(
        ethers.parseEther(drip),
        ethers.parseEther(ceiling),
        BigInt(Math.round(Number(cooldownHours) * 3600)),
        ethers.parseEther(dailyCap),
        { gasLimit: 120_000 },
      );
      await tx.wait(1);

      emit({ status: "success", txHash: tx.hash });

      return;
    }

    case "pause": {
      const [paused] = rest;
      const station = new ethers.Contract(stationAddr, STATION_ABI, ownerSigner());
      const tx = await station.setPaused(paused === "true", { gasLimit: 60_000 });
      await tx.wait(1);

      emit({ status: "success", txHash: tx.hash, paused: paused === "true" });

      return;
    }

    case "withdraw": {
      const [address, amount] = rest;
      if (!address || !amount) throw new Error("address and amount required");

      const station = new ethers.Contract(stationAddr, STATION_ABI, ownerSigner());
      const tx = await station.withdraw(address, ethers.parseEther(amount), {
        gasLimit: 80_000,
      });
      await tx.wait(1);

      emit({ status: "success", txHash: tx.hash });

      return;
    }

    default:
      console.error(`Unknown action: ${action}`);
      process.exit(1);
  }
}

main().catch((error) => {
  console.error(error?.message ?? error);
  process.exit(1);
});
