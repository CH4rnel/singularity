import { spawn } from "node:child_process";
import { randomUUID } from "node:crypto";
import { existsSync } from "node:fs";
import { mkdir, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { createLogger } from "../logger.js";
import {
  ModelTier,
  type ModelProvider,
  type ModelRequest,
  type ModelResponse,
  type ModelToolCall,
  type ToolSchema,
} from "../types.js";

const log = createLogger("model:codex");

/**
 * Codex CLI backend: each completion is one non-interactive `codex exec` run,
 * billed to the machine's ChatGPT subscription (`codex login`) — no API key.
 *
 * The CLI is an agent, not a chat API, so the ModelProvider contract is
 * emulated: system + conversation are serialised into a single prompt, the
 * final agent message comes back via --output-last-message, and tool calling
 * uses a JSON-in-the-reply protocol ({"tool": ..., "input": ...}) that
 * {@link parseToolReply} decodes back into ModelResponse.toolCalls.
 *
 * The run is fenced in: read-only sandbox, an empty scratch cwd (never the
 * repo), --ephemeral so no session files pile up, and the prompt tells the
 * agent to answer directly instead of exploring.
 */
export interface CodexProviderOptions {
  /** Path to the codex binary (see {@link resolveCodexBin}). */
  bin: string;
  /** Model per tier; unset tiers use the CLI's configured default. */
  models?: Partial<Record<ModelTier, string>>;
  /** Hard timeout for one completion, ms. */
  timeoutMs?: number;
  /** Extra attempts after a failed run (transient CLI/network blips). */
  retries?: number;
  /** Extra args appended to `codex exec` (e.g. -c model_reasoning_effort="low"). */
  extraArgs?: string[];
  /** Scratch working directory the sandboxed agent is pointed at. */
  cwd: string;
  /** Optional HTTP(S) proxy for the CLI's API traffic. */
  proxy?: string;
}

const DEFAULT_TIMEOUT_MS = 240_000;
const DEFAULT_RETRIES = 1;
const RETRY_DELAY_MS = 2_000;
const OUTPUT_TAIL_CHARS = 4_000;

export class CodexModelProvider implements ModelProvider {
  readonly name = "codex";
  private bin: string;
  private models: Partial<Record<ModelTier, string>>;
  private timeoutMs: number;
  private retries: number;
  private extraArgs: string[];
  private cwd: string;
  private proxy?: string;

  constructor(opts: CodexProviderOptions) {
    this.bin = opts.bin;
    this.models = opts.models ?? {};
    this.timeoutMs = Math.max(10_000, opts.timeoutMs ?? DEFAULT_TIMEOUT_MS);
    this.retries = Math.max(0, opts.retries ?? DEFAULT_RETRIES);
    this.extraArgs = opts.extraArgs ?? [];
    // Absolute: codex resolves -C against its own cwd, so a relative path
    // (./data/codex) would point at data/codex/data/codex and fail.
    this.cwd = resolve(opts.cwd);
    this.proxy = opts.proxy;
    if (opts.proxy) log.info(`routing codex traffic via proxy ${opts.proxy}`);
  }

  modelFor(tier: ModelTier): string {
    return this.models[tier] ?? "codex";
  }

  async generate(request: ModelRequest): Promise<ModelResponse> {
    const model = this.models[request.tier];
    log.debug(`generate via codex exec${model ? ` (-m ${model})` : ""}`);

    await mkdir(this.cwd, { recursive: true });
    const outFile = join(tmpdir(), `lainos-codex-${randomUUID()}.txt`);
    const args = [
      "exec",
      "--skip-git-repo-check",
      "--sandbox",
      "read-only",
      "--ephemeral",
      "--color",
      "never",
      "-C",
      this.cwd,
      "--output-last-message",
      outFile,
    ];
    if (model) args.push("-m", model);
    args.push(...this.extraArgs, "-");

    // Transient blips (dropped proxy, brief 5xx) get a quick in-house retry —
    // there is deliberately no other model to fall back to. Timeouts are not
    // retried: doubling a 4-minute wait helps nobody.
    const prompt = renderPrompt(request);
    let lastErr: unknown;
    for (let attempt = 0; attempt <= this.retries; attempt++) {
      if (attempt > 0) {
        log.warn(`codex attempt ${attempt} failed — retrying`, lastErr);
        await new Promise((r) => setTimeout(r, RETRY_DELAY_MS));
      }
      try {
        const { raw, observedModel } = await this.run(args, prompt, outFile);
        await rm(outFile, { force: true }).catch(() => {});
        const { text, toolCalls } = parseToolReply(raw);
        // The session header codex prints (model: gpt-…) is the receipt of
        // which model actually answered — surface it instead of a guess.
        return { text, toolCalls, model: observedModel ? `codex/${observedModel}` : (model ?? "codex") };
      } catch (err) {
        lastErr = err;
        if (err instanceof Error && err.message.includes("timed out")) break;
      }
    }
    await rm(outFile, { force: true }).catch(() => {});
    throw lastErr;
  }

  /** Spawn one codex run, feed the prompt on stdin, return the final message. */
  private run(
    args: string[],
    prompt: string,
    outFile: string,
  ): Promise<{ raw: string; observedModel?: string }> {
    return new Promise((resolve, reject) => {
      const child = spawn(this.bin, args, {
        cwd: this.cwd,
        env: this.env(),
        stdio: ["pipe", "pipe", "pipe"],
      });

      let tail = "";
      let head = "";
      const keep = (chunk: Buffer) => {
        // The session header ("model: gpt-…") sits in the first bytes — on
        // stderr when stdout is not a TTY, so watch both streams.
        if (head.length < 1_000) head += chunk.toString();
        tail = (tail + chunk.toString()).slice(-OUTPUT_TAIL_CHARS);
      };
      child.stdout?.on("data", keep);
      child.stderr?.on("data", keep);

      let timedOut = false;
      const killer = setTimeout(() => {
        timedOut = true;
        child.kill("SIGTERM");
      }, this.timeoutMs);
      killer.unref?.();

      child.on("error", (err) => {
        clearTimeout(killer);
        reject(new Error(`codex spawn failed: ${err.message}`));
      });
      child.on("close", (code) => {
        clearTimeout(killer);
        if (timedOut) {
          return reject(new Error(`codex timed out after ${Math.round(this.timeoutMs / 1000)}s`));
        }
        if (code !== 0) {
          const reason = tail.trim().split("\n").slice(-3).join(" · ").slice(-300);
          return reject(new Error(`codex exited ${code}${reason ? `: ${reason}` : ""}`));
        }
        const observedModel = head.match(/^model:\s*(\S+)/m)?.[1];
        readFile(outFile, "utf8")
          .then((text) => resolve({ raw: text.trim(), observedModel }))
          .catch(() => resolve({ raw: "", observedModel }));
      });

      child.stdin?.on("error", () => {}); // a dead child must not crash the daemon
      child.stdin?.end(prompt);
    });
  }

  private env(): NodeJS.ProcessEnv {
    const env: NodeJS.ProcessEnv = { ...process.env };
    if (this.proxy) {
      env.HTTPS_PROXY = this.proxy;
      env.HTTP_PROXY = this.proxy;
      env.NO_PROXY = [env.NO_PROXY, "localhost,127.0.0.1"].filter(Boolean).join(",");
    }
    return env;
  }
}

/** Locate the codex binary: explicit override, PATH, then ~/.local/bin. */
export function resolveCodexBin(explicit?: string): string | null {
  if (explicit) return explicit;
  const home = process.env.HOME ?? "";
  for (const bin of ["codex", join(home, ".local/bin/codex")]) {
    if (bin.includes("/") ? existsSync(bin) : onPath(bin)) return bin;
  }
  return null;
}

function onPath(bin: string): boolean {
  const paths = (process.env.PATH ?? "").split(":");
  return paths.some((p) => p && existsSync(join(p, bin)));
}

/** Serialise a ModelRequest into the single prompt one codex run receives. */
export function renderPrompt(request: ModelRequest): string {
  const lines: string[] = [
    "This is a pure chat completion relayed through the Codex CLI — you are not " +
      "a coding agent here. Do not run shell commands, do not read or write " +
      "files, do not explore the workspace: compose the reply from this prompt " +
      "alone and finish in one message.",
    "",
    "# Persona and context",
    request.system,
  ];
  if (request.tools?.length) {
    lines.push("", "# Tools", renderTools(request.tools));
  }
  lines.push("", "# Conversation");
  for (const m of request.messages) {
    lines.push(`${m.role === "assistant" ? "assistant" : "user"}: ${m.content}`);
  }
  lines.push(
    "",
    `Reply to the last user message${request.tools?.length ? " (or emit exactly one tool-call JSON)" : ""}. ` +
      "Output only the reply itself — no role prefix, no commentary about these instructions.",
  );
  return lines.join("\n");
}

function renderTools(tools: ToolSchema[]): string {
  const list = tools
    .map((t) => `- ${t.name}: ${t.description}\n  input schema: ${JSON.stringify(t.input_schema)}`)
    .join("\n");
  return (
    "To use a tool, output ONLY this JSON as your entire reply (no prose around it, no code fences):\n" +
    '{"tool":"<name>","input":{<arguments matching the schema>}}\n' +
    "One tool call per reply; you will receive the result and can then answer or call another.\n" +
    "If no tool is needed, reply with plain text.\n" +
    `Available tools:\n${list}`
  );
}

/**
 * Decode a codex reply: a bare {"tool": ...} object (possibly fenced, possibly
 * after some prose on its own line) becomes a tool call; anything else is the
 * reply text.
 */
export function parseToolReply(raw: string): { text: string; toolCalls: ModelToolCall[] } {
  let body = raw.trim();
  const fenced = body.match(/^```(?:json)?\s*([\s\S]*?)\s*```$/);
  if (fenced) body = fenced[1].trim();

  const whole = tryParseCall(body);
  if (whole) return { text: "", toolCalls: [whole] };

  const lines = body.split("\n");
  for (let i = 0; i < lines.length; i++) {
    const call = tryParseCall(lines[i].trim());
    if (call) {
      const rest = [...lines.slice(0, i), ...lines.slice(i + 1)].join("\n").trim();
      return { text: rest, toolCalls: [call] };
    }
  }
  return { text: raw.trim(), toolCalls: [] };
}

function tryParseCall(candidate: string): ModelToolCall | null {
  if (!candidate.startsWith("{") || !candidate.includes('"tool"')) return null;
  try {
    const parsed = JSON.parse(candidate) as { tool?: unknown; input?: unknown };
    if (typeof parsed.tool !== "string" || !parsed.tool) return null;
    const input =
      typeof parsed.input === "object" && parsed.input !== null
        ? (parsed.input as Record<string, unknown>)
        : {};
    return { name: parsed.tool, input };
  } catch {
    return null;
  }
}
