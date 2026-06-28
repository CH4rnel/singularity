#!/usr/bin/env -S npx tsx
/**
 * End-to-end smoke test: drives the runtime through several turns with the
 * offline mock model and a real Cyberia chain read. Run: npm run smoke
 */
import { mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createAgent } from "../src/index.js";
import { lain } from "../src/characters/lain.js";

async function main() {
  const dataDir = mkdtempSync(join(tmpdir(), "lainos-smoke-"));
  const agent = await createAgent({ character: lain, dataDir });
  const room = "smoke";

  const say = async (text: string) => {
    const res = await agent.handleMessage({ roomId: room, userId: "tester", text });
    console.log(`\n  you> ${text}`);
    console.log(`  lain> ${res.text}`);
    if (res.actions.length) {
      console.log(`  [actions] ${res.actions.map((a) => `${a.name}=${a.result.ok}`).join(", ")}`);
    }
    return res;
  };

  await say("my name is operator");
  await say("who are you?");
  const bal = await say(
    "what is the balance of 0x0000000000000000000000000000000000000000?",
  );

  // --- assertions ---
  const facts = await agent.memory.facts(50);
  const learnedName = facts.some((f) => /operator/i.test(f));
  const ranBalance = bal.actions.some((a) => a.name === "check_balance" && a.result.ok);
  const nullIsZero = bal.actions.some(
    (a) => a.name === "check_balance" && a.result.data?.balance === "0",
  );

  console.log("\n=== assertions ===");
  console.log(`fact 'operator' learned : ${learnedName ? "PASS" : "FAIL"}`);
  console.log(`check_balance ran ok     : ${ranBalance ? "PASS" : "FAIL"}`);
  console.log(`null address == 0 CYBER  : ${nullIsZero ? "PASS" : "FAIL"}`);

  await agent.stop();
  const ok = learnedName && ranBalance && nullIsZero;
  console.log(`\n${ok ? "✅ smoke OK" : "❌ smoke FAILED"}`);
  process.exit(ok ? 0 : 1);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
