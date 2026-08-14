import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Box, Text, useApp, useStdin, useStdout } from "ink";
import { ModelTier, type AgentEvent, type IAgentRuntime } from "../../types.js";
import {
  CHAT_PROVIDER_CHOICES,
  chatProviderLabel,
  resolveChatProviderKind,
  SwitchableModelProvider,
} from "../../models/routing.js";
import {
  DEFAULT_THEME,
  GLYPH,
  THEME_ORDER,
  THEMES,
  loadCursor,
  loadEffort,
  loadPulse,
  loadSkin,
  saveCursor,
  saveEffort,
  savePulse,
  saveSkin,
  type Theme,
} from "./theme.js";
import { blank, concat, fitLine, padLine, sp, truncateLine, type Line, type Span } from "./markdown.js";
import { editLine } from "./editor.js";
import { InputHistory, loadInputHistory, saveInputHistory } from "./history.js";
import { ESC_TIMEOUT_MS, KeyReader, type KeyPress, type MouseInfo, type TuiKey } from "./keys.js";
import { ChainPulse } from "./pulse.js";
import { cursorToWrap, wrapIndices } from "./transcript.js";
import {
  bannerLines,
  bootLines,
  sidebarLines,
  spinnerChar,
  turnLines,
  type FeedRegion,
  type SidebarRegion,
  type Turn,
} from "./layout.js";
import type { ForgeService } from "../../plugins/forge/index.js";
import type { ScoutService } from "../../plugins/scout/index.js";
import type { SentinelService } from "../../plugins/sentinel/index.js";

// ------------------------------------------------------------------ model

type PickerOption = { value: string; label: string; hint?: string };
type PickerState = {
  title: string;
  kind: "skin" | "plain";
  options: PickerOption[];
  index: number;
  onPick: (value: string) => void;
  onHighlight?: (value: string) => void;
  onCancel?: () => void;
};

const COMMANDS = [
  { name: "/help", desc: "show commands" },
  { name: "/skills", desc: "list chain skills" },
  { name: "/facts", desc: "durable facts lain remembers" },
  { name: "/watches", desc: "active background watches" },
  { name: "/wishes", desc: "the forge wishboard" },
  { name: "/research", desc: "scout research topics" },
  { name: "/pulse", desc: "toggle whale transfer notices" },
  { name: "/skin", desc: "pick a colour skin (arrows)" },
  { name: "/effort", desc: "set reply depth (arrows)" },
  { name: "/cursor", desc: "cursor style + blink (arrows)" },
  { name: "/clear", desc: "clear the screen (memory intact)" },
  { name: "/copy", desc: "copy lain's last reply to the clipboard" },
  { name: "/reset", desc: "fresh memory room" },
  { name: "/model", desc: "switch claude/codex/opencode (arrows)" },
  { name: "/exit", desc: "leave the wired" },
];

/** Bucket a skill (action) name into a stylish category. */
function skillCategory(name: string): "wallet" | "tx" | "memory" | "system" | "chain" {
  if (/balance|overview|token/.test(name)) return "wallet";
  if (/send|transfer/.test(name)) return "tx";
  if (/remember|recall|memor/.test(name)) return "memory";
  if (/shell|exec|file|dir|^ls$|read|write|list/.test(name)) return "system";
  if (/tx/.test(name)) return "tx";
  return "chain";
}
const SKILL_ORDER = ["chain", "wallet", "tx", "memory", "system"] as const;

const EFFORTS = [
  { value: "low", label: "low", tokens: 512, desc: "terse · fast" },
  { value: "medium", label: "medium", tokens: 1024, desc: "balanced (default)" },
  { value: "high", label: "high", tokens: 2048, desc: "thorough" },
  { value: "max", label: "max", tokens: 4096, desc: "deepest · slowest" },
];
const effortTokens = (v: string) => EFFORTS.find((e) => e.value === v)?.tokens ?? 1024;

const CURSORS = [
  { value: "block-blink", label: "block · blink", desc: "fat, blinking (default)" },
  { value: "block-steady", label: "block · steady", desc: "fat, no blink" },
  { value: "line-blink", label: "line · blink", desc: "thin underline, blinking" },
  { value: "line-steady", label: "line · steady", desc: "thin underline, no blink" },
];

let _seq = 0;
const nextId = () => `t${++_seq}`;
const sysTurn = (text: string): Turn => ({ id: nextId(), role: "sys", parts: [{ kind: "text", text }] });

const clamp = (n: number, lo: number, hi: number): number => Math.min(hi, Math.max(lo, n));
function fmtTokens(n: number): string {
  return n >= 1000 ? `${(n / 1000).toFixed(1)}k` : String(n);
}
function estimateTokens(history: Turn[], live: Turn | null): number {
  let chars = 0;
  const add = (t: Turn) => {
    for (const p of t.parts) {
      if (p.kind === "text") chars += p.text.length;
      else chars += p.tool.name.length + (p.tool.summary?.length ?? 0);
    }
  };
  history.forEach(add);
  if (live) add(live);
  return Math.round(chars / 4);
}
function suggestionsFor(value: string): typeof COMMANDS {
  if (!value.startsWith("/") || value.includes(" ")) return [];
  return COMMANDS.filter((c) => c.name.startsWith(value.toLowerCase()));
}

// ------------------------------------------------------------ live chain

function useChainHeight(rpc: string): number | null {
  const [block, setBlock] = useState<number | null>(null);
  useEffect(() => {
    let alive = true;
    const poll = async () => {
      try {
        const res = await fetch(rpc, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "eth_blockNumber", params: [] }),
        });
        const j = (await res.json()) as { result?: string };
        if (alive && typeof j.result === "string") setBlock(parseInt(j.result, 16));
      } catch {
        /* offline — keep last height */
      }
    };
    void poll();
    const t = setInterval(() => void poll(), 5000);
    return () => {
      alive = false;
      clearInterval(t);
    };
  }, [rpc]);
  return block;
}

/** Terminal size that tracks window resizes. */
function useStdoutDimensions(): { width: number; rows: number } {
  const { stdout } = useStdout();
  const [size, setSize] = useState(() => ({
    width: stdout?.columns ?? 80,
    rows: stdout?.rows ?? 24,
  }));
  useEffect(() => {
    if (!stdout) return;
    const onResize = () => setSize({ width: stdout.columns ?? 80, rows: stdout.rows ?? 24 });
    stdout.on("resize", onResize);
    return () => {
      stdout.off("resize", onResize);
    };
  }, [stdout]);
  return size;
}

/** Advance a frame counter while `active` — drives spinner glyphs. */
function useSpin(active: boolean): number {
  const [frame, setFrame] = useState(0);
  useEffect(() => {
    if (!active) return;
    const id = setInterval(() => setFrame((f) => f + 1), 90);
    return () => clearInterval(id);
  }, [active]);
  return frame;
}

/** One vertical strip of the feed's scrollbar thumb (empty when not needed). */
function scrollbarChar(i: number, viewport: number, maxScroll: number, scrollTop: number): string {
  if (maxScroll <= 0) return " ";
  const thumb = Math.max(1, Math.round((viewport * viewport) / (viewport + maxScroll)));
  const track = viewport - thumb;
  const pos = maxScroll ? Math.round((track * scrollTop) / maxScroll) : 0;
  return i >= pos && i < pos + thumb ? "█" : "░";
}

// ------------------------------------------------------------ components

/** Render one styled line into a single ink <Text>. */
function LineView({ line }: { line: Line }) {
  return (
    <Text wrap="truncate">
      {line.map((s, i) => (
        <Text key={i} color={s.c} bold={s.b} italic={s.i} underline={s.u} backgroundColor={s.bg}>
          {s.t}
        </Text>
      ))}
    </Text>
  );
}

/** The bottom widget: status divider, status line, thinking row, menus and
 *  the multi-line composer, flattened to exactly `chromeRows` lines. */
function chromeLines(args: {
  theme: Theme;
  width: number;
  status: "idle" | "thinking" | "streaming";
  thinkingOn: boolean;
  spin: number;
  provider: string;
  model: string;
  block: number | null;
  tokens: number;
  room: string;
  effort: string;
  statusLines: number;
  thinkingLines: number;
  menuLines: Line[];
  pickerLines: Line[];
  composerWrap: { text: string; start: number; end: number }[];
  cursorLine: number;
  cursorCol: number;
  blinkOn: boolean;
  blinkEnabled: boolean;
  cursorStyle: "block" | "line";
  busy: boolean;
  showHint: boolean;
}): Line[] {
  const c = args.theme;
  const out: Line[] = [];

  out.push([{ t: "─".repeat(args.width), c: c.mutedDim }]);
  out.push(
    truncateLine(
      padLine(
        concat(
          [sp(args.status === "idle" ? "● " : "◐ ", args.status === "idle" ? c.ok : c.warn)],
          [sp(args.provider, c.primary)],
          [sp(` ${GLYPH.dot} `, c.mutedDim)],
          [sp(args.model, c.secondary)],
          [sp(`   ${GLYPH.chain} `, c.mutedDim)],
          [sp(args.block === null ? "—" : args.block.toLocaleString("en-US"), c.fg)],
          [sp("   ◷ ", c.mutedDim)],
          [sp(fmtTokens(args.tokens), c.fg)],
          [sp(" tok", c.mutedDim)],
          [sp("   effort:", c.mutedDim)],
          [sp(args.effort, c.secondary)],
          [sp("   skin:", c.mutedDim)],
          [sp(c.name, c.primary)],
          [sp("   room:", c.mutedDim)],
          [sp(args.room, c.fg)],
        ),
        args.width,
      ),
      args.width,
    ),
  );

  if (args.thinkingOn) {
    out.push(concat([sp(`${spinnerChar(args.spin)} `, c.warn)], [sp("reaching into the wired…", c.mutedDim)]));
  }

  if (args.menuLines.length) out.push(...args.menuLines);
  if (args.pickerLines.length) out.push(...args.pickerLines);

  const prefix = (busy: boolean, first: boolean): Span => sp(first ? `${GLYPH.you} ` : "  ", busy ? c.mutedDim : c.primary);
  out.push(blank(args.width)); // margin above the composer
  args.composerWrap.forEach((wl, li) => {
    const first = li === 0;
    const row: Span[] = [prefix(args.busy, first)];
    if (li === args.cursorLine) {
      const before = wl.text.slice(0, args.cursorCol);
      const at = wl.text.slice(args.cursorCol, args.cursorCol + 1) || " ";
      const after = wl.text.slice(args.cursorCol + 1);
      const show = !args.blinkEnabled || args.blinkOn;
      const cursor: Span =
        args.cursorStyle === "line"
          ? { t: at, c: show ? c.primary : c.fg, u: show }
          : show
            ? { t: at, c: "#0b0b12", bg: c.primary }
            : { t: at, c: c.fg };
      row.push(sp(before, c.fg), cursor, sp(after, c.fg));
    } else {
      row.push(sp(wl.text, c.fg));
    }
    if (first && args.cursorLine === 0 && wl.text.length === 0 && !args.busy) {
      row.push(sp("  ask lain…", c.mutedDim));
    }
    out.push(padLine(truncateLine(row, args.width), args.width));
  });

  if (args.showHint) {
    out.push(concat([sp("  ", c.mutedDim)], [sp("↑↓ history · wheel scroll · / commands · Tab complete · shift/ctrl+enter newline", c.mutedDim)]));
  }

  return out;
}

/** The slash-command autocomplete menu, margin + one row per command. */
function menuLines(items: typeof COMMANDS, index: number, theme: Theme, width: number): Line[] {
  const c = theme;
  const out: Line[] = [blank(width)];
  for (let i = 0; i < items.length; i++) {
    const it = items[i];
    const on = i === index;
    out.push(
      concat(
        [sp(on ? "❯ " : "  ", on ? c.primary : c.mutedDim)],
        [{ t: it.name.padEnd(10), c: on ? c.primary : c.secondary, b: on }],
        [sp(it.desc, c.mutedDim)],
      ),
    );
  }
  return out;
}

/** The bordered arrow-key picker (skin/effort/cursor/model). */
function pickerLines(state: PickerState, theme: Theme, width: number): Line[] {
  const c = theme;
  const out: Line[] = [blank(width)];
  const title = ` ${state.title}  ↑↓ move · enter select · esc cancel `;
  out.push(truncateLine([{ t: `╭─${title}${"─".repeat(Math.max(0, width - title.length - 3))}╮`, c: c.border }], width));
  for (let i = 0; i < state.options.length; i++) {
    const opt = state.options[i];
    const on = i === state.index;
    const th = state.kind === "skin" ? THEMES[opt.value] : undefined;
    const row: Span[] = [
      sp(on ? " ❯ " : "   ", on ? c.primary : c.mutedDim),
      { t: opt.label.padEnd(11), c: on ? c.primary : c.fg, b: on },
    ];
    if (th) {
      for (const col of [th.primary, th.secondary, th.ok, th.warn, th.err]) row.push(sp(GLYPH.swatch, col));
      row.push(sp(`  ${th.label}`, c.mutedDim));
    } else if (opt.hint) {
      row.push(sp(opt.hint, c.mutedDim));
    }
    out.push(truncateLine(concat(row), width));
  }
  out.push([{ t: `╰${"─".repeat(Math.max(1, width - 2))}╯`, c: c.border }]);
  return out;
}

/**
 * Single keyboard sink, mounted only when raw mode is available (a real TTY).
 *
 * ink's `useInput` is deliberately bypassed: it hands the handler a parsed
 * `Key` with the escape sequence already gone, so Home, End, Delete and
 * ctrl+←/→ are indistinguishable from nothing at all. We subscribe to the raw
 * stdin chunks ink emits and parse them ourselves (see ./keys.ts). Mouse
 * reporting is enabled here too: wheel events scroll the feed, and clicks
 * toggle tools / sidebar rows.
 */
function InputCapture({ onKey }: { onKey: (input: string, key: TuiKey) => void }) {
  const { setRawMode, internal_eventEmitter, internal_exitOnCtrlC } = useStdin();
  const { stdout } = useStdout();
  const sink = useRef(onKey);
  useEffect(() => {
    sink.current = onKey;
  });

  useEffect(() => {
    setRawMode(true);
    const reader = new KeyReader();
    let escTimer: ReturnType<typeof setTimeout> | undefined;
    const emit = (presses: KeyPress[]) => {
      for (const { input, key } of presses) {
        // ink's own handler exits on ctrl+c; don't also type it into the line.
        if (internal_exitOnCtrlC && key.ctrl && input === "c") continue;
        sink.current(input, key);
      }
    };
    const onData = (chunk: unknown) => {
      if (escTimer) clearTimeout(escTimer);
      emit(reader.feed(String(chunk)));
      // A sequence split across chunks waits for its tail — but a bare ESC (the
      // Escape key) looks exactly like such a head, so it fires on a timer.
      if (reader.partial) escTimer = setTimeout(() => emit(reader.flush()), ESC_TIMEOUT_MS);
    };
    // xterm buttons + SGR coordinates: gives the wheel and exact click cells.
    // Kitty keyboard protocol (level 1, progressive): terminals that support it
    // send shift+enter as \x1b[13;2u instead of an ordinary \r.
    stdout?.write("\x1b[?1000h\x1b[?1006h\x1b[>1u");
    internal_eventEmitter.on("input", onData);
    return () => {
      if (escTimer) clearTimeout(escTimer);
      internal_eventEmitter.removeListener("input", onData);
      stdout?.write("\x1b[<1u\x1b[?1006l\x1b[?1000l");
      setRawMode(false);
    };
  }, [internal_eventEmitter, internal_exitOnCtrlC, setRawMode, stdout]);

  return null;
}

// ------------------------------------------------------------------ app

const HELP = [
  "commands:",
  "  /help          this list",
  "  /skills        list the chain skills Lain can use",
  "  /facts         durable facts Lain has learned",
  "  /watches       active background balance watches",
  "  /wishes        the forge wishboard (holder requests → branches)",
  "  /research      topics the scout researches (digests on schedule)",
  "  /pulse         toggle whale transfer notices",
  "  /skin          pick a colour skin with the arrow keys",
  "  /effort        set reply depth (low … max) with the arrow keys",
  "  /cursor        cursor style — block/line, blink/steady",
  "  /clear         clear the screen (conversation memory stays)",
  "  /copy          copy lain's last reply to the clipboard (OSC 52)",
  "  /reset         start a fresh memory room",
  "  /model         switch the chat model (arrows) — or /model claude|codex|opencode",
  "  /exit /quit    leave the wired",
  "",
  "editing: ← → move · home/end (or ctrl+a/ctrl+e) · ctrl+←/→ word",
  "         ⌫ delete back · del delete forward · alt+b/alt+f word",
  "         ctrl+w / alt+⌫ del word · alt+d del word fwd · ctrl+u/ctrl+k kill line",
  "         ↑ ↓ recall history (kept between runs) · type / for autocomplete",
  "         shift+enter (or ctrl+enter) starts a new line — the composer wraps like a chat app",
  "",
  "scrollback: the transcript scrolls inside the app — PgUp/PgDn page,",
  "            ctrl+↑/↓ one line, or just roll the mouse wheel.",
  "            click a ⚙ tool row to expand/collapse it.",
].join("\n");

/** Render the registered skills grouped by category, with descriptions. */
function skillsList(actions: readonly { name: string; description: string }[]): string {
  if (!actions.length) return "no chain skills are registered.";
  const groups: Record<string, { name: string; description: string }[]> = {};
  for (const a of actions) (groups[skillCategory(a.name)] ??= []).push(a);
  const pad = Math.min(22, Math.max(...actions.map((a) => a.name.length)));
  const lines: string[] = [`skills · ${actions.length} chain abilities`, ""];
  for (const cat of SKILL_ORDER) {
    const items = groups[cat];
    if (!items) continue;
    lines.push(cat);
    for (const a of items) lines.push(`  ${a.name.padEnd(pad)}  ${a.description}`);
    lines.push("");
  }
  return lines.join("\n").trimEnd();
}

export function App({ runtime }: { runtime: IAgentRuntime }) {
  const { exit } = useApp();
  const { isRawModeSupported } = useStdin();
  const { stdout } = useStdout();
  const { width, rows } = useStdoutDimensions();

  const character = runtime.character;
  const provider = runtime.model.name;
  const model = runtime.model.modelFor(character.modelTier ?? ModelTier.LARGE);
  const rpc = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
  const block = useChainHeight(rpc);

  const [skin, setSkin] = useState<string>(() => loadSkin());
  const [previewSkin, setPreviewSkin] = useState<string | null>(null);
  const theme = THEMES[previewSkin ?? skin] ?? THEMES[DEFAULT_THEME];
  useEffect(() => {
    saveSkin(skin);
  }, [skin]);

  const [effort, setEffort] = useState<string>(() => loadEffort());
  useEffect(() => {
    runtime.maxTokens = effortTokens(effort);
    saveEffort(effort);
  }, [effort, runtime]);

  const [cursorPref, setCursorPref] = useState<string>(() => loadCursor());
  const cursorStyle: "block" | "line" = cursorPref.startsWith("line") ? "line" : "block";
  const blinkEnabled = cursorPref.endsWith("blink");
  useEffect(() => {
    saveCursor(cursorPref);
  }, [cursorPref]);

  const [room, setRoom] = useState("tui");
  const [history, setHistory] = useState<Turn[]>(() => []);
  const session = useMemo(() => {
    const d = new Date();
    const p = (n: number) => String(n).padStart(2, "0");
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
  }, []);
  const [live, setLive] = useState<Turn | null>(null);
  const [status, setStatus] = useState<"idle" | "thinking" | "streaming">("idle");
  const busy = status !== "idle";
  const thinkingOn = status === "thinking";

  // Terminal/window title (OSC 0) — without it the tab shows the launcher
  // command ("npm lainos"). Tracks activity so the tab shows when she is busy.
  useEffect(() => {
    stdout?.write(`\x1b]0;${busy ? "Lain OS ✦ thinking…" : "Lain OS · the wired"}\x07`);
  }, [busy, stdout]);

  // input line + ui
  const [value, setValue] = useState("");
  const [cursor, setCursor] = useState(0);
  const [acIndex, setAcIndex] = useState(0);
  const [blink, setBlink] = useState(true);
  const [picker, setPicker] = useState<PickerState | null>(null);
  // true while walking history — suppresses the slash menu so ↑/↓ stay on history
  const [browsing, setBrowsing] = useState(false);
  // which tool calls are expanded in the feed (click to toggle)
  const [expandedTools, setExpandedTools] = useState<ReadonlySet<string>>(new Set());

  // input history (shell-style ↑/↓), remembered across runs
  const histRef = useRef<InputHistory | null>(null);
  histRef.current ??= new InputHistory(loadInputHistory());
  const hist = histRef.current;

  const tokens = useMemo(() => estimateTokens(history, live), [history, live]);
  const [pulseOn, setPulseOn] = useState<boolean>(() => loadPulse());
  useEffect(() => {
    savePulse(pulseOn);
  }, [pulseOn]);

  const suggestions = useMemo(() => suggestionsFor(value), [value]);
  const menuItems = browsing ? [] : suggestions;
  const acIdx = menuItems.length ? Math.min(acIndex, menuItems.length - 1) : 0;
  const pushHistory = useCallback((t: Turn) => setHistory((h) => [...h, t]), []);

  // Blink only around actual typing. A permanent 530ms repaint wipes mouse
  // selection in the terminal, making copy/paste impossible; once the keyboard
  // has been idle for a spell the cursor goes solid and repaints stop.
  const [blinkAlive, setBlinkAlive] = useState(true);
  const blinkIdleRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => {
    if (!blinkEnabled || !blinkAlive) {
      setBlink(true);
      return;
    }
    const id = setInterval(() => setBlink((b) => !b), 530);
    return () => clearInterval(id);
  }, [blinkEnabled, blinkAlive]);
  useEffect(() => {
    blinkIdleRef.current = setTimeout(() => setBlinkAlive(false), 10_000);
    return () => {
      if (blinkIdleRef.current) clearTimeout(blinkIdleRef.current);
    };
  }, []);

  // Chain watcher — Lain notices whale transfers and otherwise stays silent.
  useEffect(() => {
    if (!pulseOn) return;
    const pulse = new ChainPulse(rpc, (ev) => {
      pushHistory({ id: nextId(), role: "pulse", parts: [{ kind: "text", text: ev.text }] });
    });
    pulse.start();
    return () => pulse.stop();
  }, [pulseOn, pushHistory, rpc]);

  // Sentinel alerts (background balance watches) surface live in the feed.
  useEffect(() => {
    const sentinel = runtime.getService<SentinelService>("sentinel");
    if (!sentinel?.onAlert) return;
    return sentinel.onAlert((alert) => {
      pushHistory({ id: nextId(), role: "pulse", parts: [{ kind: "text", text: `⚠ ${alert.text}` }] });
    });
  }, [pushHistory, runtime]);

  // Forge progress (wishes being built) appears live too.
  useEffect(() => {
    const forge = runtime.getService<ForgeService>("forge");
    if (!forge?.onEvent) return;
    return forge.onEvent((ev) => {
      pushHistory({ id: nextId(), role: "pulse", parts: [{ kind: "text", text: ev.text }] });
    });
  }, [pushHistory, runtime]);

  // Research digests from the scout land in the feed as well.
  useEffect(() => {
    const scout = runtime.getService<ScoutService>("scout");
    if (!scout?.onEvent) return;
    return scout.onEvent((ev) => {
      pushHistory({ id: nextId(), role: "pulse", parts: [{ kind: "text", text: ev.text }] });
    });
  }, [pushHistory, runtime]);

  const openSkinPicker = useCallback(() => {
    setPicker({
      title: "pick a skin",
      kind: "skin",
      options: THEME_ORDER.map((n) => ({ value: n, label: n })),
      index: Math.max(0, THEME_ORDER.indexOf(skin)),
      onHighlight: (v) => setPreviewSkin(v),
      onPick: (v) => {
        setPreviewSkin(null);
        if (v !== skin) setSkin(v);
      },
      onCancel: () => setPreviewSkin(null),
    });
  }, [skin]);

  const openEffortPicker = useCallback(() => {
    setPicker({
      title: "set effort (reply depth)",
      kind: "plain",
      options: EFFORTS.map((e) => ({ value: e.value, label: e.label, hint: `  ${e.desc} · ~${e.tokens} tok` })),
      index: Math.max(0, EFFORTS.findIndex((e) => e.value === effort)),
      onPick: (v) => setEffort(v),
    });
  }, [effort]);

  const openCursorPicker = useCallback(() => {
    setPicker({
      title: "cursor",
      kind: "plain",
      options: CURSORS.map((cu) => ({ value: cu.value, label: cu.label, hint: `  ${cu.desc}` })),
      index: Math.max(0, CURSORS.findIndex((cu) => cu.value === cursorPref)),
      onPick: (v) => setCursorPref(v),
    });
  }, [cursorPref]);

  // The live chat model is a SwitchableModelProvider, so /model re-routes
  // replies mid-session. The choice is persisted for the next boot.
  const switchable = useMemo(
    () => (runtime.model instanceof SwitchableModelProvider ? runtime.model : undefined),
    [runtime],
  );

  const switchProvider = useCallback(
    (name: string): void => {
      if (!switchable) {
        pushHistory(sysTurn("this session's model provider is fixed — nothing to switch."));
        return;
      }
      const kind = resolveChatProviderKind(name);
      if (!kind) {
        pushHistory(
          sysTurn(`unknown provider "${name}" — try: ${CHAT_PROVIDER_CHOICES.map((p) => p.name).join(" · ")}`),
        );
        return;
      }
      // A missing key or CLI must fail visibly, not drop the chat on a mock.
      const result = switchable.switchTo(kind);
      if (typeof result === "string") {
        pushHistory(sysTurn(result));
        return;
      }
      pushHistory(
        sysTurn(
          `replies now go through ${chatProviderLabel(result.kind)} ${GLYPH.dot} ${result.model}` +
            (result.overridden
              ? `  (env default ${result.envKind}; saved — the daemon adopts it on restart)`
              : "  (back to the env default)"),
        ),
      );
    },
    [pushHistory, switchable],
  );

  const openModelPicker = useCallback(() => {
    const current = switchable?.state();
    setPicker({
      title: current
        ? `chat model — now ${chatProviderLabel(current.kind)} ${GLYPH.dot} ${current.model}`
        : "chat model",
      kind: "plain",
      options: CHAT_PROVIDER_CHOICES.map((p) => ({ value: p.name, label: p.name, hint: `  ${p.desc}` })),
      index: Math.max(
        0,
        CHAT_PROVIDER_CHOICES.findIndex((p) => p.kind === current?.kind),
      ),
      onPick: (v) => switchProvider(v),
    });
  }, [switchable, switchProvider]);

  const command = useCallback(
    (text: string): void => {
      const cmd = text.slice(1).split(/\s+/)[0]?.toLowerCase();
      switch (cmd) {
        case "help":
          pushHistory(sysTurn(HELP));
          break;
        case "clear":
          // The flat feed is rebuilt from history, so a clear is just state.
          setLive(null);
          setHistory([]);
          break;
        case "copy": {
          const lastLain = [...history].reverse().find((t) => t.role === "lain");
          const reply = (lastLain?.parts ?? [])
            .flatMap((p) => (p.kind === "text" ? [p.text] : []))
            .join("\n")
            .trim();
          if (!reply) {
            pushHistory(sysTurn("nothing to copy yet — ask lain something first."));
            break;
          }
          // OSC 52 sets the system clipboard through the terminal itself, so
          // it survives SSH. Terminals cap the payload, so trim huge replies.
          const b64 = Buffer.from(reply.slice(0, 65536), "utf8").toString("base64");
          stdout?.write(`\x1b]52;c;${b64}\x07`);
          pushHistory(
            sysTurn(
              `copied lain's last reply (${reply.length} chars) to the clipboard.` +
                ` if it didn't land, your terminal blocks OSC 52 (tmux needs "set -g set-clipboard on").`,
            ),
          );
          break;
        }
        case "skills":
          pushHistory(sysTurn(skillsList(runtime.actions)));
          break;
        case "facts":
          void runtime.memory.facts(30).then((facts) => {
            pushHistory(
              sysTurn(
                facts.length
                  ? `durable facts (${facts.length}):\n${facts.map((f) => `  · ${f}`).join("\n")}`
                  : "no durable facts yet — say “remember that …” to teach me.",
              ),
            );
          });
          break;
        case "watches": {
          const sentinel = runtime.getService<SentinelService>("sentinel");
          const watches = sentinel?.listWatches() ?? [];
          pushHistory(
            sysTurn(
              watches.length
                ? `active watches (${watches.length}):\n${watches
                    .map(
                      (w) =>
                        `  ${w.id}  ${w.token ? w.token.toUpperCase() : "CYBER"} of ${w.address}` +
                        `  ${w.kind === "change" ? "on change" : `${w.kind} ${w.threshold}`}` +
                        `${w.note ? `  — ${w.note}` : ""}${w.lastValue !== undefined ? `  (last ${w.lastValue})` : ""}`,
                    )
                    .join("\n")}`
                : "no background watches. ask lain: “watch 0x… and warn me below 5 CYBER”.",
            ),
          );
          break;
        }
        case "wishes": {
          const forge = runtime.getService<ForgeService>("forge");
          const wishes = forge?.listWishes() ?? [];
          const fprovider = forge?.forgeProvider();
          const providerLine = fprovider
            ? `forge provider: ${fprovider.selected} (${fprovider.available ? "ready" : "unavailable"})\n`
            : "";
          pushHistory(
            sysTurn(
              wishes.length
                ? `${providerLine}wishboard (${wishes.length}):\n${wishes
                    .map((w) => `  ${w.id} [${w.status}] ${w.title} — ${w.reporter}${w.branch ? `, ${w.branch}` : ""}`)
                    .join("\n")}`
                : `${providerLine}the wishboard is empty — tell lain what you wish for.`,
            ),
          );
          break;
        }
        case "research": {
          const scout = runtime.getService<ScoutService>("scout");
          const topics = scout?.listTopics() ?? [];
          pushHistory(
            sysTurn(
              topics.length
                ? `research topics (${topics.length}):\n${topics
                    .map(
                      (t) =>
                        `  ${t.id} "${t.query}" every ${Math.round(t.intervalMs / 3_600_000)}h` +
                        `${t.note ? ` — ${t.note}` : ""}`,
                    )
                    .join("\n")}`
                : "no research topics — try: “следи за Solana и сообщай только важное”.",
            ),
          );
          break;
        }
        case "pulse": {
          const next = !pulseOn;
          setPulseOn(next);
          pushHistory(
            sysTurn(next ? "pulse on — i'll murmur when the chain moves." : "pulse off — the wired goes quiet."),
          );
          break;
        }
        case "skin":
        case "theme":
          openSkinPicker();
          break;
        case "effort":
          openEffortPicker();
          break;
        case "cursor":
          openCursorPicker();
          break;
        case "reset": {
          const r = `tui-${Date.now().toString(36)}`;
          setRoom(r);
          pushHistory(sysTurn(`new room: ${r} — short-term memory is fresh here.`));
          break;
        }
        case "model": {
          // "/model" opens the picker; "/model codex" switches straight away.
          const arg = text.trim().split(/\s+/)[1];
          if (arg) switchProvider(arg);
          else if (switchable) openModelPicker();
          else pushHistory(sysTurn(`provider ${provider} ${GLYPH.dot} model ${model}`));
          break;
        }
        case "exit":
        case "quit":
          exit();
          break;
        default:
          pushHistory(sysTurn(`unknown command: /${cmd}  (try /help)`));
      }
    },
    [exit, history, model, openCursorPicker, openEffortPicker, openModelPicker, openSkinPicker, provider, pulseOn, pushHistory, runtime, stdout, switchProvider, switchable],
  );

  const send = useCallback(
    async (text: string) => {
      pushHistory({ id: nextId(), role: "you", parts: [{ kind: "text", text }] });
      const acc: Turn = { id: nextId(), role: "lain", parts: [] };
      const flush = () =>
        setLive({
          id: acc.id,
          role: acc.role,
          parts: acc.parts.map((p) =>
            p.kind === "tool" ? { kind: "tool", tool: { ...p.tool } } : { kind: "text", text: p.text },
          ),
        });

      setStatus("thinking");
      flush();
      try {
        const result = await runtime.handleMessageStream({ roomId: room, userId: "user", text }, (ev: AgentEvent) => {
          if (ev.type === "thinking") {
            setStatus("thinking");
          } else if (ev.type === "text") {
            setStatus("streaming");
            const last = acc.parts.at(-1);
            if (last && last.kind === "text") last.text += ev.delta;
            else acc.parts.push({ kind: "text", text: ev.delta });
            flush();
          } else if (ev.type === "tool") {
            acc.parts.push({ kind: "tool", tool: { id: ev.id, name: ev.name, input: ev.input, status: "running" } });
            flush();
          } else if (ev.type === "tool_result") {
            for (const p of acc.parts) {
              if (p.kind === "tool" && p.tool.id === ev.id) {
                p.tool.status = ev.ok ? "ok" : "fail";
                p.tool.summary = ev.summary;
              }
            }
            flush();
          }
        });
        acc.model = result.model;
      } catch (err) {
        acc.parts.push({ kind: "text", text: `⚠ ${(err as Error).message}` });
      } finally {
        pushHistory(acc);
        setLive(null);
        setStatus("idle");
      }
    },
    [pushHistory, room, runtime],
  );

  // ---------------------------------------------------------- layout math

  const sidebarOn = width >= 100;
  const sidebarW = sidebarOn ? 28 : 0;
  const contentW = sidebarOn ? width - sidebarW : width;
  const composerWidth = Math.max(4, contentW - 2);

  const spin = useSpin(busy);
  const feed = useMemo(() => {
    const bn = bannerLines(theme, contentW);
    const lines: Line[] = [...bn];
    const regions: (FeedRegion | undefined)[] = bn.map(() => undefined);
    const push = (ls: Line[], rs: (FeedRegion | undefined)[]) => {
      lines.push(...ls);
      regions.push(...rs);
    };
    const bt = bootLines(theme, contentW);
    push(bt, bt.map(() => undefined));
    for (const t of history) {
      const tl = turnLines(t, expandedTools, theme, contentW, spinnerChar(spin));
      push(tl.lines, tl.regions);
    }
    if (live) {
      const tl = turnLines(live, expandedTools, theme, contentW, spinnerChar(spin));
      push(tl.lines, tl.regions);
    }
    return { lines, regions };
  }, [contentW, expandedTools, history, live, spin, theme]);

  const watches = runtime.getService<SentinelService>("sentinel")?.listWatches()?.length ?? 0;
  const wishes = runtime.getService<ForgeService>("forge")?.listWishes()?.length ?? 0;
  const topics = runtime.getService<ScoutService>("scout")?.listTopics()?.length ?? 0;
  const skills = runtime.actions.length;
  const sidebar = sidebarOn
    ? sidebarLines(theme, sidebarW, rows, {
        provider,
        model,
        block,
        tokens,
        room,
        effort,
        skinLabel: theme.name,
        watches,
        wishes,
        topics,
        skills,
      })
    : { lines: Array.from({ length: rows }, () => blank(sidebarW)), regions: [] as (SidebarRegion | undefined)[] };

  // ---- chrome (status bar + thinking + menus + picker + composer)
  const showHint = value.length === 0 && !busy && !picker;
  const composerWrap = useMemo(() => wrapIndices(value, composerWidth), [value, composerWidth]);
  const cursorPos = useMemo(() => cursorToWrap(value, cursor, composerWidth), [value, cursor, composerWidth]);
  const menu = !picker && menuItems.length ? menuLines(menuItems, acIdx, theme, contentW) : [];
  const pickerRows = picker ? pickerLines(picker, theme, contentW) : [];
  const statusLines = 2;
  const thinkingLines = thinkingOn ? 1 : 0;
  const menuRows = menu.length;
  const pickerRowsN = pickerRows.length;
  const composerRows = picker ? 0 : 1 + Math.max(1, composerWrap.length);
  const hintRows = showHint && !picker ? 1 : 0;
  const chromeRows = statusLines + thinkingLines + menuRows + pickerRowsN + composerRows + hintRows;
  const viewportRows = Math.max(1, rows - chromeRows);
  const composerTop = viewportRows + statusLines + thinkingLines + menuRows + pickerRowsN;

  // ---- scrolling (in-app; the terminal's own scrollback is not used)
  const maxScroll = Math.max(0, feed.lines.length - viewportRows);
  const [scrollTop, setScrollTop] = useState(0);
  const atBottomRef = useRef(true);
  const feedLenRef = useRef(0);
  useEffect(() => {
    const n = history.length + (live ? 1 : 0);
    const newContent = feedLenRef.current !== n;
    if (newContent) {
      feedLenRef.current = n;
      atBottomRef.current = true;
    }
    if (atBottomRef.current) {
      setScrollTop(Math.max(0, feed.lines.length - viewportRows));
    }
  }, [feed.lines.length, viewportRows, history.length, live]);

  // ---- assemble the frame: feed slice + scrollbar, chrome, sidebar merge
  const feedRef = useRef(feed);
  const layoutRef = useRef({
    viewportRows,
    composerTop,
    composerWrap,
    contentW,
    sidebarOn,
    rows,
    feedRegions: [] as (FeedRegion | undefined)[],
    sidebarRegions: [] as (SidebarRegion | undefined)[],
  });

  const scrollBy = useCallback((delta: number) => {
    setScrollTop((prev) => {
      const max = Math.max(0, feedRef.current.lines.length - layoutRef.current.viewportRows);
      const next = clamp(prev + delta, 0, max);
      atBottomRef.current = next >= max;
      return next;
    });
  }, []);

  const slice = feed.lines.slice(scrollTop, scrollTop + viewportRows);
  const sliceRegions = feed.regions.slice(scrollTop, scrollTop + viewportRows);
  const hasScrollbar = feed.lines.length > viewportRows;
  const trackW = hasScrollbar ? contentW - 1 : contentW;
  const screenLines: Line[] = [];
  const feedRegions: (FeedRegion | undefined)[] = [];
  for (let i = 0; i < viewportRows; i++) {
    let l = slice[i] ?? blank(contentW);
    l = truncateLine(l, trackW);
    l = padLine(l, trackW);
    if (hasScrollbar) l = concat(l, [sp(scrollbarChar(i, viewportRows, maxScroll, scrollTop), theme.mutedDim)]);
    screenLines.push(l);
    feedRegions.push(sliceRegions[i]);
  }

  const chrome = chromeLines({
    theme,
    width: contentW,
    status,
    thinkingOn,
    spin,
    provider,
    model,
    block,
    tokens,
    room,
    effort,
    statusLines,
    thinkingLines,
    menuLines: menu,
    pickerLines: pickerRows,
    composerWrap: composerWrap as { text: string; start: number; end: number }[],
    cursorLine: cursorPos.line,
    cursorCol: cursorPos.col,
    blinkOn: blink,
    blinkEnabled,
    cursorStyle,
    busy,
    showHint,
  });

  feedRef.current = feed;
  layoutRef.current = {
    viewportRows,
    composerTop,
    composerWrap,
    contentW,
    sidebarOn,
    rows,
    feedRegions,
    sidebarRegions: sidebar.regions,
  };

  const all: Line[] = [];
  for (let i = 0; i < rows; i++) {
    const l = i < viewportRows ? screenLines[i] : chrome[i - viewportRows];
    if (!l) {
      all.push(blank(width));
      continue;
    }
    all.push(sidebarOn ? concat(fitLine(l, contentW), sidebar.lines[i] ?? blank(sidebarW)) : fitLine(l, width));
  }

  // ------------------------------------------------------------- handlers

  const toggleTool = useCallback((id: string) => {
    setExpandedTools((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);

  const openPickerBy = useCallback(
    (action: "model" | "effort" | "skin") => {
      if (action === "skin") openSkinPicker();
      else if (action === "effort") openEffortPicker();
      else openModelPicker();
    },
    [openEffortPicker, openModelPicker, openSkinPicker],
  );

  const handleMouse = useCallback(
    (m: MouseInfo) => {
      if (picker) return;
      const L = layoutRef.current;
      if (m.wheel) {
        scrollBy(m.wheel === "down" ? 3 : -3);
        return;
      }
      if (m.action !== "press") return;
      const col = m.x - 1;
      const row = m.y - 1;
      if (row < 0 || row >= L.rows || col < 0) return;
      if (L.sidebarOn && col >= L.contentW) {
        const sr = L.sidebarRegions[row];
        if (sr?.kind === "pick") openPickerBy(sr.action);
        return;
      }
      if (col >= L.contentW) return;
      if (row < L.viewportRows) {
        const r = L.feedRegions[row];
        if (r?.kind === "tool") toggleTool(r.toolId);
        return;
      }
      const local = row - L.composerTop;
      const lineIdx = local - 1;
      const wl = L.composerWrap[lineIdx];
      if (wl) {
        const colIn = clamp(col - 2, 0, wl.text.length);
        setCursor(wl.start + colIn);
        setBrowsing(false);
      }
    },
    [openPickerBy, picker, scrollBy, toggleTool],
  );

  const onKey = useCallback(
    (input: string, key: TuiKey) => {
      setBlink(true);
      setBlinkAlive(true);
      if (blinkIdleRef.current) clearTimeout(blinkIdleRef.current);
      blinkIdleRef.current = setTimeout(() => setBlinkAlive(false), 10_000);

      if (key.mouse) {
        handleMouse(key.mouse);
        return;
      }

      // 1) picker captures everything
      if (picker) {
        if (key.upArrow || key.downArrow) {
          const n = picker.options.length;
          const ni = key.upArrow ? (picker.index - 1 + n) % n : (picker.index + 1) % n;
          picker.onHighlight?.(picker.options[ni].value);
          setPicker({ ...picker, index: ni });
        } else if (key.return) {
          picker.onPick(picker.options[picker.index].value);
          setPicker(null);
        } else if (key.escape) {
          picker.onCancel?.();
          setPicker(null);
        }
        return;
      }

      // 2) scroll the feed with the keyboard.
      const page = Math.max(1, layoutRef.current.viewportRows - 2);
      if (key.pageUp) {
        scrollBy(page);
        return;
      }
      if (key.pageDown) {
        scrollBy(-page);
        return;
      }
      if (key.ctrl && key.upArrow) {
        scrollBy(1);
        return;
      }
      if (key.ctrl && key.downArrow) {
        scrollBy(-1);
        return;
      }

      // The slash menu is "open" only while actively typing a command — not when
      // a command was just recalled from history (browsing).
      const items = browsing ? [] : suggestionsFor(value);
      const open = items.length > 0;
      const idx = open ? Math.min(acIndex, items.length - 1) : 0;

      const commit = (text: string) => {
        // Remembered for the next ↑ — and for the next run of the TUI.
        if (hist.push(text)) saveInputHistory(hist.entries);
        setBrowsing(false);
        setValue("");
        setCursor(0);
        setAcIndex(0);
        if (text.startsWith("/")) command(text);
        else if (text) send(text);
      };

      // 3a) menu open → arrows (and Tab) pick a command, Enter runs it
      if (open) {
        if (key.upArrow) {
          setAcIndex((idx - 1 + items.length) % items.length);
          return;
        }
        if (key.downArrow || key.tab) {
          setAcIndex((idx + (key.shift ? items.length - 1 : 1)) % items.length);
          return;
        }
        if (key.return && !key.shift) {
          commit(items[idx].name);
          return;
        }
        // other keys (incl. shift+enter) fall through to editing
      } else {
        // 3b) menu closed → ↑/↓ move a visual line first, then history.
        if (key.upArrow || key.downArrow) {
          if (!browsing) {
            const edited = editLine({ value, cursor }, input, key, composerWidth);
            if (edited) {
              setBrowsing(false);
              setValue(edited.value);
              setCursor(edited.cursor);
              setAcIndex(0);
              return;
            }
          }
          const v = key.upArrow ? hist.prev(value) : hist.next();
          if (v === null) return;
          setBrowsing(true);
          setValue(v);
          setCursor(v.length);
          return;
        }
        if (key.return) {
          if (!key.shift && !busy) {
            commit(value.trim());
            return;
          }
          // shift+enter / ctrl+enter falls through to editLine → new line.
        }
      }

      // 4) line editing — any edit exits history-browsing (re-arms the menu)
      const edited = editLine({ value, cursor }, input, key, composerWidth);
      if (edited) {
        setBrowsing(false);
        setValue(edited.value);
        setCursor(edited.cursor);
        setAcIndex(0);
      }
    },
    [acIndex, browsing, busy, command, composerWidth, cursor, handleMouse, hist, picker, scrollBy, send, value],
  );

  return (
    <Box flexDirection="column">
      {all.map((line, i) => (
        <LineView key={i} line={line} />
      ))}
      {isRawModeSupported ? <InputCapture onKey={onKey} /> : null}
    </Box>
  );
}
