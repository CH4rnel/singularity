/**
 * LainOS — a framework for autonomous AI agents in the Cyberia ecosystem.
 *
 * Public surface. Build an agent like:
 *
 *   import { createAgent, lain } from "lainos";
 *   const agent = await createAgent({ character: lain });
 *   const { text } = await agent.handleMessage({ roomId: "cli", userId: "me", text: "hi" });
 */
import "dotenv/config";
import { createLogger } from "./logger.js";
import { createEmbeddingProvider } from "./memory/embeddings.js";
import { FileMemoryStore } from "./memory/store.js";
import { createModelProvider } from "./models/index.js";
import { bootstrapPlugin } from "./plugins/bootstrap/index.js";
import { channelPlugin } from "./plugins/channel/index.js";
import { cyberiaPlugin } from "./plugins/cyberia/index.js";
import { forgePlugin } from "./plugins/forge/index.js";
import { githubPlugin } from "./plugins/github/index.js";
import { initiativePlugin } from "./plugins/initiative/index.js";
import { scoutPlugin } from "./plugins/scout/index.js";
import { sentinelPlugin } from "./plugins/sentinel/index.js";
import { skillsPlugin } from "./plugins/skills/index.js";
import { systemPlugin } from "./plugins/system/index.js";
import { telegramPlugin } from "./plugins/telegram/index.js";
import { traderPlugin } from "./plugins/trader/index.js";
import { AgentRuntime } from "./runtime.js";
import { loadSoul } from "./soul.js";
import type { Character, Plugin } from "./types.js";

export * from "./types.js";
export { AgentRuntime } from "./runtime.js";
export { loadSoul } from "./soul.js";
export { FileMemoryStore } from "./memory/store.js";
export {
  createModelProvider,
  AnthropicModelProvider,
  CodexModelProvider,
  FallbackModelProvider,
  MockModelProvider,
  OpenRouterModelProvider,
  TieredModelProvider,
  resolveCodexBin,
} from "./models/index.js";
export { bootstrapPlugin } from "./plugins/bootstrap/index.js";
export {
  createEmbeddingProvider,
  HashingEmbeddingProvider,
  OpenAIEmbeddingProvider,
} from "./memory/embeddings.js";
export { cyberiaPlugin, cyberiaChain, CYBERIA_TOKENS } from "./plugins/cyberia/index.js";
export { systemPlugin } from "./plugins/system/index.js";
export { sentinelPlugin, SentinelService } from "./plugins/sentinel/index.js";
export type { Alert, Watch, WatchKind } from "./plugins/sentinel/index.js";
export { forgePlugin, ForgeService } from "./plugins/forge/index.js";
export type { ForgeEvent, ForgeJob, Wish, WishStatus } from "./plugins/forge/index.js";
export { scoutPlugin, ScoutService, parseRss, looksLikeNothing } from "./plugins/scout/index.js";
export type { ScoutEvent, ScoutItem, Topic } from "./plugins/scout/index.js";
export { githubPlugin, GithubStreakService, parseContributionDay } from "./plugins/github/index.js";
export type { ContributionDay, GithubEvent, GithubWatch } from "./plugins/github/index.js";
export {
  channelPlugin,
  ChannelWatchService,
  parseChannelPosts,
  normalizeChannel,
  localDay,
} from "./plugins/channel/index.js";
export type { ChannelActivity, ChannelEvent, ChannelWatch } from "./plugins/channel/index.js";
export { TelegramClient } from "./clients/telegram.js";
export { telegramPlugin, resolveOperatorChatId, sendToOperator } from "./plugins/telegram/index.js";
export { skillsPlugin, SkillsService } from "./plugins/skills/index.js";
export type { SkillModule } from "./plugins/skills/index.js";
export { initiativePlugin, InitiativeService } from "./plugins/initiative/index.js";
export { traderPlugin, TraderService } from "./plugins/trader/index.js";
export type { TraderEvent } from "./plugins/trader/index.js";
export { TradeJournal, applyBuy, applySell } from "./plugins/cyberia/journal.js";
export type { Position, TradeRecord } from "./plugins/cyberia/journal.js";
export { lain } from "./characters/lain.js";

const log = createLogger("boot");

/** Built-in plugins keyed by the names a character may request. */
const BUILTIN_PLUGINS: Record<string, Plugin> = {
  bootstrap: bootstrapPlugin,
  cyberia: cyberiaPlugin,
  system: systemPlugin,
  sentinel: sentinelPlugin,
  forge: forgePlugin,
  scout: scoutPlugin,
  github: githubPlugin,
  channel: channelPlugin,
  telegram: telegramPlugin,
  skills: skillsPlugin,
  trader: traderPlugin,
  initiative: initiativePlugin,
};

export interface CreateAgentOptions {
  character: Character;
  /** Extra plugins beyond those named in the character. */
  plugins?: Plugin[];
  dataDir?: string;
}

/** Assemble and start an agent runtime from a character. */
export async function createAgent(opts: CreateAgentOptions): Promise<AgentRuntime> {
  const dataDir = opts.dataDir ?? process.env.LAINOS_DATA_DIR ?? "./data";
  const getSetting = (k: string) => process.env[k];
  const embedder = createEmbeddingProvider(getSetting);
  const memory = new FileMemoryStore(dataDir, embedder);
  const model = createModelProvider(getSetting);

  // Pin the data dir in settings so services (sentinel, telegram) that read
  // LAINOS_DATA_DIR land in the same place as the memory store.
  const runtime = new AgentRuntime({
    character: opts.character,
    memory,
    model,
    soul: loadSoul(getSetting),
    settings: { ...process.env, LAINOS_DATA_DIR: dataDir },
  });

  const wanted = new Set(opts.character.plugins ?? ["bootstrap"]);
  for (const name of wanted) {
    const plugin = BUILTIN_PLUGINS[name];
    if (plugin) runtime.use(plugin);
    else log.warn(`character requested unknown plugin: ${name}`);
  }
  for (const p of opts.plugins ?? []) runtime.use(p);

  await runtime.start();
  return runtime;
}
