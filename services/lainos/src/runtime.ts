import { randomUUID } from "node:crypto";
import { createLogger } from "./logger.js";
import {
  ModelTier,
  type Action,
  type ActionResult,
  type AgentEvent,
  type Character,
  type Evaluator,
  type IAgentRuntime,
  type Memory,
  type MemoryStore,
  type ModelProvider,
  type ModelRequest,
  type ModelResponse,
  type Plugin,
  type Provider,
  type Service,
  type State,
  type ToolSchema,
  type TurnResult,
} from "./types.js";

const log = createLogger("runtime");

/** Upper bound on think→act rounds within one turn. */
const MAX_TOOL_ROUNDS = 4;

export interface RuntimeOptions {
  character: Character;
  memory: MemoryStore;
  model: ModelProvider;
  settings?: Record<string, string | undefined>;
}

/**
 * AgentRuntime — wires character, memory, model, and plugins together and
 * drives one think→act→evaluate cycle per incoming message.
 */
export class AgentRuntime implements IAgentRuntime {
  readonly character: Character;
  readonly memory: MemoryStore;
  readonly model: ModelProvider;
  readonly actions: Action[] = [];
  readonly providers: Provider[] = [];
  readonly evaluators: Evaluator[] = [];

  /** Reply token budget (the /effort knob). Mutable at runtime. */
  maxTokens = 1024;

  private readonly services = new Map<string, Service>();
  private readonly settings: Record<string, string | undefined>;

  constructor(opts: RuntimeOptions) {
    this.character = opts.character;
    this.memory = opts.memory;
    this.model = opts.model;
    this.settings = opts.settings ?? { ...process.env };
  }

  getSetting(key: string): string | undefined {
    return this.settings[key] ?? process.env[key];
  }

  getService<T extends Service = Service>(name: string): T | undefined {
    return this.services.get(name) as T | undefined;
  }

  use(plugin: Plugin): this {
    log.info(`loading plugin: ${plugin.name}`);
    for (const a of plugin.actions ?? []) this.actions.push(a);
    for (const p of plugin.providers ?? []) this.providers.push(p);
    for (const e of plugin.evaluators ?? []) this.evaluators.push(e);
    for (const s of plugin.services ?? []) this.services.set(s.name, s);
    return this;
  }

  async start(): Promise<void> {
    for (const svc of this.services.values()) {
      await svc.start?.(this);
    }
    log.agent(`${this.character.name} is online.`);
  }

  async stop(): Promise<void> {
    for (const svc of this.services.values()) await svc.stop?.();
  }

  /** Build the working State for a turn. */
  private async buildState(message: Memory): Promise<State> {
    const [recent, relevant, facts] = await Promise.all([
      this.memory.recent(message.roomId, 12),
      this.memory.search(message.roomId, message.content, 6),
      this.memory.facts(20),
    ]);

    const availableActions: string[] = [];
    const partialState: State = {
      roomId: message.roomId,
      agentName: this.character.name,
      message,
      recent,
      relevant,
      facts,
      providerContext: {},
      availableActions,
    };

    // Run providers (live context) and validate actions in parallel.
    await Promise.all(
      this.providers.map(async (p) => {
        try {
          const text = await p.get(this, partialState);
          if (text) partialState.providerContext[p.name] = text;
        } catch (err) {
          log.warn(`provider ${p.name} failed`, err);
        }
      }),
    );
    for (const a of this.actions) {
      try {
        if (await a.validate(this, partialState)) availableActions.push(a.name);
      } catch (err) {
        log.warn(`action ${a.name} validate failed`, err);
      }
    }

    return partialState;
  }

  private composeSystemPrompt(state: State): string {
    const c = this.character;
    const lines: string[] = [];
    lines.push(`You are ${c.name}.`);
    if (c.bio.length) lines.push(`\n# Bio\n${c.bio.join("\n")}`);
    if (c.lore.length) lines.push(`\n# Lore\n${c.lore.join("\n")}`);
    if (c.adjectives.length)
      lines.push(`\n# Manner\nYou are ${c.adjectives.join(", ")}.`);
    if (c.topics.length)
      lines.push(`\n# Topics you care about\n${c.topics.join(", ")}.`);
    if (c.style.all.length || c.style.chat.length)
      lines.push(
        `\n# Style\n${[...c.style.all, ...c.style.chat].map((s) => `- ${s}`).join("\n")}`,
      );

    if (state.facts.length)
      lines.push(`\n# Things you have learned\n${state.facts.map((f) => `- ${f}`).join("\n")}`);

    const providerText = Object.entries(state.providerContext)
      .map(([name, text]) => `## ${name}\n${text}`)
      .join("\n\n");
    if (providerText) lines.push(`\n# Live context\n${providerText}`);

    if (state.relevant.length) {
      const mem = state.relevant
        .map((m) => `- (${m.role}) ${m.content}`)
        .join("\n");
      lines.push(`\n# Relevant memories\n${mem}`);
    }

    lines.push(
      `\n# Behaviour\nRespond in character, concisely. If a tool fits the user's intent, call it. ` +
        `Never invent on-chain data — use the provided tools to read it.`,
    );
    return lines.join("\n");
  }

  private buildToolSchemas(state: State): ToolSchema[] {
    return this.actions
      .filter((a) => state.availableActions.includes(a.name))
      .map((a) => ({
        name: a.name,
        description: `${a.description} (aliases: ${a.similes.join(", ")})`,
        input_schema: a.parameters ?? { type: "object", properties: {} },
      }));
  }

  private memoriesToMessages(
    recent: Memory[],
  ): { role: "user" | "assistant"; content: string }[] {
    return recent
      .filter((m) => m.role !== "system")
      .map((m) => ({
        role: m.role === "agent" ? ("assistant" as const) : ("user" as const),
        content: m.content,
      }));
  }

  async handleMessage(input: {
    roomId: string;
    userId: string;
    text: string;
  }): Promise<TurnResult> {
    return this.handleMessageStream(input, () => {});
  }

  async handleMessageStream(
    input: { roomId: string; userId: string; text: string },
    onEvent: (event: AgentEvent) => void,
  ): Promise<TurnResult> {
    const incoming: Memory = {
      id: randomUUID(),
      roomId: input.roomId,
      userId: input.userId,
      role: "user",
      content: input.text,
      createdAt: Date.now(),
    };
    await this.memory.add(incoming);

    const state = await this.buildState(incoming);
    const system = this.composeSystemPrompt(state);
    const tools = this.buildToolSchemas(state);
    const messages = this.memoriesToMessages(state.recent);
    const tier = this.character.modelTier ?? ModelTier.LARGE;

    // --- Think → act loop (streamed, with tools) ---
    // The model may chain several tool rounds (read a file, then check a
    // balance, then send). Bounded by MAX_TOOL_ROUNDS; an exactly repeated
    // call (same tool, same input) short-circuits into the final reply so a
    // confused model can never loop.
    onEvent({ type: "thinking" });
    let res = await this.streamOrGenerate(
      { tier, system, messages, tools, maxTokens: this.maxTokens },
      (delta) => onEvent({ type: "text", delta }),
    );
    const ranActions: TurnResult["actions"] = [];
    const convo = [...messages];
    const seenCalls = new Set<string>();
    let rounds = 0;

    while (res.toolCalls.length) {
      rounds += 1;
      const toolSummaries: string[] = [];
      let sawRepeat = false;

      for (const call of res.toolCalls) {
        const action = this.actions.find(
          (a) => a.name === call.name || a.similes.includes(call.name),
        );
        if (!action) {
          log.warn(`model called unknown action: ${call.name}`);
          toolSummaries.push(`Tool ${call.name} -> unknown tool`);
          continue;
        }
        const callKey = `${action.name}:${JSON.stringify(call.input)}`;
        if (seenCalls.has(callKey)) {
          sawRepeat = true;
          toolSummaries.push(
            `Tool ${action.name} -> (already called with these arguments; see earlier result)`,
          );
          continue;
        }
        seenCalls.add(callKey);

        const id = randomUUID();
        onEvent({ type: "tool", id, name: action.name, input: call.input });
        let result: ActionResult;
        try {
          result = await action.handler(this, state, call.input);
        } catch (err) {
          log.error(`action ${action.name} threw`, err);
          result = { ok: false, text: `Action ${action.name} failed.` };
        }
        ranActions.push({ name: action.name, result });
        onEvent({
          type: "tool_result",
          id,
          name: action.name,
          ok: result.ok,
          summary: summariseResult(result),
        });
        toolSummaries.push(
          `Tool ${action.name} -> ${JSON.stringify(result.data ?? result.text ?? { ok: result.ok })}`,
        );
      }

      const canContinue = rounds < MAX_TOOL_ROUNDS && !sawRepeat;
      convo.push(
        {
          role: "assistant",
          content:
            res.text || `(called tools: ${res.toolCalls.map((c) => c.name).join(", ")})`,
        },
        {
          role: "user",
          content:
            `Tool results:\n${toolSummaries.join("\n") || "(no tool output)"}\n\n` +
            (canContinue
              ? `Continue. Call another tool if the task needs it, otherwise reply to me in character using these results.`
              : `Now reply to me in character using these results. Do not call more tools.`),
        },
      );

      onEvent({ type: "thinking" });
      const followup = await this.streamOrGenerate(
        {
          tier,
          system,
          maxTokens: this.maxTokens,
          messages: convo,
          tools: canContinue ? tools : undefined,
        },
        (delta) => onEvent({ type: "text", delta }),
      );
      res = canContinue ? followup : { ...followup, toolCalls: [] };
    }

    const replyText =
      res.text ||
      ranActions.map((a) => a.result.text).filter(Boolean).join(" ") ||
      "...";

    const reply: Memory = {
      id: randomUUID(),
      roomId: input.roomId,
      userId: "agent",
      role: "agent",
      content: replyText,
      createdAt: Date.now(),
      metadata: ranActions.length ? { actions: ranActions } : undefined,
    };
    await this.memory.add(reply);

    // --- Evaluate (learn) ---
    for (const ev of this.evaluators) {
      try {
        if (await ev.validate(this, state)) await ev.handler(this, state, replyText);
      } catch (err) {
        log.warn(`evaluator ${ev.name} failed`, err);
      }
    }

    const result: TurnResult = { text: replyText, actions: ranActions };
    onEvent({ type: "done", result });
    return result;
  }

  /** Stream when the provider supports it, otherwise fall back to one generate. */
  private async streamOrGenerate(
    request: ModelRequest,
    onText: (delta: string) => void,
  ): Promise<ModelResponse> {
    if (this.model.stream) {
      try {
        return await this.model.stream(request, onText);
      } catch (err) {
        log.warn("model stream failed, falling back to generate", err);
      }
    }
    const res = await this.model.generate(request);
    if (res.text) onText(res.text);
    return res;
  }
}

/** Compact, human-readable summary of an action result for the tool card. */
function summariseResult(result: ActionResult): string {
  if (result.data && Object.keys(result.data).length > 0) {
    return JSON.stringify(result.data);
  }
  if (result.text) return result.text;
  return result.ok ? "ok" : "failed";
}
