#!/usr/bin/env -S npx tsx
/** Run the Wired game-auth server (model B: signs WiredForge entry tickets). */
import "dotenv/config";
import type { Address, Hex } from "viem";
import { createWiredServer } from "../src/wired/server.js";
import { SessionStore } from "../src/wired/sessions.js";
import { TicketSigner } from "../src/wired/signer.js";

const DEFAULT_FORGE = "0x2daa4A79EC2224AD02D9D4eBf937924b76F669Fa";

function main() {
  const pk = process.env.CYBERIA_AGENT_PK;
  if (!pk || !/^0x[0-9a-fA-F]{64}$/.test(pk)) {
    console.error(
      "CYBERIA_AGENT_PK (0x + 64 hex) is required to sign tickets.\n" +
        "Its address must equal WiredForge.signer() on-chain (currently the deployer).",
    );
    process.exit(1);
  }
  const contract = (process.env.WIRED_FORGE_ADDRESS ?? DEFAULT_FORGE) as Address;
  const chainId = Number(process.env.WIRED_CHAIN_ID ?? 49406);

  const signer = new TicketSigner({ privateKey: pk as Hex, chainId, verifyingContract: contract });
  const sessions = new SessionStore();
  createWiredServer({ signer, sessions, port: Number(process.env.WIRED_HTTP_PORT ?? 7788) });
}

main();
