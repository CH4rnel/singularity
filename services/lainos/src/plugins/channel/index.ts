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
 * The channel plugin keeps a public Telegram channel alive. The Cyberia
 * channel's posts are cross-posted to Twitter, and Twitter moves the CYBER.sol
 * price — so a day without posts is a day of lost signal. A holder says
 * "следи, чтобы в канале каждый день были посты" — that becomes a *watch*: the
 * service reads the channel's public web preview (t.me/s/<name>, no admin
 * rights needed) and, past the reminder hour on a day with zero posts, nudges
 * the holder in Telegram. Days with posts pass in silence; a day is never
 * nudged twice.
 *
 * Watches persist in `data/channels.json`; reminders are pushed through
 * onEvent (Telegram chat of the requester) like sentinel alerts.
 */

export interface ChannelWatch {
  id: string;
  /** Public channel username, e.g. "cyberia_church" (t.me/<name>). */
  channel: string;
  reporter: string;
  /** Telegram chat to deliver to, when known. */
  chatId?: number;
  /** Host-local hour (0-23) after which a postless day triggers the nudge. */
  remindHour: number;
  createdAt: number;
  lastCheckedAt?: number;
  /** Unix ms of the newest post seen on the last check. */
  lastPostAt?: number;
  /** YYYY-MM-DD of the last day a reminder went out (max one per day). */
  lastRemindedDay?: string;
}

export interface ChannelEvent {
  kind: "reminder";
  text: string;
  watch: ChannelWatch;
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
      this.watches = parsed.watches ?? [];
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
      `channel watch online: ${this.watches.length} watch(es)` +
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

  async addWatch(input: {
    channel: string;
    reporter: string;
    chatId?: number;
    remindHour?: number;
  }): Promise<ChannelWatch | null> {
    const channel = normalizeChannel(input.channel);
    if (!CHANNEL_RE.test(channel)) return null;
    const existing = this.watches.find((w) => w.channel.toLowerCase() === channel.toLowerCase());
    if (existing) {
      if (input.chatId !== undefined) existing.chatId = input.chatId;
      if (input.remindHour !== undefined) existing.remindHour = clampHour(input.remindHour);
      await this.persist();
      return existing;
    }
    this.counter += 1;
    const watch: ChannelWatch = {
      id: `ch${this.counter}`,
      channel,
      reporter: input.reporter,
      chatId: input.chatId,
      remindHour:
        input.remindHour !== undefined ? clampHour(input.remindHour) : this.defaultRemindHour,
      createdAt: Date.now(),
    };
    this.watches.push(watch);
    await this.persist();
    log.info(`watch added: [${watch.id}] t.me/${watch.channel} (remind after ${watch.remindHour}:00)`);
    return watch;
  }

  async removeWatch(idOrChannel: string): Promise<boolean> {
    const key = normalizeChannel(idOrChannel).toLowerCase();
    const before = this.watches.length;
    this.watches = this.watches.filter(
      (w) => w.id.toLowerCase() !== key && w.channel.toLowerCase() !== key,
    );
    if (this.watches.length !== before) {
      await this.persist();
      return true;
    }
    return false;
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

  /** Scheduled sweep: nudge every due watch, at most once per day each. */
  private async tick(): Promise<void> {
    if (this.busy) return;
    this.busy = true;
    try {
      const now = new Date();
      const day = localDay(now);
      for (const watch of this.watches) {
        if (watch.lastRemindedDay === day) continue;
        if (now.getHours() < watch.remindHour) continue;
        const activity = await this.activityToday(watch.channel);
        watch.lastCheckedAt = Date.now();
        if (activity?.lastPostAt) watch.lastPostAt = activity.lastPostAt;
        if (!activity || activity.postsToday > 0) {
          await this.persist();
          continue;
        }
        watch.lastRemindedDay = day;
        await this.persist();
        const last = activity.lastPostAt
          ? ` последний пост: ${new Date(activity.lastPostAt).toLocaleString("ru-RU")}.`
          : "";
        const text =
          `📣 напоминание: сегодня в t.me/${watch.channel} ещё нет постов —` +
          ` а посты уходят в твиттер, который двигает курс CYBER.sol.${last}`;
        for (const fn of this.subscribers) {
          try {
            fn({ kind: "reminder", text, watch, chatId: watch.chatId });
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
    "Remind the user every day when a public Telegram channel has no posts yet: watches the channel's public preview (t.me/s/<name>) and sends one reminder in the evening on days without posts (silence on days with them). Use when someone asks to make sure a channel posts daily — e.g. because its posts are mirrored to Twitter and move the token price.",
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

const checkChannelPostsAction: Action = {
  name: "check_channel_posts",
  similes: ["channel_today", "posts_today", "last_post", "channel_status"],
  description:
    "Check right now whether a public Telegram channel has posts today, and when the last post went out. Defaults to the watched channel when only one is watched.",
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
    const watches = svc.listWatches();
    const channel =
      normalizeChannel(String(params.channel ?? "")) ||
      (watches.length === 1 ? watches[0].channel : "");
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
  similes: ["unwatch_channel", "stop_post_reminder", "remove_channel_watch"],
  description: "Stop the daily channel-post reminder, by watch id (ch1) or channel username.",
  parameters: {
    type: "object",
    properties: {
      id: { type: "string", description: "Watch id (e.g. 'ch1') or channel username." },
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
    const removed = await svc.removeWatch(id);
    return removed
      ? { ok: true, text: `Stopped watching ${id}.` }
      : { ok: false, text: `No channel watch matching ${id}.` };
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
        "You can keep a Telegram channel alive: watch_channel_posts sets a daily reminder " +
        "that fires only on days the channel published nothing."
      );
    }
    return (
      `You watch Telegram channels for daily posts: ` +
      watches
        .map((w) => `${w.id} t.me/${w.channel} (remind after ${w.remindHour}:00)`)
        .join(", ") +
      `. Reminders go out automatically; check_channel_posts answers "did the channel post today".`
    );
  },
};

export const channelPlugin: Plugin = {
  name: "channel",
  description:
    "Telegram channel keeper: watches public channels and reminds holders in the evening of days without posts (the posts feed Twitter, which moves CYBER.sol).",
  services: [new ChannelWatchService()],
  providers: [channelProvider],
  actions: [watchChannelPostsAction, checkChannelPostsAction, stopChannelWatchAction],
};
