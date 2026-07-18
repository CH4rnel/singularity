#!/usr/bin/env -S npx tsx
/**
 * End-to-end smoke test: drives the runtime through several turns with the
 * offline mock model and a real Cyberia chain read. Run: npm run smoke
 */
import { mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createAgent } from "../src/index.js";
import { lain } from "../src/characters/lain.js";
import { splitMessage } from "../src/clients/telegram.js";
import { stripReasoning, ThinkTagFilter } from "../src/models/openrouter.js";
import {
  localDay,
  normalizeChannel,
  parseChannelPosts,
  type ChannelWatchService,
} from "../src/plugins/channel/index.js";
import type { CyberiaChainService } from "../src/plugins/cyberia/index.js";
import type { ForgeEvent, ForgeService } from "../src/plugins/forge/index.js";
import {
  parseContributionDay,
  type GithubStreakService,
} from "../src/plugins/github/index.js";
import { looksLikeNothing, parseRss, type ScoutService } from "../src/plugins/scout/index.js";
import type { SentinelService } from "../src/plugins/sentinel/index.js";
import { resolveOperatorChatId, telegramPlugin } from "../src/plugins/telegram/index.js";

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

  // --- cyberia: the agent can mint its own wallet; the key never surfaces ---
  const chain = agent.getService<CyberiaChainService>("cyberia-chain");
  let walletOk = false;
  if (chain) {
    if (chain.agentAddress) {
      // A signer is preconfigured (CYBERIA_AGENT_PK) — create_wallet must be a no-op.
      const res = await chain.createWallet();
      walletOk = !res.created && res.address === chain.agentAddress;
    } else {
      const first = await chain.createWallet();
      const again = await chain.createWallet();
      const stored = readFileSync(join(dataDir, "wallet.json"), "utf8");
      walletOk =
        first.created &&
        /^0x[0-9a-fA-F]{40}$/.test(first.address) &&
        !again.created &&
        again.address === first.address &&
        chain.agentAddress === first.address &&
        stored.includes(first.address);
    }
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

  // --- openrouter: a reasoning model's chain of thought must never surface ---
  const filter = new ThinkTagFilter();
  const streamed =
    filter.push("<thi") +
    filter.push("nk>secret plan ") +
    filter.push("continues</th") +
    filter.push("ink>ответ: ") +
    filter.push("готово") +
    filter.flush();
  const reasoningOk =
    stripReasoning("<think>hidden</think>привет") === "привет" &&
    stripReasoning("lost opener reasoning…</think>\n\nреальный ответ") === "реальный ответ" &&
    stripReasoning("<think>ran out of tokens mid-thought") === "" &&
    stripReasoning("обычный ответ без тегов") === "обычный ответ без тегов" &&
    streamed === "ответ: готово";

  // --- github: contribution-graph parsing + watch add/remove ---
  const calendarHtml =
    `<td id="contribution-day-component-2-3" data-date="2026-07-16" data-level="0" class="ContributionCalendar-day"></td>` +
    `<td id="contribution-day-component-2-4" data-date="2026-07-15" data-level="3" class="ContributionCalendar-day"></td>` +
    `<tool-tip for="contribution-day-component-2-3">No contributions on July 16th.</tool-tip>` +
    `<tool-tip for="contribution-day-component-2-4">7 contributions on July 15th.</tool-tip>`;
  const emptyDay = parseContributionDay(calendarHtml, "2026-07-16");
  const busyDay = parseContributionDay(calendarHtml, "2026-07-15");
  const parseOk =
    emptyDay?.level === 0 &&
    emptyDay?.count === 0 &&
    busyDay?.level === 3 &&
    busyDay?.count === 7 &&
    parseContributionDay(calendarHtml, "2026-07-14") === null;
  const github = agent.getService<GithubStreakService>("github-streak");
  let githubOk = false;
  if (github) {
    const watch = await github.addWatch({ username: "cyberia-temple", reporter: "tester" });
    const rejected = await github.addWatch({ username: "not a user!!", reporter: "tester" });
    const listed = github.listWatches().some((w) => w.id === watch?.id);
    const removed = watch ? await github.removeWatch(watch.username) : false;
    githubOk = Boolean(watch) && rejected === null && listed && removed && parseOk;
  }

  // --- channel: preview parsing + watch add/remove ---
  const nowTs = Date.now();
  const previewHtml =
    `<div class="tgme_widget_message" data-post="cyberia/41">` +
    `<a class="tgme_widget_message_date"><time datetime="${new Date(nowTs - 3_600_000).toISOString()}" class="time">x</time></a></div>` +
    `<div class="tgme_widget_message" data-post="cyberia/42">` +
    `<a class="tgme_widget_message_date"><time datetime="${new Date(nowTs).toISOString()}" class="time">x</time></a></div>` +
    `<div class="tgme_widget_message" data-post="cyberia/1">` +
    `<a class="tgme_widget_message_date"><time datetime="2020-01-01T00:00:00+00:00" class="time">x</time></a></div>`;
  const activity = parseChannelPosts(previewHtml, localDay(new Date(nowTs)));
  const channelParseOk =
    activity.postsToday === 2 &&
    activity.lastPostAt === nowTs &&
    parseChannelPosts("<html>no posts here</html>", localDay()).lastPostAt === null &&
    normalizeChannel("https://t.me/s/cyberia_church?before=41") === "cyberia_church" &&
    normalizeChannel("@cyberia_church") === "cyberia_church";
  const channels = agent.getService<ChannelWatchService>("channel-watch");
  let channelOk = false;
  if (channels) {
    const watch = await channels.addWatch({ channel: "t.me/cyberia_church", reporter: "tester" });
    const rejected = await channels.addWatch({ channel: "not a channel!!", reporter: "tester" });
    const listed = channels.listWatches().some((w) => w.id === watch?.id);
    const removed = watch ? await channels.removeWatch(watch.channel) : false;
    channelOk = Boolean(watch) && rejected === null && listed && removed && channelParseOk;
  }

  // --- telegram hand: operator chat resolution + token gating (no network) ---
  const tgDir = mkdtempSync(join(tmpdir(), "lainos-tg-"));
  const tgEmptyDir = mkdtempSync(join(tmpdir(), "lainos-tg-empty-"));
  writeFileSync(join(tgDir, "telegram.json"), JSON.stringify({ chats: [777, -100123] }));
  const settings =
    (over: Record<string, string | undefined>) =>
    (k: string): string | undefined =>
      over[k];
  const sendAction = telegramPlugin.actions![0]!;
  const tgRuntime = (over: Record<string, string | undefined>) =>
    ({ getSetting: settings(over) }) as never;
  const telegramOk =
    (await resolveOperatorChatId(settings({ TELEGRAM_OPERATOR_CHAT_ID: "42" }))) === "42" &&
    (await resolveOperatorChatId(settings({ TELEGRAM_ALLOWED_CHATS: " 123 ,456" }))) === "123" &&
    (await resolveOperatorChatId(settings({ LAINOS_DATA_DIR: tgDir }))) === "777" &&
    (await resolveOperatorChatId(settings({ LAINOS_DATA_DIR: tgEmptyDir }))) === null &&
    !(await sendAction.validate(tgRuntime({}), {} as never)) &&
    (await sendAction.validate(tgRuntime({ TELEGRAM_BOT_TOKEN: "x" }), {} as never));

  // --- scout: "nothing found" replies must read as silence, digests must not ---
  const nothingOk =
    looksLikeNothing("NOTHING") &&
    looksLikeNothing("NOTHING.") &&
    looksLikeNothing("Ничего не найдено по теме Cyberia (сеть, Ritual DEX, LainOS, токен CYBER).") &&
    looksLikeNothing("No relevant news.") &&
    looksLikeNothing("") &&
    !looksLikeNothing("Solana выкатила Firedancer в мейннет — детали: https://example.com/a") &&
    !looksLikeNothing("Главное за день: релиз zkVM 2.0.\n- подробности — https://example.com/b");

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
  console.log(`agent wallet lifecycle   : ${walletOk ? "PASS" : "FAIL"}`);
  console.log(`forge wish logged        : ${wishLogged ? "PASS" : "FAIL"}`);
  console.log(`forge wish -> review     : ${wishForged ? "PASS" : "FAIL"}`);
  console.log(`scout rss parser         : ${rssOk ? "PASS" : "FAIL"}`);
  console.log(`scout topic add/remove   : ${scoutOk ? "PASS" : "FAIL"}`);
  console.log(`scout NOTHING = silence  : ${nothingOk ? "PASS" : "FAIL"}`);
  console.log(`reasoning never leaks    : ${reasoningOk ? "PASS" : "FAIL"}`);
  console.log(`github streak watch      : ${githubOk ? "PASS" : "FAIL"}`);
  console.log(`channel post watch       : ${channelOk ? "PASS" : "FAIL"}`);
  console.log(`telegram send hand       : ${telegramOk ? "PASS" : "FAIL"}`);

  await agent.stop();
  const ok =
    learnedName && ranBalance && nullIsZero && sentinelFired && alertDelivered && splitOk &&
    walletOk && wishLogged && wishForged && rssOk && scoutOk && nothingOk && reasoningOk &&
    githubOk && channelOk && telegramOk;
  console.log(`\n${ok ? "✅ smoke OK" : "❌ smoke FAILED"}`);
  process.exit(ok ? 0 : 1);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
