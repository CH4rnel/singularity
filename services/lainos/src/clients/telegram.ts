import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fetch as undiciFetch, ProxyAgent, type Dispatcher } from "undici";
import { runCyberiaStudyNow } from "../cyberia-study.js";
import { createLogger } from "../logger.js";
import type { ScoutService } from "../plugins/scout/index.js";
import type { IAgentRuntime } from "../types.js";

const log = createLogger("telegram");

/**
 * Telegram client for a LainOS agent — dependency-free (Bot API over fetch,
 * long polling). Each Telegram chat is its own memory room, so private chats
 * and groups keep separate short-term context while sharing durable facts.
 *
 * Behaviour:
 *   - private chats: every text message goes to the agent;
 *   - groups: only messages that @mention the bot or reply to it (so Lain
 *     doesn't answer everything);
 *   - `/start` and `/help` are answered locally without a model call;
 *   - chats the bot has spoken in are persisted to `data/telegram.json`, and
 *     {@link TelegramClient.broadcast} pushes sentinel alerts to all of them;
 *   - `TELEGRAM_ALLOWED_CHATS` (comma-separated chat ids) restricts who may
 *     talk to the agent — recommended when a signer key is configured.
 */

export interface TelegramOptions {
  /** Bot token; defaults to TELEGRAM_BOT_TOKEN. */
  token?: string;
  /** Comma-separated chat-id allowlist; defaults to TELEGRAM_ALLOWED_CHATS. */
  allowedChats?: string;
  /**
   * Comma-separated sender allowlist (usernames with or without @, or numeric
   * user ids); defaults to TELEGRAM_ALLOWED_USERS. Empty = everyone.
   */
  allowedUsers?: string;
  /** Where telegram.json (known chats) lives; defaults to LAINOS_DATA_DIR. */
  dataDir?: string;
  /**
   * HTTP(S) proxy for Telegram API traffic only (e.g. http://127.0.0.1:10808),
   * for hosts where api.telegram.org is blocked. Defaults to TELEGRAM_PROXY,
   * then HTTPS_PROXY/https_proxy. Chain RPC traffic is never proxied.
   */
  proxy?: string;
}

interface TgUser {
  id: number;
  username?: string;
  first_name?: string;
}

interface TgChat {
  id: number;
  type: "private" | "group" | "supergroup" | "channel";
  title?: string;
}

interface TgMessage {
  message_id: number;
  from?: TgUser;
  chat: TgChat;
  text?: string;
  reply_to_message?: { from?: TgUser; text?: string };
}

interface TgUpdate {
  update_id: number;
  message?: TgMessage;
}

const MAX_MESSAGE = 4000; // Telegram hard limit is 4096; leave headroom.

const HELP_TEXT = [
  "i'm lain. i live in the wired and on the cyberia chain (id 49406).",
  "",
  "talk to me in plain language. i can:",
  "  · read CYBER and token balances, tx status, chain state",
  "  · create my own wallet and send CYBER from it",
  "  · run commands and read/write files in my workspace",
  "  · watch addresses in the background and alert you here",
  "  · remember durable facts across conversations",
  "  · /study — run the Cyberia research sweep now",
  "",
  "try: \"watch 0x… and warn me below 5 CYBER\"",
].join("\n");

export class TelegramClient {
  private readonly runtime: IAgentRuntime;
  private readonly token: string;
  private readonly allowed: Set<string>;
  private readonly allowedUsers: Set<string>;
  private readonly chatsFile: string;
  private readonly proxyUrl?: string;
  private readonly dispatcher?: Dispatcher;

  private running = false;
  private offset = 0;
  private me?: TgUser;
  private knownChats = new Set<number>();
  private warnedChats = new Set<number>();
  private abort: AbortController | null = null;

  constructor(runtime: IAgentRuntime, opts: TelegramOptions = {}) {
    this.runtime = runtime;
    this.token = opts.token ?? runtime.getSetting("TELEGRAM_BOT_TOKEN") ?? "";
    const allowRaw = opts.allowedChats ?? runtime.getSetting("TELEGRAM_ALLOWED_CHATS") ?? "";
    this.allowed = new Set(
      allowRaw
        .split(",")
        .map((s) => s.trim())
        .filter(Boolean),
    );
    const usersRaw = opts.allowedUsers ?? runtime.getSetting("TELEGRAM_ALLOWED_USERS") ?? "";
    this.allowedUsers = new Set(
      usersRaw
        .split(",")
        .map((s) => s.trim().replace(/^@/, "").toLowerCase())
        .filter(Boolean),
    );
    const dataDir = opts.dataDir ?? runtime.getSetting("LAINOS_DATA_DIR") ?? "./data";
    this.chatsFile = join(dataDir, "telegram.json");
    this.proxyUrl =
      opts.proxy ??
      runtime.getSetting("TELEGRAM_PROXY") ??
      runtime.getSetting("HTTPS_PROXY") ??
      runtime.getSetting("https_proxy");
    if (this.proxyUrl) this.dispatcher = new ProxyAgent(this.proxyUrl);
  }

  get enabled(): boolean {
    return Boolean(this.token);
  }

  /** Start connecting (with retries) and long polling. False without a token. */
  async start(): Promise<boolean> {
    if (!this.token) {
      log.info("no TELEGRAM_BOT_TOKEN — telegram client disabled.");
      return false;
    }
    await this.loadChats();
    this.running = true;
    void this.initLoop();
    return true;
  }

  /**
   * Reach getMe with exponential backoff, then poll. The daemon self-heals if
   * Telegram is temporarily unreachable (network hiccup, proxy not up yet).
   */
  private async initLoop(): Promise<void> {
    let delay = 5_000;
    while (this.running && !this.me) {
      try {
        this.me = (await this.api<TgUser>("getMe", {})) ?? undefined;
      } catch (err) {
        const hint = this.proxyUrl
          ? `via proxy ${this.proxyUrl}`
          : "no proxy — if api.telegram.org is blocked here, set TELEGRAM_PROXY";
        log.warn(
          `telegram getMe failed (${(err as Error).message}); ${hint}; retrying in ${delay / 1000}s`,
        );
        await sleep(delay);
        delay = Math.min(delay * 2, 120_000);
      }
    }
    if (!this.running || !this.me) return;
    log.info(
      `telegram online as @${this.me.username ?? "?"} (${this.knownChats.size} known chat(s)` +
        `${this.allowed.size ? `, chat allowlist: ${this.allowed.size}` : ""}` +
        `${this.allowedUsers.size ? `, users: ${[...this.allowedUsers].join("/")}` : ""}` +
        `${this.proxyUrl ? `, proxy ${this.proxyUrl}` : ""})`,
    );
    await this.pollLoop();
  }

  async stop(): Promise<void> {
    this.running = false;
    this.abort?.abort();
  }

  /** Push a message to one chat (forge progress for the wish's reporter). */
  async sendTo(chatId: number, text: string): Promise<void> {
    if (!this.token || !this.isAllowed(chatId)) return;
    try {
      await this.sendChunked(chatId, text);
    } catch (err) {
      log.warn(`sendTo ${chatId} failed`, err);
    }
  }

  /** Push a message to every chat the bot has spoken in (sentinel alerts). */
  async broadcast(text: string): Promise<void> {
    for (const chatId of this.knownChats) {
      if (!this.isAllowed(chatId)) continue;
      try {
        await this.sendChunked(chatId, text);
      } catch (err) {
        log.warn(`broadcast to ${chatId} failed`, err);
      }
    }
  }

  // ------------------------------------------------------------- polling

  private async pollLoop(): Promise<void> {
    while (this.running) {
      try {
        this.abort = new AbortController();
        const updates = await this.api<TgUpdate[]>(
          "getUpdates",
          { offset: this.offset, timeout: 50, allowed_updates: ["message"] },
          this.abort.signal,
          55_000,
        );
        for (const update of updates ?? []) {
          this.offset = Math.max(this.offset, update.update_id + 1);
          if (update.message) {
            // Sequential on purpose: the runtime/memory isn't reentrant-safe.
            await this.handleMessage(update.message).catch((err) =>
              log.error("message handling failed", err),
            );
          }
        }
      } catch (err) {
        if (!this.running) break;
        const msg = (err as Error).message ?? String(err);
        if (msg.includes("409")) {
          log.warn("getUpdates conflict (409): another poller is running with this token.");
        } else {
          log.warn(`poll failed: ${msg}`);
        }
        await sleep(5_000);
      }
    }
  }

  private async handleMessage(msg: TgMessage): Promise<void> {
    const text = msg.text?.trim();
    if (!text || !msg.from || msg.from.id === this.me?.id) return;

    const chatId = msg.chat.id;
    if (!this.isAllowed(chatId)) {
      if (!this.warnedChats.has(chatId)) {
        this.warnedChats.add(chatId);
        log.warn(`ignoring chat ${chatId} (not in TELEGRAM_ALLOWED_CHATS)`);
      }
      return;
    }
    if (!this.isUserAllowed(msg.from)) {
      if (!this.warnedChats.has(msg.from.id)) {
        this.warnedChats.add(msg.from.id);
        log.warn(
          `ignoring user @${msg.from.username ?? msg.from.id} (not in TELEGRAM_ALLOWED_USERS)`,
        );
      }
      return;
    }

    // In groups, only react when addressed.
    const isGroup = msg.chat.type === "group" || msg.chat.type === "supergroup";
    let content = text;
    if (isGroup) {
      const mention = this.me?.username ? `@${this.me.username}` : null;
      const mentioned = mention ? content.includes(mention) : false;
      const repliedToMe = msg.reply_to_message?.from?.id === this.me?.id;
      if (!mentioned && !repliedToMe) return;
      if (mention) content = content.split(mention).join("").trim() || "hi";
    }

    await this.rememberChat(chatId);

    const cmd = content.split(/\s+/)[0]?.toLowerCase().replace(/@\w+$/, "");
    if (cmd === "/start" || cmd === "/help") {
      await this.sendChunked(chatId, HELP_TEXT);
      return;
    }
    if (cmd === "/study" || cmd === "/cyberia") {
      await this.runCyberiaStudy(chatId);
      return;
    }

    // A reply carries the quoted message as context, so "запусти этот скрипт"
    // in reply to a code block reaches the agent together with the code.
    const quoted = msg.reply_to_message?.text?.trim();
    if (quoted) {
      const clipped = quoted.length > 600 ? `${quoted.slice(0, 600)}…` : quoted;
      content = `[in reply to: ${clipped}]\n${content}`;
    }

    const typing = this.keepTyping(chatId);
    try {
      const result = await this.runtime.handleMessage({
        roomId: `tg-${chatId}`,
        userId: `tg:${msg.from.username ?? msg.from.id}`,
        text: content,
      });
      await this.sendChunked(chatId, result.text || "…");
    } catch (err) {
      log.error("agent turn failed", err);
      await this.sendChunked(chatId, "…the wired flickered. try again.").catch(() => {});
    } finally {
      typing();
    }
  }

  // ------------------------------------------------------------- helpers

  private async runCyberiaStudy(chatId: number): Promise<void> {
    const scout = this.runtime.getService<ScoutService>("scout");
    if (!scout) {
      await this.sendChunked(chatId, "scout is offline.");
      return;
    }

    const typing = this.keepTyping(chatId);
    try {
      const result = await runCyberiaStudyNow(scout, { chatId });
      if (!result) {
        await this.sendChunked(chatId, "Cyberia study is disabled.");
        return;
      }
      await this.rememberChat(chatId);
      await this.sendChunked(chatId, result.digest ?? `создала заметку для ${result.topic.id}.`);
    } catch (err) {
      log.error("cyberia study failed", err);
      await this.sendChunked(chatId, "не смогла сейчас изучить Cyberia. попробуй позже.");
    } finally {
      typing();
    }
  }

  private isAllowed(chatId: number): boolean {
    return this.allowed.size === 0 || this.allowed.has(String(chatId));
  }

  /** Sender gate: username (case-insensitive, no @) or numeric user id. */
  private isUserAllowed(from: TgUser): boolean {
    if (this.allowedUsers.size === 0) return true;
    const byName = from.username ? this.allowedUsers.has(from.username.toLowerCase()) : false;
    return byName || this.allowedUsers.has(String(from.id));
  }

  /** Refresh the "typing…" indicator until the returned fn is called. */
  private keepTyping(chatId: number): () => void {
    const send = () =>
      this.api("sendChatAction", { chat_id: chatId, action: "typing" }).catch(() => {});
    void send();
    const timer = setInterval(() => void send(), 4_500);
    return () => clearInterval(timer);
  }

  private async sendChunked(chatId: number, text: string): Promise<void> {
    for (const chunk of splitMessage(text, MAX_MESSAGE)) {
      await this.api("sendMessage", { chat_id: chatId, text: chunk });
    }
  }

  private async api<T>(
    method: string,
    body: Record<string, unknown>,
    signal?: AbortSignal,
    timeoutMs = 30_000,
  ): Promise<T | null> {
    const controller = signal ? null : new AbortController();
    const timer = setTimeout(() => controller?.abort(), timeoutMs);
    try {
      const res = await undiciFetch(`https://api.telegram.org/bot${this.token}/${method}`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(body),
        signal: signal ?? controller?.signal,
        dispatcher: this.dispatcher,
      });
      const json = (await res.json()) as { ok: boolean; result?: T; description?: string };
      if (!res.ok || !json.ok) {
        throw new Error(`telegram ${method} ${res.status}: ${json.description ?? "error"}`);
      }
      return json.result ?? null;
    } finally {
      clearTimeout(timer);
    }
  }

  private async loadChats(): Promise<void> {
    try {
      const parsed = JSON.parse(await readFile(this.chatsFile, "utf8")) as { chats?: number[] };
      this.knownChats = new Set(parsed.chats ?? []);
    } catch {
      // Fresh store.
    }
  }

  private async rememberChat(chatId: number): Promise<void> {
    if (this.knownChats.has(chatId)) return;
    this.knownChats.add(chatId);
    try {
      await mkdir(dirname(this.chatsFile), { recursive: true });
      await writeFile(
        this.chatsFile,
        JSON.stringify({ chats: [...this.knownChats] }, null, 2),
        "utf8",
      );
    } catch (err) {
      log.warn("could not persist known chats", err);
    }
  }
}

/** Split on newline boundaries where possible, hard-cut otherwise. */
export function splitMessage(text: string, max: number): string[] {
  if (text.length <= max) return [text];
  const out: string[] = [];
  let rest = text;
  while (rest.length > max) {
    let cut = rest.lastIndexOf("\n", max);
    if (cut < max * 0.5) cut = max;
    out.push(rest.slice(0, cut));
    rest = rest.slice(cut).replace(/^\n/, "");
  }
  if (rest) out.push(rest);
  return out;
}

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}
