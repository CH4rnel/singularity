import "dotenv/config";
import { network } from "hardhat";

const phaseDuration = Number(process.env.ARENA_PHASE_DURATION ?? "300");
if (!Number.isSafeInteger(phaseDuration) || phaseDuration <= 0) {
  throw new Error("ARENA_PHASE_DURATION must be a positive integer");
}

const { ethers } = await network.connect({ network: "cyberia" });
const [deployer] = await ethers.getSigners();
if (!deployer) {
  throw new Error("Set CYBERIA_PRIVATE_KEY in game/SDK/.env before deployment");
}

console.log(
  `Deploying Cyberia Arena from ${deployer.address} (phase ${phaseDuration}s)`,
);
const arena = await ethers.deployContract("RockPaperScissors", [phaseDuration]);
await arena.waitForDeployment();
console.log(`ARENA_CONTRACT_ADDRESS=${await arena.getAddress()}`);
