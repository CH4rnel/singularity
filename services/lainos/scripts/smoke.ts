#!/usr/bin/env -S npx tsx
/**
 * End-to-end smoke test: drives the runtime through several turns with the
 * offline mock model and a real Cyberia chain read. Run: npm run smoke
 */
import { chmodSync, mkdtempSync, readdirSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { AgentRuntime, createAgent, FileMemoryStore, type ModelProvider, type ModelRequest } from "../src/index.js";
import { lain } from "../src/characters/lain.js";
import { splitMessage } from "../src/clients/telegram.js";
import { stripReasoning, ThinkTagFilter } from "../src/models/openrouter.js";
import { resolveChatProviderKind, SwitchableModelProvider } from "../src/models/routing.js";
import {
  localDay,
  normalizeChannel,
  parseChannelPosts,
  type ChannelWatchService,
} from "../src/plugins/channel/index.js";
import type { CyberiaChainService } from "../src/plugins/cyberia/index.js";
import { applyBuy, applySell, TradeJournal, type Position } from "../src/plugins/cyberia/journal.js";
import { forgePlugin, ForgeService, formatForgeJobs, type ForgeEvent } from "../src/plugins/forge/index.js";
import { InitiativeService } from "../src/plugins/initiative/index.js";
import type { SkillsService } from "../src/plugins/skills/index.js";
import {
  parseContributionDay,
  type GithubStreakService,
} from "../src/plugins/github/index.js";
import {
  cleanPresenceMessage,
  DEFAULT_PRESENCE_MESSAGES,
  isNoisyWiredPulseMessage,
} from "../src/plugins/presence/index.js";
import { looksLikeNothing, parseRss, type ScoutService } from "../src/plugins/scout/index.js";
import type { SentinelService } from "../src/plugins/sentinel/index.js";
import { resolveOperatorChatId, telegramPlugin } from "../src/plugins/telegram/index.js";

async function main() {
  // Forge: deterministic stub agent, no auto mode, no real repo edits.
  process.env.LAINOS_FORGE_CMD = 'echo "forge stub saw: $LAINOS_FORGE_PROMPT" | head -c 200';
  process.env.LAINOS_FORGE_AUTO = "0";
  // Skills: hot-load from a scratch dir, not the repo's skills/.
  process.env.LAINOS_SKILLS_DIR = mkdtempSync(join(tmpdir(), "lainos-skills-"));

  // --- runtime contract: tool follow-ups must receive both text and data ---
  const toolResultDir = mkdtempSync(join(tmpdir(), "lainos-tool-result-"));
  let toolModelCalls = 0;
  let toolResultContractOk = false;
  const toolResultModel: ModelProvider = {
    name: "tool-result-stub",
    modelFor: () => "tool-result-stub",
    async generate(request: ModelRequest) {
      toolModelCalls += 1;
      if (toolModelCalls === 1) {
        return {
          text: "",
          toolCalls: [{ name: "result_probe", input: {} }],
          model: "tool-result-stub",
        };
      }
      const followup = request.messages.at(-1)?.content ?? "";
      toolResultContractOk =
        followup.includes('"ok":true') &&
        followup.includes('"text":"human-readable"') &&
        followup.includes('"data":{"detail":"structured"}');
      return { text: "done", toolCalls: [], model: "tool-result-stub" };
    },
  };
  const toolResultRuntime = new AgentRuntime({
    character: lain,
    memory: new FileMemoryStore(toolResultDir),
    model: toolResultModel,
    settings: { LAINOS_DATA_DIR: toolResultDir, LAINOS_MODEL_TRANSCRIPTS: "0" },
  });
  toolResultRuntime.use({
    name: "tool-result-smoke",
    description: "tool result contract smoke",
    actions: [
      {
        name: "result_probe",
        similes: [],
        description: "Return text and data.",
        examples: [],
        async validate() {
          return true;
        },
        async handler() {
          return { ok: true, text: "human-readable", data: { detail: "structured" } };
        },
      },
    ],
  });
  await toolResultRuntime.start();
  await toolResultRuntime.handleMessage({ roomId: "tool-result", userId: "tester", text: "probe" });
  await toolResultRuntime.stop();

  // --- runtime guard: a missing-capability refusal must become a forge job ---
  const refusalDataDir = mkdtempSync(join(tmpdir(), "lainos-refusal-"));
  const refusalModel: ModelProvider = {
    name: "refusal-stub",
    modelFor: () => "refusal-stub",
    async generate(_request: ModelRequest) {
      return {
        text: "не могу выполнить: нет инструмента для этого действия",
        toolCalls: [],
        model: "refusal-stub",
      };
    },
  };
  const refusalRuntime = new AgentRuntime({
    character: lain,
    memory: new FileMemoryStore(refusalDataDir),
    model: refusalModel,
    settings: {
      ...process.env,
      LAINOS_DATA_DIR: refusalDataDir,
      LAINOS_FORGE_CMD: process.env.LAINOS_FORGE_CMD,
      LAINOS_FORGE_AUTO: "0",
    },
  });
  refusalRuntime.use({
    ...forgePlugin,
    services: [new ForgeService()],
  });
  await refusalRuntime.start();
  const refusal = await refusalRuntime.handleMessage({
    roomId: "refusal-smoke",
    userId: "tester",
    text: "сделай новый тестовый рабочий инструмент",
  });
  const autoLearnOk =
    refusal.actions.some((a) => a.name === "learn_skill" && a.result.ok) &&
    refusal.text.includes("План:") &&
    !refusal.text.includes("не могу выполнить");
  await refusalRuntime.stop();

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
  await say("меня зовут Алиса");
  await say("who are you?");
  const bal = await say(
    "what is the balance of 0x0000000000000000000000000000000000000000?",
  );
  const pnl = await say("посчитай нереализованную прибыль/убыток");

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
    // The alerts provider should pass the alert to the next turn and mark it delivered.
    await say("anything happen while I was away?");
    alertDelivered = sentinel.recentAlerts(5).every((a) => a.delivered);
  }

  // --- cyberia: the agent can mint its own wallet; the key is never exposed ---
  const chain = agent.getService<CyberiaChainService>("cyberia-chain");
  let walletOk = false;
  let lainTokenKnown = false;
  if (chain) {
    lainTokenKnown =
      chain.resolveToken("LAIN")?.toLowerCase() ===
      "0x05cd1afd5b2df3cca6ceab80cbc21168ec981e8b";
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
  let wishEdited = false;
  let wishForged = false;
  let forgeJobsListed = false;
  let forgeJobsScrubbed = false;
  if (forge) {
    const wish = await forge.addWish({ title: "smoke wish", reporter: "tester" });
    wishLogged = forge.listWishes("open").some((w) => w.id === wish.id);
    const editWish = agent.actions.find((action) => action.name === "edit_wish");
    const editResult = editWish
      ? await editWish.handler(agent, {} as never, {
          id: wish.id,
          appendDetail: "Implement this specifically in the Laravel application.",
        })
      : null;
    wishEdited =
      editResult?.ok === true &&
      forge.getWish(wish.id)?.detail === "Implement this specifically in the Laravel application.";
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
      forge.getWish(wish.id)?.status === "done";
    if (typeof job !== "string") {
      job.summary = "final line API_TOKEN=dummy-token-value";
      const jobsText = formatForgeJobs(forge, { limit: 1 });
      forgeJobsListed =
        jobsText.includes(job.id) &&
        jobsText.includes(wish.id) &&
        jobsText.includes("[ok]") &&
        jobsText.includes("started:") &&
        jobsText.includes("ended:") &&
        jobsText.includes("result:");
      forgeJobsScrubbed =
        jobsText.includes("API_TOKEN=[redacted]") && !jobsText.includes("dummy-token-value");
    }
  }

  // --- forge provider selection: runtime switch persists and affects new jobs ---
  let forgeProviderSwitchOk = false;
  const switchDataDir = mkdtempSync(join(tmpdir(), "lainos-forge-provider-"));
  const fakeBinDir = mkdtempSync(join(tmpdir(), "lainos-forge-bin-"));
  for (const bin of ["claude", "codex"]) {
    const path = join(fakeBinDir, bin);
    writeFileSync(path, "#!/bin/sh\nexit 0\n");
    chmodSync(path, 0o755);
  }
  const prevPath = process.env.PATH;
  process.env.PATH = `${fakeBinDir}:${prevPath ?? ""}`;
  const forgeProviderSettings = {
    ...process.env,
    LAINOS_DATA_DIR: switchDataDir,
    LAINOS_FORGE_REPO: switchDataDir,
    LAINOS_FORGE_AUTO: "0",
    LAINOS_FORGE_CMD: "",
    LAINOS_FORGE_AGENT: "claude",
  };
  const switchRuntime = new AgentRuntime({
    character: lain,
    memory: new FileMemoryStore(switchDataDir),
    model: refusalModel,
    settings: forgeProviderSettings,
  });
  switchRuntime.use({
    ...forgePlugin,
    services: [new ForgeService()],
  });
  await switchRuntime.start();
  const switchForge = switchRuntime.getService<ForgeService>("forge")!;
  const initialProvider = switchForge.forgeProvider().selected;
  const switched = await switchForge.setProvider("codex");
  const switchWish = await switchForge.addWish({ title: "provider switch smoke", reporter: "tester" });
  const switchFinished = new Promise<ForgeEvent>((res) =>
    switchForge.onEvent((ev) => ev.kind === "job_finished" && res(ev)),
  );
  const switchJob = await switchForge.buildWish(switchWish.id);
  const switchEv = await Promise.race([
    switchFinished,
    new Promise<null>((res) => setTimeout(() => res(null), 15_000)),
  ]);
  await switchRuntime.stop();

  const restartRuntime = new AgentRuntime({
    character: lain,
    memory: new FileMemoryStore(switchDataDir),
    model: refusalModel,
    settings: forgeProviderSettings,
  });
  restartRuntime.use({
    ...forgePlugin,
    services: [new ForgeService()],
  });
  await restartRuntime.start();
  const restartedProvider = restartRuntime.getService<ForgeService>("forge")?.forgeProvider().selected;
  await restartRuntime.stop();
  process.env.PATH = prevPath;

  const storedForge = JSON.parse(readFileSync(join(switchDataDir, "forge.json"), "utf8")) as { selectedAgent?: string };
  forgeProviderSwitchOk =
    initialProvider === "claude" &&
    typeof switched !== "string" &&
    switched.provider === "codex" &&
    typeof switchJob !== "string" &&
    switchJob.agent === "codex" &&
    switchEv?.job.status === "ok" &&
    storedForge.selectedAgent === "codex" &&
    restartedProvider === "codex";

  // --- skills: she can write herself a tool and use it seconds later ---
  const skillsSvc = agent.getService<SkillsService>("skills");
  let skillsOk = false;
  if (skillsSvc) {
    const module = (marker: string) =>
      `export default { name: "smoke_echo", description: "echo for smoke",\n` +
      `  parameters: { type: "object", properties: { text: { type: "string" } } },\n` +
      `  async handler(_rt, _st, params) { return { ok: true, text: "${marker}:" + params.text }; } };\n`;
    await skillsSvc.createSkill("smoke_echo", module("echo"));
    const live = agent.actions.find((a) => a.name === "smoke_echo");
    const first = live ? await live.handler(agent, {} as never, { text: "wired" }) : null;
    // Hot overwrite must replace the live action, not duplicate it.
    await skillsSvc.createSkill("smoke_echo", module("echo2"));
    const second = await agent.actions
      .find((a) => a.name === "smoke_echo")!
      .handler(agent, {} as never, { text: "wired" });
    const copies = agent.actions.filter((a) => a.name === "smoke_echo").length;
    let brokenRejected = false;
    try {
      await skillsSvc.createSkill("smoke_broken", "export default {};");
    } catch {
      brokenRejected = true;
    }
    let shadowRejected = false;
    try {
      await skillsSvc.createSkill("check_balance", module("shadow"));
    } catch {
      shadowRejected = true;
    }
    skillsOk =
      first?.text === "echo:wired" &&
      second.text === "echo2:wired" &&
      copies === 1 &&
      brokenRejected &&
      shadowRejected &&
      !agent.actions.some((a) => a.name === "smoke_broken");
  }

  // --- trade journal: moving-average cost basis + realised PnL, persisted ---
  const posBase: Position = { token: "0xabc", symbol: "T", qtyWei: "0", costWei: "0" };
  const afterBuy1 = applyBuy(undefined, posBase, 100n, 10n);
  const afterBuy2 = applyBuy(afterBuy1, posBase, 100n, 30n);
  const afterSell = applySell(afterBuy2, 100n, 40n);
  const mathOk =
    afterBuy2.qtyWei === "200" &&
    afterBuy2.costWei === "40" &&
    afterSell.realizedWei === 20n &&
    afterSell.position.qtyWei === "100" &&
    afterSell.position.costWei === "20";
  const tj = new TradeJournal(join(dataDir, "tj-test.json"));
  await tj.load();
  await tj.recordBuy({ token: "0xabc", symbol: "T", qtyWei: 100n, cyberWei: 10n, txHash: "0x1" });
  const realized = await tj.recordSell({
    token: "0xABC", // case-insensitive position key
    symbol: "T",
    qtyWei: 50n,
    cyberWei: 20n,
    txHash: "0x2",
  });
  const tj2 = new TradeJournal(join(dataDir, "tj-test.json"));
  await tj2.load();
  const reloaded = tj2.positionOf("0xabc");
  const journalOk =
    mathOk && realized === 15n && reloaded?.qtyWei === "50" && reloaded?.costWei === "5" &&
    tj2.recentTrades(5).length === 2;

  // --- initiative: quiet hours wrap midnight and can be disabled ---
  const initiative = new InitiativeService();
  const quietOk =
    initiative.isQuietHour(23) &&
    initiative.isQuietHour(3) &&
    !initiative.isQuietHour(9) &&
    !initiative.isQuietHour(12);

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

  // --- telegram: operator chat resolution + token gating (no network) ---
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

  // --- presence: hourly check-ins must never use the old decorative block/gas hum ---
  const noisyPulse = "✶ wired the wired hums quietly at block 12345, gas 1.23 gwei.";
  const presenceQuietOk =
    isNoisyWiredPulseMessage(noisyPulse) &&
    cleanPresenceMessage(noisyPulse) === undefined &&
    cleanPresenceMessage("тихо. я рядом.") === "тихо. я рядом." &&
    DEFAULT_PRESENCE_MESSAGES.length > 0 &&
    DEFAULT_PRESENCE_MESSAGES.every((message) => !isNoisyWiredPulseMessage(message));

  // --- assertions ---
  const facts = await agent.memory.facts(50);
  const learnedName = facts.some((f) => /operator/i.test(f));
  const learnedRussianName = facts.some((f) => /Алиса/i.test(f));
  const ranBalance = bal.actions.some((a) => a.name === "check_balance" && a.result.ok);
  const nullIsZero = bal.actions.some(
    (a) => a.name === "check_balance" && a.result.data?.balance === "0",
  );
  const forcedPnl = pnl.actions.some((a) => a.name === "portfolio_pnl");
  const transcriptOk = readdirSync(join(dataDir, "model-transcripts")).some((name) =>
    name.endsWith(".json"),
  );

  // --- chat provider switch: claude/codex re-routing, no silent downgrade ---
  const stub = (name: string): ModelProvider => ({
    name,
    modelFor: () => `${name}-model`,
    async generate() {
      return { text: "", toolCalls: [], model: `${name}-model` };
    },
  });
  const persistedProviders: (string | null)[] = [];
  const switchable = new SwitchableModelProvider({
    initial: stub("codex"),
    kind: "codex",
    envKind: "codex",
    // "anthropic" stands in for a route with no key and no CLI on the machine.
    assemble: (kind) => (kind === "anthropic" ? undefined : stub(kind)),
    persist: (kind) => persistedProviders.push(kind),
  });
  const toClaude = switchable.switchTo(resolveChatProviderKind("claude") ?? "?");
  const failedSwitch = switchable.switchTo(resolveChatProviderKind("claude-api") ?? "?");
  const stayedPut = typeof failedSwitch === "string" && switchable.state().kind === "claude";
  const backToCodex = switchable.switchTo(resolveChatProviderKind("codex") ?? "?");
  const chatProviderSwitchOk =
    resolveChatProviderKind("claude") === "claude" &&
    resolveChatProviderKind("claude-api") === "anthropic" &&
    resolveChatProviderKind("gpt-5") === undefined &&
    typeof toClaude !== "string" &&
    toClaude.kind === "claude" &&
    toClaude.overridden &&
    stayedPut &&
    typeof backToCodex !== "string" &&
    !backToCodex.overridden &&
    // the override is persisted, and cleared again when it matches the env
    persistedProviders.length === 2 &&
    persistedProviders[0] === "claude" &&
    persistedProviders[1] === null;

  console.log("\n=== assertions ===");
  console.log(`fact 'operator' learned : ${learnedName ? "PASS" : "FAIL"}`);
  console.log(`russian name learned     : ${learnedRussianName ? "PASS" : "FAIL"}`);
  console.log(`check_balance ran ok     : ${ranBalance ? "PASS" : "FAIL"}`);
  console.log(`null address == 0 CYBER  : ${nullIsZero ? "PASS" : "FAIL"}`);
  console.log(`forced portfolio_pnl     : ${forcedPnl ? "PASS" : "FAIL"}`);
  console.log(`tool result text + data  : ${toolResultContractOk ? "PASS" : "FAIL"}`);
  console.log(`refusal -> learn_skill   : ${autoLearnOk ? "PASS" : "FAIL"}`);
  console.log(`model transcript saved   : ${transcriptOk ? "PASS" : "FAIL"}`);
  console.log(`sentinel watch fired     : ${sentinelFired ? "PASS" : "FAIL"}`);
  console.log(`alert delivered in turn  : ${alertDelivered ? "PASS" : "FAIL"}`);
  console.log(`telegram splitMessage    : ${splitOk ? "PASS" : "FAIL"}`);
  console.log(`agent wallet lifecycle   : ${walletOk ? "PASS" : "FAIL"}`);
  console.log(`LAIN token registry      : ${lainTokenKnown ? "PASS" : "FAIL"}`);
  console.log(`forge wish logged        : ${wishLogged ? "PASS" : "FAIL"}`);
  console.log(`forge wish edited        : ${wishEdited ? "PASS" : "FAIL"}`);
  console.log(`forge wish -> done       : ${wishForged ? "PASS" : "FAIL"}`);
  console.log(`forge jobs listed        : ${forgeJobsListed ? "PASS" : "FAIL"}`);
  console.log(`forge jobs scrub secrets : ${forgeJobsScrubbed ? "PASS" : "FAIL"}`);
  console.log(`forge provider switch    : ${forgeProviderSwitchOk ? "PASS" : "FAIL"}`);
  console.log(`chat provider switch     : ${chatProviderSwitchOk ? "PASS" : "FAIL"}`);
  console.log(`skills hot self-extend   : ${skillsOk ? "PASS" : "FAIL"}`);
  console.log(`trade journal cost basis : ${journalOk ? "PASS" : "FAIL"}`);
  console.log(`initiative quiet hours   : ${quietOk ? "PASS" : "FAIL"}`);
  console.log(`scout rss parser         : ${rssOk ? "PASS" : "FAIL"}`);
  console.log(`scout topic add/remove   : ${scoutOk ? "PASS" : "FAIL"}`);
  console.log(`scout NOTHING = silence  : ${nothingOk ? "PASS" : "FAIL"}`);
  console.log(`presence quiet format    : ${presenceQuietOk ? "PASS" : "FAIL"}`);
  console.log(`reasoning never leaks    : ${reasoningOk ? "PASS" : "FAIL"}`);
  console.log(`github streak watch      : ${githubOk ? "PASS" : "FAIL"}`);
  console.log(`channel post watch       : ${channelOk ? "PASS" : "FAIL"}`);
  console.log(`telegram send action     : ${telegramOk ? "PASS" : "FAIL"}`);

  await agent.stop();
  const ok =
    learnedName && learnedRussianName && ranBalance && nullIsZero && forcedPnl && toolResultContractOk && autoLearnOk && transcriptOk && sentinelFired && alertDelivered && splitOk &&
    walletOk && lainTokenKnown && wishLogged && wishEdited && wishForged && forgeProviderSwitchOk && chatProviderSwitchOk && skillsOk && journalOk && quietOk &&
    forgeJobsListed && forgeJobsScrubbed &&
    rssOk && scoutOk && nothingOk && presenceQuietOk && reasoningOk &&
    githubOk && channelOk && telegramOk;
  console.log(`\n${ok ? "✅ smoke OK" : "❌ smoke FAILED"}`);
  process.exit(ok ? 0 : 1);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
