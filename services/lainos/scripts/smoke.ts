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
import { splitMessage } from "../src/clients/telegram.js";
import type { ForgeEvent, ForgeService } from "../src/plugins/forge/index.js";
import { parseRss, type ScoutService } from "../src/plugins/scout/index.js";
import type { SentinelService } from "../src/plugins/sentinel/index.js";

async function main() {
  // Forge: deterministic stub agent, no auto mode, no real repo edits.
  process.env.LAINOS_FORGE_CMD = 'echo "forge stub saw: $LAINOS_FORGE_PROMPT" | head -c 200';
  process.env.LAINOS_FORGE_AUTO = "0";

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

  // --- sentinel: watch the null address below 1 CYBER; one forced tick must fire ---
  const sentinel = agent.getService<SentinelService>("sentinel");
  let sentinelFired = false;
  let alertDelivered = false;
  if (sentinel) {
    await sentinel.addWatch({
      address: "0x0000000000000000000000000000000000000000",
      kind: "below",
      threshold: 1,
      note: "smoke",
    });
    await sentinel.tick();
    sentinelFired = sentinel.recentAlerts(5).some((a) => a.text.includes("below"));
    // The alerts provider should hand the alert to the next turn and mark it delivered.
    await say("anything happen while I was away?");
    alertDelivered = sentinel.recentAlerts(5).every((a) => a.delivered);
  }

  // --- telegram: message splitting respects the API limit ---
  const chunks = splitMessage(`${"a".repeat(3990)}\n${"b".repeat(3990)}`, 4000);
  const splitOk = chunks.length === 2 && chunks.every((c) => c.length <= 4000);

  // --- forge: log a wish, build it with the stub agent, expect "review" ---
  const forge = agent.getService<ForgeService>("forge");
  let wishLogged = false;
  let wishForged = false;
  if (forge) {
    const wish = await forge.addWish({ title: "smoke wish", reporter: "tester" });
    wishLogged = forge.listWishes("open").some((w) => w.id === wish.id);
    const finished = new Promise<ForgeEvent>((res) =>
      forge.onEvent((ev) => ev.kind === "job_finished" && res(ev)),
    );
    const job = await forge.buildWish(wish.id);
    const ev = await Promise.race([
      finished,
      new Promise<null>((res) => setTimeout(() => res(null), 15_000)),
    ]);
    wishForged =
      typeof job !== "string" &&
      ev !== null &&
      ev.job.status === "ok" &&
      forge.getWish(wish.id)?.status === "review";
  }

  // --- scout: RSS parser is sane, topics can be added and removed ---
  const rss = parseRss(
    `<rss><channel><item><title><![CDATA[Big zkVM news]]></title>` +
      `<link>https://example.com/a</link><pubDate>Fri, 10 Jul 2026 10:00:00 GMT</pubDate></item>` +
      `<item><title>Second &amp; last</title><link>https://example.com/b</link></item></channel></rss>`,
    "News",
  );
  const atom = parseRss(
    `<feed><entry><title>Atom post</title><link href="https://example.com/c"/>` +
      `<updated>2026-07-10T10:00:00Z</updated></entry></feed>`,
    "Reddit",
  );
  const rssOk =
    rss.length === 2 &&
    rss[0].title === "Big zkVM news" &&
    rss[1].title === "Second & last" &&
    atom.length === 1 &&
    atom[0].url === "https://example.com/c";
  const scout = agent.getService<ScoutService>("scout");
  let scoutOk = false;
  if (scout) {
    const topic = await scout.addTopic({ query: "lainos smoke", reporter: "tester" });
    const listed = scout.listTopics().some((t) => t.id === topic.id);
    const removed = await scout.removeTopic(topic.id);
    scoutOk = listed && removed && !scout.getTopic(topic.id);
  }

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
  console.log(`sentinel watch fired     : ${sentinelFired ? "PASS" : "FAIL"}`);
  console.log(`alert delivered in turn  : ${alertDelivered ? "PASS" : "FAIL"}`);
  console.log(`telegram splitMessage    : ${splitOk ? "PASS" : "FAIL"}`);
  console.log(`forge wish logged        : ${wishLogged ? "PASS" : "FAIL"}`);
  console.log(`forge wish -> review     : ${wishForged ? "PASS" : "FAIL"}`);
  console.log(`scout rss parser         : ${rssOk ? "PASS" : "FAIL"}`);
  console.log(`scout topic add/remove   : ${scoutOk ? "PASS" : "FAIL"}`);

  await agent.stop();
  const ok =
    learnedName && ranBalance && nullIsZero && sentinelFired && alertDelivered && splitOk &&
    wishLogged && wishForged && rssOk && scoutOk;
  console.log(`\n${ok ? "✅ smoke OK" : "❌ smoke FAILED"}`);
  process.exit(ok ? 0 : 1);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
