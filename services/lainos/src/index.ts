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
import { FileMemoryStore } from "./memory/store.js";
import { createModelProvider } from "./models/index.js";
import { bootstrapPlugin } from "./plugins/bootstrap/index.js";
import { cyberiaPlugin } from "./plugins/cyberia/index.js";
import { AgentRuntime } from "./runtime.js";
import type { Character, Plugin } from "./types.js";

export * from "./types.js";
export { AgentRuntime } from "./runtime.js";
export { FileMemoryStore } from "./memory/store.js";
export {
  createModelProvider,
  AnthropicModelProvider,
  MockModelProvider,
  OpenRouterModelProvider,
} from "./models/index.js";
export { bootstrapPlugin } from "./plugins/bootstrap/index.js";
export { cyberiaPlugin, cyberiaChain, CYBERIA_TOKENS } from "./plugins/cyberia/index.js";
export { lain } from "./characters/lain.js";

const log = createLogger("boot");

/** Built-in plugins keyed by the names a character may request. */
const BUILTIN_PLUGINS: Record<string, Plugin> = {
  bootstrap: bootstrapPlugin,
  cyberia: cyberiaPlugin,
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
  const memory = new FileMemoryStore(dataDir);
  const getSetting = (k: string) => process.env[k];
  const model = createModelProvider(getSetting);

  const runtime = new AgentRuntime({ character: opts.character, memory, model });

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
