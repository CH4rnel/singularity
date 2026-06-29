import type { Action, Evaluator, Plugin, Provider } from "../../types.js";

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
    const patterns: RegExp[] = [
      /\bmy name is\s+([^.!?\n]{1,40})/i,
      /\bменя зовут\s+([^.!?\n]{1,40})/i,
      /\bremember that\s+([^.!?\n]{1,120})/i,
      /\bзапомни,?\s+что\s+([^.!?\n]{1,120})/i,
    ];
    for (const re of patterns) {
      const m = text.match(re);
      if (m?.[1]) {
        const fact = re.source.includes("name")
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

export const bootstrapPlugin: Plugin = {
  name: "bootstrap",
  description: "Core providers, evaluators, and long-term memory skills every agent needs.",
  actions: [rememberAction, recallAction],
  providers: [timeProvider],
  evaluators: [factEvaluator],
};
