import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fetch as undiciFetch, ProxyAgent, type Dispatcher } from "undici";
import { createLogger } from "../../logger.js";
import type {
  Action,
  IAgentRuntime,
  Plugin,
  Provider,
  Service,
  State,
} from "../../types.js";

const log = createLogger("plugin:channel");

/**
 * The channel plugin keeps the rooms Cyberia speaks in alive. Two kinds of
 * room, and the whole design turns on whether Lain can actually see one:
 *
 *  - **readable** — a public Telegram channel. Its web preview
 *    (t.me/s/<name>, no admin rights needed) carries every post's timestamp,
 *    so "did we post today" is ground truth: days with posts pass in silence.
 *  - **blind** — a Discord behind an invite link, a group chat in X. Nothing
 *    outside can read either: Discord would need a bot inside the guild, an X
 *    chat would need the account's own session. There is no truth to check, so
 *    the schedule is the entire signal. The nudge says so rather than
 *    pretending it looked, and `mark_venue_posted` ("я уже написал в дискорд")
 *    buys silence for the rest of the day.
 *
 * Past the reminder hour, every quiet room lands in **one** message per chat —
 * three separate pings would be exactly the noise this is meant to cure. A day
 * is never nudged twice. Watches persist in `data/channels.json`; records
 * written before venues existed had no `kind` and read back as Telegram
 * channels. Reminders are pushed through onEvent like sentinel alerts.
 */

/** What a watched room is — which decides whether its activity can be read. */
export type VenueKind = "telegram" | "discord" | "twitter" | "other";

export interface ChannelWatch {
  id: string;
  /** Venue kind; absent in pre-venue records, where it means "telegram". */
  kind: VenueKind;
  /** Stable key: the Telegram username, or a slug for a blind venue. */
  channel: string;
  /** Human name of a blind venue, e.g. "дискорд" or "чат в X". */
  label?: string;
  /** Invite/chat link, carried into the reminder so the nudge is one tap. */
  url?: string;
  reporter: string;
  /** Telegram chat to deliver to, when known. */
  chatId?: number;
  /** Host-local hour (0-23) after which a silent day triggers the nudge. */
  remindHour: number;
  createdAt: number;
  lastCheckedAt?: number;
  /** Unix ms of the newest post seen on the last check (readable venues). */
  lastPostAt?: number;
  /** YYYY-MM-DD of the last day a reminder went out (max one per day). */
  lastRemindedDay?: string;
  /** YYYY-MM-DD the operator said they had already written there. */
  lastPostedDay?: string;
}

export interface ChannelEvent {
  kind: "reminder";
  text: string;
  /** Every venue this one reminder covers. */
  watches: ChannelWatch[];
  chatId?: number;
}

export interface ChannelActivity {
  /** Unix ms of the newest post on the preview page, or null when none parse. */
  lastPostAt: number | null;
  /** Posts whose host-local day equals `day`. */
  postsToday: number;
}

interface ChannelFile {
  watches: ChannelWatch[];
  counter: number;
}

const DEFAULT_REMIND_HOUR = 18;
const DEFAULT_TICK_MS = 1_800_000; // 30 min
const CHANNEL_RE = /^[a-zA-Z][a-zA-Z0-9_]{3,31}$/;

/** Host-local calendar day as YYYY-MM-DD (reminders fire in host time). */
export function localDay(d = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Strip t.me/…, @… and trailing paths down to the bare channel username. */
export function normalizeChannel(raw: string): string {
  return raw
    .trim()
    .replace(/^(https?:\/\/)?t\.me\/(s\/)?/i, "")
    .replace(/^@/, "")
    .replace(/[/?#].*$/, "");
}

/** Only a public Telegram channel exposes its posts to an outside reader. */
export function isReadableVenue(watch: ChannelWatch): boolean {
  return watch.kind === "telegram";
}

/** Display name: the t.me handle for channels, the given name for the rest. */
export function venueLabel(watch: ChannelWatch): string {
  if (watch.kind === "telegram") return `t.me/${watch.channel}`;
  return watch.label ?? watch.channel;
}

/** Guess the venue from what the operator called it or linked to. */
export function inferVenueKind(raw: string): VenueKind {
  const s = raw.toLowerCase();
  if (/discord|дискорд|диск\b/.test(s)) return "discord";
  if (/twitter|x\.com|твит|тви́т/.test(s)) return "twitter";
  if (/(^|[^a-z])t\.me|телеграм|telegram/.test(s)) return "telegram";
  return "other";
}

/**
 * Parse the message timestamps out of a channel's public web preview
 * (https://t.me/s/<name>): every post carries a `<time datetime="…">` inside
 * its date link. Returns the newest post time and how many posts fall on the
 * given host-local day. Exported for tests.
 */
export function parseChannelPosts(html: string, day: string): ChannelActivity {
  let lastPostAt: number | null = null;
  let postsToday = 0;
  for (const m of html.matchAll(/<time[^>]*datetime="([^"]+)"/gi)) {
    const at = Date.parse(m[1]);
    if (!Number.isFinite(at)) continue;
    if (lastPostAt === null || at > lastPostAt) lastPostAt = at;
    if (localDay(new Date(at)) === day) postsToday += 1;
  }
  return { lastPostAt, postsToday };
}

/**
 * Does this venue owe a nudge right now? Pure, so the schedule is testable
 * without a clock or a network. `activity` is the reading for a readable
 * venue and is ignored for blind ones — a readable day that could not be read
 * is never nudged (a blocked preview is not proof of silence), while a blind
 * venue has nothing to read and rides on the schedule alone.
 */
export function isVenueDue(
  watch: ChannelWatch,
  now: Date,
  activity: ChannelActivity | null,
): boolean {
  const day = localDay(now);
  if (watch.lastRemindedDay === day) return false;
  if (watch.lastPostedDay === day) return false;
  if (now.getHours() < watch.remindHour) return false;
  if (!isReadableVenue(watch)) return true;
  return activity !== null && activity.postsToday === 0;
}

/** The evening message: every quiet room at once, in one nudge. */
export function reminderText(watches: ChannelWatch[]): string {
  const blind = watches.filter((w) => !isReadableVenue(w));
  const lines = watches.map((w) => {
    if (isReadableVenue(w)) {
      const last = w.lastPostAt
        ? ` (последний пост: ${new Date(w.lastPostAt).toLocaleString("ru-RU")})`
        : "";
      return `• ${venueLabel(w)} — сегодня ещё нет постов${last}`;
    }
    return `• ${venueLabel(w)}${w.url ? ` — ${w.url}` : ""}`;
  });
  const tail: string[] = [];
  if (watches.some(isReadableVenue)) {
    tail.push("посты из канала уходят в твиттер, который двигает курс CYBER.sol.");
  }
  if (blind.length) {
    tail.push(
      `${listRu(blind.map(venueLabel))} я изнутри не вижу — напоминаю по расписанию. ` +
        `если уже написал туда, скажи, и я замолчу до завтра.`,
    );
  }
  return ["📣 сегодня тихо:", ...lines, ...tail].join("\n");
}

export class ChannelWatchService implements Service {
  readonly name = "channel-watch";

  private watches: ChannelWatch[] = [];
  private counter = 0;
  private file = "";
  private timer: ReturnType<typeof setInterval> | null = null;
  private dispatcher?: Dispatcher;
  private busy = false;
  private defaultRemindHour = DEFAULT_REMIND_HOUR;
  private subscribers = new Set<(event: ChannelEvent) => void>();

  async start(runtime: IAgentRuntime): Promise<void> {
    const dataDir = runtime.getSetting("LAINOS_DATA_DIR") ?? "./data";
    this.file = join(dataDir, "channels.json");
    try {
      const parsed = JSON.parse(await readFile(this.file, "utf8")) as ChannelFile;
      // Records predating venues carry no kind: they were all Telegram channels.
      this.watches = (parsed.watches ?? []).map((w) => ({ ...w, kind: w.kind ?? "telegram" }));
      this.counter = parsed.counter ?? 0;
    } catch {
      // Fresh store.
    }

    // t.me is blocked on the same hosts as api.telegram.org, so the Telegram
    // proxy is the natural first fallback.
    const proxy =
      runtime.getSetting("LAINOS_CHANNEL_PROXY") ??
      runtime.getSetting("TELEGRAM_PROXY") ??
      runtime.getSetting("LAINOS_MODEL_PROXY") ??
      runtime.getSetting("HTTPS_PROXY");
    if (proxy) this.dispatcher = new ProxyAgent(proxy);

    const hour = Number(runtime.getSetting("LAINOS_CHANNEL_REMIND_HOUR") ?? DEFAULT_REMIND_HOUR);
    this.defaultRemindHour = Number.isFinite(hour)
      ? Math.min(23, Math.max(0, hour))
      : DEFAULT_REMIND_HOUR;

    const tick = Number(runtime.getSetting("LAINOS_CHANNEL_INTERVAL_MS") ?? DEFAULT_TICK_MS);
    this.timer = setInterval(() => void this.tick(), Math.max(60_000, tick));
    this.timer.unref?.();
    log.info(
      `channel watch online: ${this.watches.length} venue(s)` +
        `${proxy ? `, proxy ${proxy}` : ""}, tick every ${Math.max(60_000, tick) / 60_000}m`,
    );
  }

  async stop(): Promise<void> {
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
  }

  onEvent(fn: (event: ChannelEvent) => void): () => void {
    this.subscribers.add(fn);
    return () => this.subscribers.delete(fn);
  }

  listWatches(): ChannelWatch[] {
    return [...this.watches];
  }

  /**
   * Add or update a watched venue. A Telegram channel is keyed by its
   * username and must look like one; a blind venue is keyed by a slug of its
   * name, because there is no handle to validate against.
   */
  async addWatch(input: {
    channel: string;
    kind?: VenueKind;
    label?: string;
    url?: string;
    reporter: string;
    chatId?: number;
    remindHour?: number;
  }): Promise<ChannelWatch | null> {
    const raw = input.channel.trim();
    if (!raw) return null;
    const kind = input.kind ?? "telegram";

    let key: string;
    let label: string | undefined;
    let url = input.url?.trim() || undefined;
    if (kind === "telegram") {
      key = normalizeChannel(raw);
      if (!CHANNEL_RE.test(key)) return null;
    } else {
      label = (input.label ?? raw).trim();
      if (isUrl(raw) && !url) {
        url = raw;
        if (label === raw) label = defaultLabelFor(kind, raw);
      }
      if (!label) return null;
      key = slugify(label) || slugify(url ?? "") || kind;
    }

    const existing = this.watches.find(
      (w) => w.kind === kind && w.channel.toLowerCase() === key.toLowerCase(),
    );
    if (existing) {
      if (input.chatId !== undefined) existing.chatId = input.chatId;
      if (input.remindHour !== undefined) existing.remindHour = clampHour(input.remindHour);
      if (label) existing.label = label;
      if (url) existing.url = url;
      await this.persist();
      return existing;
    }

    this.counter += 1;
    const watch: ChannelWatch = {
      id: `ch${this.counter}`,
      kind,
      channel: key,
      label,
      url,
      reporter: input.reporter,
      chatId: input.chatId,
      remindHour:
        input.remindHour !== undefined ? clampHour(input.remindHour) : this.defaultRemindHour,
      createdAt: Date.now(),
    };
    this.watches.push(watch);
    await this.persist();
    log.info(
      `venue added: [${watch.id}] ${venueLabel(watch)} (${watch.kind}, ` +
        `${isReadableVenue(watch) ? "readable" : "blind"}, remind after ${watch.remindHour}:00)`,
    );
    return watch;
  }

  async removeWatch(idOrName: string): Promise<boolean> {
    const target = this.match(idOrName);
    if (!target) return false;
    this.watches = this.watches.filter((w) => w !== target);
    await this.persist();
    return true;
  }

  /**
   * "я уже написал туда" — silence a blind venue for the rest of the day.
   * Without a name it covers every blind venue, since the readable ones answer
   * that question themselves. Returns what it actually marked.
   */
  async markPosted(idOrName?: string, day = localDay()): Promise<ChannelWatch[]> {
    const marked = idOrName?.trim()
      ? [this.match(idOrName)].filter((w): w is ChannelWatch => Boolean(w))
      : this.watches.filter((w) => !isReadableVenue(w));
    if (!marked.length) return [];
    for (const watch of marked) watch.lastPostedDay = day;
    await this.persist();
    return marked;
  }

  /** Find a venue by id, key, label, or — when unambiguous — by its kind. */
  match(key: string): ChannelWatch | undefined {
    const k = key.trim().toLowerCase();
    if (!k) return undefined;
    const bare = normalizeChannel(key).toLowerCase();
    const direct = this.watches.find(
      (w) =>
        w.id.toLowerCase() === k ||
        w.channel.toLowerCase() === k ||
        w.channel.toLowerCase() === bare ||
        (w.label ?? "").toLowerCase() === k ||
        (w.url ?? "").toLowerCase() === k,
    );
    if (direct) return direct;
    // "дискорд", "чат в твиттере" — a kind names a venue while it is the only one.
    const kind = inferVenueKind(k);
    const ofKind = this.watches.filter((w) => w.kind === kind);
    if (kind !== "other" && ofKind.length === 1) return ofKind[0];
    return this.watches.find((w) => (w.label ?? "").toLowerCase().includes(k));
  }

  /**
   * Read the channel's public preview and report today's activity. Returns
   * null when the page could not be fetched or holds no parseable posts (a
   * private channel, a blocked preview) — an unknown day is never nudged.
   */
  async activityToday(channel: string): Promise<ChannelActivity | null> {
    try {
      const html = await this.get(`https://t.me/s/${channel}`);
      const activity = parseChannelPosts(html, localDay());
      return activity.lastPostAt === null ? null : activity;
    } catch (err) {
      log.warn(`preview fetch failed for t.me/${channel}`, err);
      return null;
    }
  }

  /** Scheduled sweep: one nudge per chat per day, covering every quiet venue. */
  private async tick(): Promise<void> {
    if (this.busy) return;
    this.busy = true;
    try {
      const now = new Date();
      const day = localDay(now);
      const due: ChannelWatch[] = [];
      let dirty = false;
      for (const watch of this.watches) {
        if (watch.lastRemindedDay === day || watch.lastPostedDay === day) continue;
        if (now.getHours() < watch.remindHour) continue;
        let activity: ChannelActivity | null = null;
        if (isReadableVenue(watch)) {
          activity = await this.activityToday(watch.channel);
          watch.lastCheckedAt = Date.now();
          if (activity?.lastPostAt) watch.lastPostAt = activity.lastPostAt;
          dirty = true;
        }
        if (isVenueDue(watch, now, activity)) due.push(watch);
      }
      for (const watch of due) {
        watch.lastRemindedDay = day;
        dirty = true;
      }
      if (dirty) await this.persist();
      for (const [chatId, group] of groupByChat(due)) {
        const text = reminderText(group);
        for (const fn of this.subscribers) {
          try {
            fn({ kind: "reminder", text, watches: group, chatId });
          } catch {
            /* a broken subscriber must never break the watcher */
          }
        }
      }
    } finally {
      this.busy = false;
    }
  }

  /** GET with the proxy when configured, falling back to a direct request. */
  private async get(url: string): Promise<string> {
    try {
      return await this.fetchOnce(url, this.dispatcher);
    } catch (err) {
      if (!this.dispatcher) throw err;
      return this.fetchOnce(url, undefined);
    }
  }

  private async fetchOnce(url: string, dispatcher?: Dispatcher): Promise<string> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15_000);
    try {
      const res = await undiciFetch(url, {
        headers: {
          "user-agent":
            "Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0",
        },
        dispatcher,
        signal: controller.signal,
      });
      if (!res.ok) throw new Error(`${new URL(url).host} HTTP ${res.status}`);
      return await res.text();
    } finally {
      clearTimeout(timer);
    }
  }

  private async persist(): Promise<void> {
    await mkdir(dirname(this.file), { recursive: true });
    const payload: ChannelFile = { watches: this.watches, counter: this.counter };
    await writeFile(this.file, JSON.stringify(payload, null, 2), "utf8");
  }
}

// ------------------------------------------------------------------ helpers

function clampHour(hour: number): number {
  return Number.isFinite(hour) ? Math.min(23, Math.max(0, Math.round(hour))) : DEFAULT_REMIND_HOUR;
}

function isUrl(raw: string): boolean {
  return /^https?:\/\//i.test(raw.trim());
}

function defaultLabelFor(kind: VenueKind, raw: string): string {
  if (kind === "discord") return "дискорд";
  if (kind === "twitter") return "чат в X";
  try {
    return new URL(raw).host;
  } catch {
    return raw;
  }
}

function slugify(raw: string): string {
  return raw
    .trim()
    .toLowerCase()
    .replace(/^https?:\/\//, "")
    .replace(/[^a-z0-9а-яё]+/gi, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 48);
}

/** "a, b и c" — the reminder reads as a sentence, not a CSV row. */
function listRu(items: string[]): string {
  if (items.length <= 1) return items[0] ?? "";
  return `${items.slice(0, -1).join(", ")} и ${items[items.length - 1]}`;
}

function groupByChat(watches: ChannelWatch[]): Map<number | undefined, ChannelWatch[]> {
  const groups = new Map<number | undefined, ChannelWatch[]>();
  for (const watch of watches) {
    const group = groups.get(watch.chatId);
    if (group) group.push(watch);
    else groups.set(watch.chatId, [watch]);
  }
  return groups;
}

function getChannels(runtime: IAgentRuntime): ChannelWatchService {
  const svc = runtime.getService<ChannelWatchService>("channel-watch");
  if (!svc) throw new Error("channel-watch service not started");
  return svc;
}

function chatIdFromState(state: State): number | undefined {
  if (!state.roomId.startsWith("tg-")) return undefined;
  const id = Number(state.roomId.slice(3));
  return Number.isFinite(id) ? id : undefined;
}

// ------------------------------------------------------------------ actions

const watchChannelPostsAction: Action = {
  name: "watch_channel_posts",
  similes: ["watch_telegram_channel", "channel_streak", "post_reminder", "watch_posts"],
  description:
    "Remind the user every day when a public Telegram channel has no posts yet: watches the channel's public preview (t.me/s/<name>) and sends one reminder in the evening on days without posts (silence on days with them). Use when someone asks to make sure a channel posts daily — e.g. because its posts are mirrored to Twitter and move the token price. For a Discord server or an X group chat, use watch_chat_silence instead — those cannot be read from outside.",
  parameters: {
    type: "object",
    properties: {
      channel: {
        type: "string",
        description: "Public channel username or t.me link, e.g. 'cyberia_church'.",
      },
      remind_hour: {
        type: "number",
        description: "Hour of day (0-23) after which to remind on postless days. Default 18.",
      },
    },
    required: ["channel"],
  },
  examples: [
    {
      user: "следи за пабликом t.me/cyberia_church — каждый день должны быть посты",
      agent: "слежу за t.me/cyberia_church — напомню вечером, если за день не вышло ни одного поста.",
    },
  ],
  async validate(runtime) {
    return Boolean(runtime.getService("channel-watch"));
  },
  async handler(runtime, state, params) {
    const svc = getChannels(runtime);
    const channel = String(params.channel ?? "").trim();
    if (!channel) return { ok: false, text: "I need the channel username to watch." };
    const hour = Number(params.remind_hour);
    const watch = await svc.addWatch({
      channel,
      kind: "telegram",
      reporter: state.message.userId,
      chatId: chatIdFromState(state),
      remindHour: Number.isFinite(hour) ? hour : undefined,
    });
    if (!watch) {
      return { ok: false, text: `"${channel}" doesn't look like a public channel username.` };
    }
    return {
      ok: true,
      text:
        `Watching t.me/${watch.channel} (${watch.id}): on days with no posts ` +
        `I'll remind you here after ${watch.remindHour}:00.`,
      data: { id: watch.id, channel: watch.channel, remindHour: watch.remindHour },
    };
  },
};

const watchChatSilenceAction: Action = {
  name: "watch_chat_silence",
  similes: [
    "watch_discord",
    "watch_twitter_chat",
    "remind_to_write",
    "watch_chat",
    "chat_reminder",
  ],
  description:
    "Add a daily reminder to write somewhere that cannot be read from outside — a Discord server behind an invite, a group chat or DM in X, any other room. There is no way to check whether it is really quiet (Discord needs a bot inside the guild, an X chat needs the account's session), so the reminder fires on schedule and says so; the user silences it for a day with mark_venue_posted. Reminders for all watched places arrive as one evening message.",
  parameters: {
    type: "object",
    properties: {
      place: {
        type: "string",
        description: "What to nudge about: a name like 'дискорд' / 'чат в X', or a link to it.",
      },
      kind: {
        type: "string",
        description: "One of 'discord', 'twitter', 'other'. Inferred from the name when omitted.",
      },
      link: {
        type: "string",
        description: "Optional invite/chat URL, included in the reminder.",
      },
      remind_hour: {
        type: "number",
        description: "Hour of day (0-23) after which to nudge. Default 18.",
      },
    },
    required: ["place"],
  },
  examples: [
    {
      user: "у меня ещё чат в твиттере и дискорд, там тишина — напоминай туда писать",
      agent:
        "буду напоминать про оба вечером вместе с каналом. заглянуть внутрь я не могу — если написал, скажи, и я замолчу до завтра.",
    },
  ],
  async validate(runtime) {
    return Boolean(runtime.getService("channel-watch"));
  },
  async handler(runtime, state, params) {
    const svc = getChannels(runtime);
    const place = String(params.place ?? "").trim();
    if (!place) return { ok: false, text: "I need to know which chat to remind you about." };
    const asked = String(params.kind ?? "").trim().toLowerCase();
    const kind: VenueKind =
      asked === "discord" || asked === "twitter" || asked === "telegram" || asked === "other"
        ? (asked as VenueKind)
        : inferVenueKind(`${place} ${params.link ?? ""}`);
    if (kind === "telegram") {
      return {
        ok: false,
        text: "That looks like a Telegram channel — watch_channel_posts reads it for real.",
      };
    }
    const hour = Number(params.remind_hour);
    const watch = await svc.addWatch({
      channel: place,
      kind,
      url: params.link ? String(params.link) : undefined,
      reporter: state.message.userId,
      chatId: chatIdFromState(state),
      remindHour: Number.isFinite(hour) ? hour : undefined,
    });
    if (!watch) return { ok: false, text: `I couldn't make a watch out of "${place}".` };
    return {
      ok: true,
      text:
        `Watching ${venueLabel(watch)} (${watch.id}): I'll nudge you here after ` +
        `${watch.remindHour}:00 every day. I can't see inside it, so the nudge says so — ` +
        `tell me you've written there and it stays quiet until tomorrow.`,
      data: {
        id: watch.id,
        kind: watch.kind,
        label: venueLabel(watch),
        remindHour: watch.remindHour,
        readable: false,
      },
    };
  },
};

const markVenuePostedAction: Action = {
  name: "mark_venue_posted",
  similes: ["already_posted", "wrote_there", "silence_today", "posted_already"],
  description:
    "Record that the user has already written in a watched chat today, so its reminder stays quiet until tomorrow. Only meaningful for places that cannot be read (Discord, X chats) — a Telegram channel answers that question by itself. Without a name it covers every unreadable place at once.",
  parameters: {
    type: "object",
    properties: {
      id: {
        type: "string",
        description: "Watch id ('ch2'), name ('дискорд') or link. Omit for all blind venues.",
      },
    },
  },
  examples: [
    { user: "я уже написал в дискорд", agent: "поняла, сегодня про дискорд не напоминаю." },
  ],
  async validate(runtime) {
    return Boolean(runtime.getService("channel-watch"));
  },
  async handler(runtime, _state, params) {
    const svc = getChannels(runtime);
    const id = params.id ? String(params.id) : undefined;
    const marked = await svc.markPosted(id);
    if (!marked.length) {
      return {
        ok: false,
        text: id ? `No watched chat matching ${id}.` : "No unreadable chats are being watched.",
      };
    }
    return {
      ok: true,
      text: `Noted — no reminder today for ${marked.map(venueLabel).join(", ")}.`,
      data: { marked: marked.map((w) => ({ id: w.id, label: venueLabel(w) })) },
    };
  },
};

const checkChannelPostsAction: Action = {
  name: "check_channel_posts",
  similes: ["channel_today", "posts_today", "last_post", "channel_status"],
  description:
    "Check right now whether a public Telegram channel has posts today, and when the last post went out. Defaults to the watched channel when only one is watched. Discord servers and X chats cannot be checked this way — it will say so.",
  parameters: {
    type: "object",
    properties: {
      channel: { type: "string", description: "Channel username. Defaults to the watched one." },
    },
  },
  examples: [{ user: "в канале сегодня постили?", agent: "смотрю превью канала…" }],
  async validate(runtime) {
    return Boolean(runtime.getService("channel-watch"));
  },
  async handler(runtime, _state, params) {
    const svc = getChannels(runtime);
    const asked = String(params.channel ?? "").trim();
    const readable = svc.listWatches().filter(isReadableVenue);

    if (asked) {
      const watched = svc.match(asked);
      if (watched && !isReadableVenue(watched)) {
        const day = localDay();
        return {
          ok: true,
          text:
            `${venueLabel(watched)} can't be read from outside — I only know whether you told ` +
            `me you wrote there today (${watched.lastPostedDay === day ? "you did" : "you haven't"}).`,
          data: { id: watched.id, kind: watched.kind, readable: false },
        };
      }
    }

    const channel =
      normalizeChannel(asked) || (readable.length === 1 ? readable[0].channel : "");
    if (!channel) {
      return { ok: false, text: "Which channel? I'm not watching exactly one." };
    }
    const activity = await svc.activityToday(channel);
    if (!activity) {
      return {
        ok: false,
        text: `Couldn't read the public preview of t.me/${channel} right now (is it public?).`,
      };
    }
    const last = activity.lastPostAt
      ? new Date(activity.lastPostAt).toLocaleString("ru-RU")
      : "unknown";
    return {
      ok: true,
      text:
        activity.postsToday > 0
          ? `Yes — t.me/${channel} has ${activity.postsToday} post(s) today; last at ${last}.`
          : `Not yet — t.me/${channel} has no posts today. Last post: ${last}. Twitter is waiting.`,
      data: { channel, ...activity },
    };
  },
};

const stopChannelWatchAction: Action = {
  name: "stop_channel_watch",
  similes: ["unwatch_channel", "stop_post_reminder", "remove_channel_watch", "unwatch_chat"],
  description:
    "Stop a daily reminder, by watch id (ch1), channel username, or the name of a chat ('дискорд').",
  parameters: {
    type: "object",
    properties: {
      id: { type: "string", description: "Watch id (e.g. 'ch1'), channel username or chat name." },
    },
    required: ["id"],
  },
  examples: [{ user: "хватит следить за каналом", agent: "убрала напоминание." }],
  async validate(runtime) {
    return Boolean(runtime.getService("channel-watch"));
  },
  async handler(runtime, _state, params) {
    const svc = getChannels(runtime);
    const id = String(params.id ?? "").trim();
    const target = svc.match(id);
    const removed = target ? await svc.removeWatch(id) : false;
    return removed
      ? { ok: true, text: `Stopped watching ${target ? venueLabel(target) : id}.` }
      : { ok: false, text: `No watch matching ${id}.` };
  },
};

// ------------------------------------------------------------------ provider

const channelProvider: Provider = {
  name: "channel",
  async get(runtime) {
    const svc = runtime.getService<ChannelWatchService>("channel-watch");
    if (!svc) return "";
    const watches = svc.listWatches();
    if (!watches.length) {
      return (
        "You can keep the project's rooms alive: watch_channel_posts sets a daily reminder for " +
        "a public Telegram channel that fires only on days it published nothing, and " +
        "watch_chat_silence does the same on schedule for places you cannot read " +
        "(a Discord behind an invite, a group chat in X)."
      );
    }
    const listed = watches
      .map(
        (w) =>
          `${w.id} ${venueLabel(w)} (${isReadableVenue(w) ? "readable" : "blind"}, ` +
          `remind after ${w.remindHour}:00)`,
      )
      .join(", ");
    const blind = watches.filter((w) => !isReadableVenue(w));
    return (
      `You watch these rooms for daily activity: ${listed}. All due reminders go out ` +
      `automatically as one evening message. check_channel_posts answers "did the channel ` +
      `post today" for Telegram.` +
      (blind.length
        ? ` ${listRu(blind.map(venueLabel))} cannot be read from outside — never claim to know ` +
          `whether they are quiet; when the user says they have already written there, call ` +
          `mark_venue_posted so today's nudge is dropped.`
        : "")
    );
  },
};

export const channelPlugin: Plugin = {
  name: "channel",
  description:
    "Keeper of the rooms Cyberia speaks in: reads a public Telegram channel's posts for real, nudges on schedule about the ones nothing can read (Discord, X chats), and delivers every due reminder as one evening message.",
  services: [new ChannelWatchService()],
  providers: [channelProvider],
  actions: [
    watchChannelPostsAction,
    watchChatSilenceAction,
    markVenuePostedAction,
    checkChannelPostsAction,
    stopChannelWatchAction,
  ],
};
