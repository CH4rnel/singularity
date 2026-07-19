import { SwitchableModelProvider } from "../../models/routing.js";
import type { Action, Evaluator, IAgentRuntime, Plugin, Provider } from "../../types.js";

/** Injects the current wall-clock time so the agent is temporally grounded. */
const timeProvider: Provider = {
  name: "time",
  async get() {
    const now = new Date();
    return `Current UTC time: ${now.toISOString()}.`;
  },
};

/**
 * Heuristic fact extractor. Avoids an extra model call (so it works offline):
 * captures self-identification and explicit "remember ..." requests as durable
 * facts. A model-backed extractor can replace this via the same interface.
 */
const factEvaluator: Evaluator = {
  name: "fact_extractor",
  description: "Stores durable facts the user shares about themselves.",
  async validate(_runtime, state) {
    return state.message.role === "user";
  },
  async handler(runtime, state) {
    const text = state.message.content.trim();
    const patterns: Array<{ re: RegExp; userName?: boolean }> = [
      { re: /\bmy name is\s+([^.!?\n]{1,40})/i, userName: true },
      { re: /(?:^|\s)меня зовут\s+([^.!?\n]{1,40})/i, userName: true },
      { re: /\bremember that\s+([^.!?\n]{1,120})/i },
      { re: /(?:^|\s)запомни,?\s+что\s+([^.!?\n]{1,120})/i },
    ];
    for (const { re, userName } of patterns) {
      const m = text.match(re);
      if (m?.[1]) {
        const fact = userName
          ? `The user (${state.message.userId}) is named ${m[1].trim()}.`
          : m[1].trim();
        await runtime.memory.remember(fact, {
          source: "fact_extractor",
          userId: state.message.userId,
        });
      }
    }
  },
};

/**
 * Deliberate write to long-term memory. The fact extractor catches things
 * passively; this lets the agent itself choose to persist something durable.
 */
const rememberAction: Action = {
  name: "remember",
  similes: ["memorize", "note", "save_memory", "keep_in_mind"],
  description:
    "Store a durable fact in long-term memory so it persists across sessions and rooms.",
  parameters: {
    type: "object",
    properties: {
      fact: {
        type: "string",
        description: "The fact to remember, as a short standalone statement.",
      },
    },
    required: ["fact"],
  },
  examples: [{ user: "remember that my wallet is 0xabc", agent: "Noting that down." }],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const fact = String(params.fact ?? "").trim();
    if (!fact) return { ok: false, text: "There's nothing to remember." };
    await runtime.memory.remember(fact, { source: "remember_action" });
    return { ok: true, text: `Remembered: ${fact}`, data: { fact } };
  },
};

/** Read back from long-term memory: durable facts plus this room's history. */
const recallAction: Action = {
  name: "recall",
  similes: ["search_memory", "what_do_you_know", "remember_what"],
  description:
    "Search long-term memory — durable facts and earlier conversation in this room — for relevant context.",
  parameters: {
    type: "object",
    properties: {
      query: {
        type: "string",
        description: "What to look for. Omit to list recent durable facts.",
      },
    },
  },
  examples: [{ user: "what do you know about me?", agent: "Let me check my memory…" }],
  async validate() {
    return true;
  },
  async handler(runtime, state, params) {
    const query = String(params.query ?? "").trim();
    const facts = await runtime.memory.facts(50);
    const matchedFacts = query
      ? facts.filter((f) => f.toLowerCase().includes(query.toLowerCase()))
      : facts.slice(-15);
    const episodic = query ? await runtime.memory.search(state.roomId, query, 5) : [];
    const lines: string[] = [];
    if (matchedFacts.length) lines.push("facts:", ...matchedFacts.map((f) => `- ${f}`));
    if (episodic.length)
      lines.push("from earlier here:", ...episodic.map((m) => `- (${m.role}) ${m.content}`));
    if (!lines.length) {
      return {
        ok: true,
        text: query ? `Nothing in memory about "${query}".` : "Memory is empty.",
      };
    }
    return { ok: true, text: lines.join("\n"), data: { facts: matchedFacts } };
  },
};

// ------------------------------------------------------------- chat provider

/** Operator-facing names → model-provider kinds ("claude" is the Anthropic API). */
const CHAT_PROVIDER_KINDS: Record<string, string> = {
  claude: "anthropic",
  anthropic: "anthropic",
  codex: "codex",
};

function chatProviderLabel(kind: string): string {
  return kind === "anthropic" ? "claude (anthropic)" : kind;
}

function switchableModel(runtime: IAgentRuntime): SwitchableModelProvider | undefined {
  return runtime.model instanceof SwitchableModelProvider ? runtime.model : undefined;
}

/**
 * Re-route the live replies between Claude and Codex on operator request
 * ("отвечай с помощью Claude"). Forge coding jobs have their own switch
 * (set_forge_provider); this one only touches the chat model.
 */
const setChatProviderAction: Action = {
  name: "set_chat_provider",
  similes: ["switch_chat_provider", "set_reply_provider", "reply_via_claude", "reply_via_codex"],
  description:
    "Switch which model writes the live replies in this chat: claude (Anthropic API) or codex " +
    "(Codex CLI). Takes effect immediately and survives restarts. Forge coding jobs are " +
    "separate — use set_forge_provider for those.",
  parameters: {
    type: "object",
    properties: {
      provider: {
        type: "string",
        enum: ["claude", "codex"],
        description: "Model provider for live replies.",
      },
    },
    required: ["provider"],
  },
  examples: [
    {
      user: "отвечай с помощью Claude, вместо codex",
      agent: "Переключаюсь: живые ответы теперь пишет Claude.",
    },
  ],
  async validate(runtime) {
    return Boolean(switchableModel(runtime));
  },
  async handler(runtime, _state, params) {
    const model = switchableModel(runtime);
    if (!model) return { ok: false, text: "The live model provider is fixed for this run." };
    const raw = String(params.provider ?? "").trim().toLowerCase();
    const kind = CHAT_PROVIDER_KINDS[raw];
    if (!kind) return { ok: false, text: "provider must be claude or codex" };
    const result = model.switchTo(kind);
    if (typeof result === "string") return { ok: false, text: result };
    return {
      ok: true,
      text:
        `Live replies now go through ${chatProviderLabel(result.kind)} — ${result.model}. ` +
        `Forge jobs are unchanged.`,
      data: { provider: result.kind, model: result.model, overridden: result.overridden },
    };
  },
};

const chatProviderStatusAction: Action = {
  name: "chat_provider_status",
  similes: ["which_model_answers", "reply_provider_status", "what_writes_replies"],
  description:
    "Report which model provider writes the live replies right now, and what the environment " +
    "default is.",
  parameters: { type: "object", properties: {} },
  examples: [
    {
      user: "чем ты сейчас отвечаешь?",
      agent: "Живые ответы пишет codex; это же провайдер по умолчанию.",
    },
  ],
  async validate(runtime) {
    return Boolean(switchableModel(runtime));
  },
  async handler(runtime) {
    const model = switchableModel(runtime);
    if (!model) return { ok: false, text: "The live model provider is fixed for this run." };
    const s = model.state();
    return {
      ok: true,
      text:
        `Live replies: ${chatProviderLabel(s.kind)} — ${s.model} (ensemble ${s.name}). ` +
        (s.overridden
          ? `Runtime override active; env default is ${s.envKind}.`
          : `Matches the env default.`),
      data: {
        provider: s.kind,
        model: s.model,
        ensemble: s.name,
        envDefault: s.envKind,
        overridden: s.overridden,
      },
    };
  },
};

export const bootstrapPlugin: Plugin = {
  name: "bootstrap",
  description: "Core providers, evaluators, and long-term memory skills every agent needs.",
  actions: [rememberAction, recallAction, setChatProviderAction, chatProviderStatusAction],
  providers: [timeProvider],
  evaluators: [factEvaluator],
};
