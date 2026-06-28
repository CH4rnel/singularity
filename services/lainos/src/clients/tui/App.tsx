import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { Box, Text, useApp, useInput, useStdin, useStdout, type Key } from "ink";
import Spinner from "ink-spinner";
import { ModelTier, type AgentEvent, type IAgentRuntime } from "../../types.js";
import {
  BANNER,
  CHAIN_ID,
  DEFAULT_THEME,
  GLYPH,
  LAIN_ART,
  lerpColor,
  VERSION,
  loadCursor,
  loadEffort,
  loadSkin,
  saveCursor,
  saveEffort,
  saveSkin,
  THEME_ORDER,
  THEMES,
  type Theme,
} from "./theme.js";
import { editLine } from "./editor.js";

// ------------------------------------------------------------- theme ctx

const ThemeContext = createContext<Theme>(THEMES[DEFAULT_THEME]);
const useTheme = () => useContext(ThemeContext);

// ---------------------------------------------------------------- model

type ToolBlock = {
  id: string;
  name: string;
  input: Record<string, unknown>;
  status: "running" | "ok" | "fail";
  summary?: string;
};
type Part = { kind: "text"; text: string } | { kind: "tool"; tool: ToolBlock };
type Role = "you" | "lain" | "sys" | "banner";
type Turn = { id: string; role: Role; parts: Part[] };

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
  { name: "/skin", desc: "pick a colour skin (arrows)" },
  { name: "/effort", desc: "set reply depth (arrows)" },
  { name: "/cursor", desc: "cursor style + blink (arrows)" },
  { name: "/reset", desc: "fresh memory room" },
  { name: "/model", desc: "show provider + model" },
  { name: "/exit", desc: "leave the wired" },
];

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

function truncate(s: string, n: number): string {
  return s.length > n ? `${s.slice(0, n - 1)}…` : s;
}
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

// ------------------------------------------------------------ components

function GradientText({ text, from, to }: { text: string; from: string; to: string }) {
  const chars = [...text];
  return (
    <Text>
      {chars.map((ch, i) => (
        <Text key={i} color={lerpColor(from, to, chars.length <= 1 ? 0 : i / (chars.length - 1))}>
          {ch}
        </Text>
      ))}
    </Text>
  );
}

function Banner({ tagline }: { tagline: string }) {
  const c = useTheme();
  return (
    <Box flexDirection="column" marginBottom={1}>
      {BANNER.map((row, i) => (
        <Text key={i} color={lerpColor(c.gradFrom, c.gradTo, BANNER.length <= 1 ? 0 : i / (BANNER.length - 1))}>
          {row}
        </Text>
      ))}
      <Box marginTop={1}>
        <Text color={c.mutedDim}>{GLYPH.spark} </Text>
        <GradientText text={tagline} from={c.gradFrom} to={c.gradTo} />
      </Box>
    </Box>
  );
}

function BootCard({
  runtime,
  block,
  session,
}: {
  runtime: IAgentRuntime;
  block: number | null;
  session: string;
}) {
  const c = useTheme();
  const provider = runtime.model.name;
  const model = runtime.model.modelFor(runtime.character.modelTier ?? ModelTier.LARGE);
  const cwd = process.cwd();

  // Bucket the registered skills into stylish categories.
  const groups: Record<string, string[]> = {};
  for (const a of runtime.actions) {
    const cat = /balance|overview|token/.test(a.name)
      ? "wallet"
      : /tx|send|transfer/.test(a.name)
        ? "tx"
        : "chain";
    (groups[cat] ??= []).push(a.name);
  }
  const order = ["chain", "wallet", "tx"].filter((o) => groups[o]);

  return (
    <Box flexDirection="column" marginBottom={1}>
      <Box flexDirection="column" borderStyle="round" borderColor={c.primary} paddingX={2}>
        <Box justifyContent="center">
          <Text color={c.primary} bold>
            Lain OS
          </Text>
          <Text color={c.mutedDim}>
            {"  ·  "}v{VERSION}
            {"  ·  "}Cyberia {CHAIN_ID}
            {"  ·  "}the Wired
          </Text>
        </Box>

        <Box flexDirection="column" alignItems="center" marginTop={1}>
          {LAIN_ART.map((line, i) => (
            <Text key={i} color={lerpColor(c.gradFrom, c.gradTo, LAIN_ART.length <= 1 ? 0 : i / (LAIN_ART.length - 1))}>
              {line}
            </Text>
          ))}
        </Box>

        <Box marginTop={1}>
          <Box flexDirection="column" width={26} marginRight={2}>
            <Box>
              <Text color={c.secondary}>{model}</Text>
            </Box>
            <Text color={c.mutedDim}>{provider}</Text>
            <Text color={c.mutedDim}>{truncate(cwd, 24)}</Text>
            <Text color={c.mutedDim}>
              {GLYPH.chain} {block === null ? "—" : block.toLocaleString("en-US")}
            </Text>
            <Text color={c.mutedDim}>session {session}</Text>
          </Box>

          <Box flexDirection="column" flexGrow={1}>
            <Text color={c.fg} bold>
              SKILLS
            </Text>
            {order.map((cat) => (
              <Box key={cat}>
                <Text color={c.secondary}>{cat.padEnd(8)}</Text>
                <Text color={c.fg}>{groups[cat].join(" · ")}</Text>
              </Box>
            ))}
            <Box marginTop={1}>
              <Text color={c.mutedDim}>
                {runtime.actions.length} chain skills {GLYPH.dot} /help for commands
              </Text>
            </Box>
          </Box>
        </Box>
      </Box>

      <Box flexDirection="column" marginTop={1}>
        <Text color={c.fg}>welcome to the wired. type to speak with Lain, or /help for commands.</Text>
        <Text color={c.mutedDim}>
          {GLYPH.spark} tip: she reads the Cyberia chain live — try “what's the latest block?”
        </Text>
      </Box>
    </Box>
  );
}

function ToolCard({ tool }: { tool: ToolBlock }) {
  const c = useTheme();
  const color = tool.status === "running" ? c.warn : tool.status === "ok" ? c.ok : c.err;
  const args = Object.entries(tool.input)
    .map(([k, v]) => `${k}=${typeof v === "string" ? v : JSON.stringify(v)}`)
    .join("  ");
  return (
    <Box flexDirection="column" marginLeft={2}>
      <Box>
        <Text color={c.mutedDim}>{GLYPH.tool} </Text>
        <Text color={color}>
          {tool.status === "running" ? <Spinner type="dots" /> : tool.status === "ok" ? GLYPH.ok : GLYPH.fail}{" "}
        </Text>
        <Text color={c.secondary}>{tool.name}</Text>
        {args ? <Text color={c.mutedDim}>  {truncate(args, 70)}</Text> : null}
      </Box>
      {tool.summary ? (
        <Box marginLeft={4}>
          <Text color={c.muted}>{truncate(tool.summary, 220)}</Text>
        </Box>
      ) : null}
    </Box>
  );
}

function TurnView({ turn }: { turn: Turn }) {
  const c = useTheme();
  const meta =
    turn.role === "you"
      ? { label: `${GLYPH.you} you`, color: c.secondary }
      : turn.role === "lain"
        ? { label: `${GLYPH.lain} lain`, color: c.primary }
        : { label: `${GLYPH.dot} sys`, color: c.mutedDim };
  return (
    <Box flexDirection="column" marginBottom={1}>
      <Text color={meta.color} bold>
        {meta.label}
      </Text>
      {turn.parts.map((part, i) =>
        part.kind === "tool" ? (
          <ToolCard key={i} tool={part.tool} />
        ) : part.text.trim() ? (
          <Box key={i} marginLeft={2}>
            <Text color={turn.role === "sys" ? c.mutedDim : c.fg}>{part.text.trimEnd()}</Text>
          </Box>
        ) : null,
      )}
    </Box>
  );
}

function Autocomplete({ items, index }: { items: typeof COMMANDS; index: number }) {
  const c = useTheme();
  return (
    <Box flexDirection="column" marginTop={1}>
      {items.map((it, i) => {
        const on = i === index;
        return (
          <Box key={it.name}>
            <Text color={on ? c.primary : c.mutedDim}>{on ? "❯ " : "  "}</Text>
            <Text color={on ? c.primary : c.secondary} bold={on}>
              {it.name.padEnd(10)}
            </Text>
            <Text color={c.mutedDim}>{it.desc}</Text>
          </Box>
        );
      })}
    </Box>
  );
}

function Picker({ state }: { state: PickerState }) {
  const c = useTheme();
  return (
    <Box flexDirection="column" marginTop={1} borderStyle="round" borderColor={c.primary} paddingX={1}>
      <Text>
        <Text color={c.primary} bold>
          {state.title}
        </Text>
        <Text color={c.mutedDim}>{"   ↑↓ move · enter select · esc cancel"}</Text>
      </Text>
      {state.options.map((opt, i) => {
        const on = i === state.index;
        const th = state.kind === "skin" ? THEMES[opt.value] : undefined;
        return (
          <Box key={opt.value}>
            <Text color={on ? c.primary : c.mutedDim}>{on ? " ❯ " : "   "}</Text>
            <Text color={on ? c.primary : c.fg} bold={on}>
              {opt.label.padEnd(11)}
            </Text>
            {th
              ? [th.primary, th.secondary, th.ok, th.warn, th.err].map((col, k) => (
                  <Text key={k} color={col}>
                    {GLYPH.swatch}
                  </Text>
                ))
              : null}
            {th ? <Text color={c.mutedDim}>{"  "}{th.label}</Text> : null}
            {!th && opt.hint ? <Text color={c.mutedDim}>{opt.hint}</Text> : null}
          </Box>
        );
      })}
    </Box>
  );
}

function Composer(props: {
  value: string;
  cursor: number;
  blinkOn: boolean;
  blinkEnabled: boolean;
  cursorStyle: "block" | "line";
  busy: boolean;
}) {
  const c = useTheme();
  const { value, cursor, busy } = props;
  const before = value.slice(0, cursor);
  const at = value.slice(cursor, cursor + 1) || " ";
  const after = value.slice(cursor + 1);
  const show = !props.blinkEnabled || props.blinkOn;
  const cursorCell =
    props.cursorStyle === "line" ? (
      <Text underline={show} color={show ? c.primary : c.fg}>
        {at}
      </Text>
    ) : (
      <Text backgroundColor={show ? c.primary : undefined} color={show ? "#0b0b12" : c.fg}>
        {at}
      </Text>
    );
  return (
    <Box flexDirection="column">
      <Box marginTop={1}>
        <Text color={busy ? c.mutedDim : c.primary}>{GLYPH.you} </Text>
        <Text color={c.fg}>{before}</Text>
        {cursorCell}
        <Text color={c.fg}>{after}</Text>
        {value.length === 0 ? (
          <Text color={c.mutedDim}>  {busy ? "lain is speaking…" : "ask lain…"}</Text>
        ) : null}
      </Box>
      {value.length === 0 && !busy ? (
        <Box marginLeft={2}>
          <Text color={c.mutedDim}>↑↓ history · / commands · Tab complete · ctrl+w·alt+d del word · ctrl+a/e home/end</Text>
        </Box>
      ) : null}
    </Box>
  );
}

/** Single keyboard sink, mounted only when raw mode is available (a real TTY). */
function InputCapture({ onKey }: { onKey: (input: string, key: Key) => void }) {
  useInput((input, key) => onKey(input, key));
  return null;
}

// ------------------------------------------------------------------ app

const HELP = [
  "commands:",
  "  /help          this list",
  "  /skin          pick a colour skin with the arrow keys",
  "  /effort        set reply depth (low … max) with the arrow keys",
  "  /cursor        cursor style — block/line, blink/steady",
  "  /reset         start a fresh memory room",
  "  /model         show the active provider + model",
  "  /exit /quit    leave the wired",
  "",
  "editing: ← → move · ctrl+a/ctrl+e home/end · alt+b/alt+f word",
  "         ctrl+w / alt+⌫ del word · alt+d del word fwd · ctrl+u/ctrl+k kill line",
  "         ↑ ↓ recall history · type / for command autocomplete",
].join("\n");

export function App({ runtime }: { runtime: IAgentRuntime }) {
  const { exit } = useApp();
  const { stdout } = useStdout();
  const { isRawModeSupported } = useStdin();
  const width = stdout?.columns ?? 80;
  const rows = stdout?.rows ?? 24;

  const character = runtime.character;
  const provider = runtime.model.name;
  const model = runtime.model.modelFor(character.modelTier ?? ModelTier.LARGE);
  const tagline = "the wired remembers  ·  autonomous agent of cyberia";
  const block = useChainHeight(process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church");

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

  // input line + ui
  const [value, setValue] = useState("");
  const [cursor, setCursor] = useState(0);
  const [acIndex, setAcIndex] = useState(0);
  const [blink, setBlink] = useState(true);
  const [picker, setPicker] = useState<PickerState | null>(null);
  // true while walking history — suppresses the slash menu so ↑/↓ stay on history
  const [browsing, setBrowsing] = useState(false);

  // input history (shell-style ↑/↓)
  const histRef = useRef<string[]>([]);
  const histIdxRef = useRef<number | null>(null);
  const draftRef = useRef<string>("");

  const tokens = useMemo(() => estimateTokens(history, live), [history, live]);
  const suggestions = useMemo(() => suggestionsFor(value), [value]);
  const menuItems = browsing ? [] : suggestions;
  const acIdx = menuItems.length ? Math.min(acIndex, menuItems.length - 1) : 0;
  const pushHistory = useCallback((t: Turn) => setHistory((h) => [...h, t]), []);

  useEffect(() => {
    const id = setInterval(() => setBlink((b) => !b), 530);
    return () => clearInterval(id);
  }, []);

  const openSkinPicker = useCallback(() => {
    setPicker({
      title: "pick a skin",
      kind: "skin",
      options: THEME_ORDER.map((n) => ({ value: n, label: n })),
      index: Math.max(0, THEME_ORDER.indexOf(skin)),
      onHighlight: (v) => setPreviewSkin(v),
      onPick: (v) => {
        setSkin(v);
        setPreviewSkin(null);
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

  const command = useCallback(
    (text: string): void => {
      const cmd = text.slice(1).split(/\s+/)[0]?.toLowerCase();
      switch (cmd) {
        case "help":
          pushHistory(sysTurn(HELP));
          break;
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
        case "model":
          pushHistory(sysTurn(`provider ${provider} ${GLYPH.dot} model ${model}`));
          break;
        case "exit":
        case "quit":
          exit();
          break;
        default:
          pushHistory(sysTurn(`unknown command: /${cmd}  (try /help)`));
      }
    },
    [exit, model, openCursorPicker, openEffortPicker, openSkinPicker, provider, pushHistory],
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
        await runtime.handleMessageStream({ roomId: room, userId: "user", text }, (ev: AgentEvent) => {
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

  const onKey = useCallback(
    (input: string, key: Key) => {
      setBlink(true);

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

      // The slash menu is "open" only while actively typing a command — not when
      // a command was just recalled from history (browsing).
      const items = browsing ? [] : suggestionsFor(value);
      const open = items.length > 0;
      const idx = open ? Math.min(acIndex, items.length - 1) : 0;

      const commit = (text: string) => {
        const h = histRef.current;
        if (text && h[h.length - 1] !== text) h.push(text);
        histIdxRef.current = null;
        draftRef.current = "";
        setBrowsing(false);
        setValue("");
        setCursor(0);
        setAcIndex(0);
        if (text.startsWith("/")) command(text);
        else if (text) send(text);
      };

      // 2a) menu open → arrows (and Tab) pick a command, Enter runs it
      if (open) {
        if (key.upArrow) {
          setAcIndex((idx - 1 + items.length) % items.length);
          return;
        }
        if (key.downArrow || key.tab) {
          setAcIndex((idx + (key.shift ? items.length - 1 : 1)) % items.length);
          return;
        }
        if (key.return) {
          commit(items[idx].name);
          return;
        }
        // other keys fall through to editing (narrows the menu)
      } else {
        // 2b) menu closed → arrows walk input history, fully replacing the line
        if (key.upArrow) {
          const h = histRef.current;
          if (!h.length) return;
          if (histIdxRef.current === null) {
            draftRef.current = value;
            histIdxRef.current = h.length - 1;
          } else if (histIdxRef.current > 0) {
            histIdxRef.current -= 1;
          }
          const v = h[histIdxRef.current];
          setBrowsing(true);
          setValue(v);
          setCursor(v.length);
          return;
        }
        if (key.downArrow) {
          if (histIdxRef.current === null) return;
          const h = histRef.current;
          if (histIdxRef.current < h.length - 1) histIdxRef.current += 1;
          else histIdxRef.current = null;
          const v = histIdxRef.current === null ? draftRef.current : h[histIdxRef.current];
          setBrowsing(true);
          setValue(v);
          setCursor(v.length);
          return;
        }
        if (key.return) {
          if (!busy) commit(value.trim());
          return;
        }
      }

      // 4) line editing — any edit exits history-browsing (re-arms the menu)
      const edited = editLine({ value, cursor }, input, key);
      if (edited) {
        setBrowsing(false);
        setValue(edited.value);
        setCursor(edited.cursor);
        setAcIndex(0);
      }
    },
    [acIndex, browsing, busy, command, cursor, picker, send, value],
  );

  // Banner + conversation live in the dynamic frame (so they recolour with the
  // skin). The whole app is bounded to the terminal height; the conversation
  // body clips its overflow and pins the newest turns to the bottom.
  const convo = history.slice(-150);
  const showBoot = history.length === 0 && !live;

  return (
    <ThemeContext.Provider value={theme}>
      <Box flexDirection="column" height={rows}>
        <Banner tagline={tagline} />

        {showBoot ? <BootCard runtime={runtime} block={block} session={session} /> : null}

        <Box flexDirection="column" flexGrow={1} overflow="hidden" justifyContent="flex-end">
          {convo.map((turn) => (
            <TurnView key={turn.id} turn={turn} />
          ))}
          {live ? <TurnView turn={live} /> : null}
        </Box>

        {status === "thinking" ? (
          <Box marginLeft={2}>
            <Text color={theme.warn}>
              <Spinner type="dots" />
            </Text>
            <Text color={theme.mutedDim}>  reaching into the wired…</Text>
          </Box>
        ) : null}

        <StatusBar
          provider={provider}
          model={model}
          block={block}
          tokens={tokens}
          room={room}
          effort={effort}
          status={status}
          width={width}
        />

        {picker ? (
          <Picker state={picker} />
        ) : (
          <>
            {menuItems.length ? <Autocomplete items={menuItems} index={acIdx} /> : null}
            <Composer
              value={value}
              cursor={cursor}
              blinkOn={blink}
              blinkEnabled={blinkEnabled}
              cursorStyle={cursorStyle}
              busy={busy}
            />
          </>
        )}

        {isRawModeSupported ? <InputCapture onKey={onKey} /> : null}
      </Box>
    </ThemeContext.Provider>
  );
}

function StatusBar(props: {
  provider: string;
  model: string;
  block: number | null;
  tokens: number;
  room: string;
  effort: string;
  status: "idle" | "thinking" | "streaming";
  width: number;
}) {
  const c = useTheme();
  const dot = props.status === "idle" ? c.ok : c.warn;
  return (
    <Box flexDirection="column" marginTop={1}>
      <Text color={c.mutedDim}>{"─".repeat(Math.max(8, props.width))}</Text>
      <Box>
        <Text color={dot}>● </Text>
        <Text color={c.primary}>{props.provider}</Text>
        <Text color={c.mutedDim}> {GLYPH.dot} </Text>
        <Text color={c.secondary}>{props.model}</Text>
        <Text color={c.mutedDim}>{"   "}{GLYPH.chain} </Text>
        <Text color={c.fg}>{props.block === null ? "—" : props.block.toLocaleString("en-US")}</Text>
        <Text color={c.mutedDim}>{"   ◷ "}</Text>
        <Text color={c.fg}>{fmtTokens(props.tokens)} tok</Text>
        <Text color={c.mutedDim}>{"   effort:"}</Text>
        <Text color={c.secondary}>{props.effort}</Text>
        <Text color={c.mutedDim}>{"   skin:"}</Text>
        <Text color={c.primary}>{c.name}</Text>
        <Text color={c.mutedDim}>{"   room:"}</Text>
        <Text color={c.fg}>{props.room}</Text>
      </Box>
    </Box>
  );
}
