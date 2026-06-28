import type { Evaluator, Plugin, Provider } from "../../types.js";

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

export const bootstrapPlugin: Plugin = {
  name: "bootstrap",
  description: "Core providers and evaluators every agent needs.",
  providers: [timeProvider],
  evaluators: [factEvaluator],
};
