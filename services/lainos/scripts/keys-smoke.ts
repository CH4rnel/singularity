#!/usr/bin/env -S npx tsx
/**
 * Composer plumbing smoke test, all headless: the escape sequences a terminal
 * sends for home, end, delete and ctrl+←/→ must reach the line editor as the
 * right edits, nothing unknown may ever be typed into the input line, ↑/↓ must
 * walk the input history (which outlives the process), and the scrollback pager
 * must flatten the transcript into lines that fit. Run: npm run keys:smoke
 */
import { mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { KeyReader, parseKey, type TuiKey } from "../src/clients/tui/keys.js";
import { editLine, type LineState } from "../src/clients/tui/editor.js";
import {
  HISTORY_LIMIT,
  InputHistory,
  loadInputHistory,
  saveInputHistory,
} from "../src/clients/tui/history.js";
import { transcriptLines } from "../src/clients/tui/transcript.js";

// A throwaway state dir: the history probe writes a real file.
process.env.LAINOS_DATA_DIR = mkdtempSync(join(tmpdir(), "lainos-keys-"));

const results: [string, boolean][] = [];
const check = (name: string, pass: boolean) => results.push([name, pass]);

/** Does the sequence raise exactly the expected flags (and type nothing)? */
const flags = (seq: string, want: Partial<TuiKey>, input = "") => {
  const { key, input: got } = parseKey(seq);
  const ok =
    got === input &&
    (Object.keys(key) as (keyof TuiKey)[]).every((k) => key[k] === (want[k] ?? false));
  return ok;
};

// ---------------------------------------------------------------- parser

check(
  "home variants        ",
  ["\x1b[H", "\x1bOH", "\x1b[1~", "\x1b[7~"].every((s) => flags(s, { home: true })),
);
check(
  "end variants         ",
  ["\x1b[F", "\x1bOF", "\x1b[4~", "\x1b[8~"].every((s) => flags(s, { end: true })),
);
check("delete key           ", flags("\x1b[3~", { delete: true }));
check("backspace ≠ delete   ", flags("\x7f", { backspace: true }));
check(
  "ctrl+left variants   ",
  ["\x1b[1;5D", "\x1b[5D", "\x1bOd"].every((s) => flags(s, { leftArrow: true, ctrl: true })) &&
    flags("\x1b\x1b[D", { leftArrow: true, meta: true }),
);
check(
  "ctrl+right variants  ",
  ["\x1b[1;5C", "\x1b[5C", "\x1bOc"].every((s) => flags(s, { rightArrow: true, ctrl: true })) &&
    flags("\x1b\x1b[C", { rightArrow: true, meta: true }),
);
check(
  "plain arrows         ",
  flags("\x1b[D", { leftArrow: true }) &&
    flags("\x1b[C", { rightArrow: true }) &&
    flags("\x1b[A", { upArrow: true }) &&
    flags("\x1b[B", { downArrow: true }),
);
check(
  "chords still work    ",
  flags("\x01", { ctrl: true }, "a") &&
    flags("\x05", { ctrl: true }, "e") &&
    flags("\x1bb", { meta: true }, "b") &&
    flags("\x1b\x7f", { backspace: true, meta: true }),
);
check(
  "return/tab/escape    ",
  flags("\r", { return: true }) &&
    flags("\t", { tab: true }) &&
    flags("\x1b", { escape: true, meta: true }),
);
check(
  "typing and paste     ",
  flags("x", {}, "x") && flags("A", { shift: true }, "A") && flags("hello", {}, "hello"),
);
// The old handler stripped the ESC and typed the rest: "[200~" in the line.
check(
  "no stray characters  ",
  ["\x1b[200~", "\x1b[201~", "\x1bOP", "\x1b[15~", "\x1b[2~"].every(
    (s) => parseKey(s).input === "",
  ),
);

// ---------------------------------------------------------------- reader

const reader = new KeyReader();
check("split escape buffers ", reader.feed("\x1b").length === 0 && reader.partial);
check("split escape resumes ", reader.feed("[3~").every((p) => p.key.delete) && !reader.partial);
check(
  "two keys in one chunk",
  (() => {
    const presses = reader.feed("\x1b[1;5D\x1b[3~");
    return presses.length === 2 && presses[0].key.leftArrow && presses[0].key.ctrl && presses[1].key.delete;
  })(),
);
check(
  "lone esc flushes     ",
  (() => {
    reader.feed("\x1b");
    const flushed = reader.flush();
    return flushed.length === 1 && flushed[0].key.escape;
  })(),
);

// ------------------------------------------------------- editor wiring

/** Feed a whole terminal transcript through reader → editor, as the TUI does. */
const drive = (start: string, chunks: string[]): LineState => {
  const r = new KeyReader();
  let state: LineState = { value: start, cursor: start.length };
  for (const chunk of chunks) {
    for (const { input, key } of r.feed(chunk)) {
      const next = editLine(state, input, key);
      if (next) state = next;
    }
  }
  return state;
};

check("home moves to start  ", drive("hello world", ["\x1b[H"]).cursor === 0);
check("end moves to end     ", drive("hello world", ["\x1b[H", "\x1b[F"]).cursor === 11);
check(
  "delete eats forward  ",
  (() => {
    const s = drive("hello", ["\x1b[H", "\x1b[3~"]);
    return s.value === "ello" && s.cursor === 0;
  })(),
);
check(
  "backspace unchanged  ",
  (() => {
    const s = drive("hello", ["\x7f"]);
    return s.value === "hell" && s.cursor === 4;
  })(),
);
check("ctrl+left one word   ", drive("hello world", ["\x1b[1;5D"]).cursor === 6);
check("ctrl+left two words  ", drive("hello world", ["\x1b[1;5D", "\x1b[1;5D"]).cursor === 0);
check("ctrl+right one word  ", drive("hello world", ["\x1b[H", "\x1b[1;5C"]).cursor === 5);
check(
  "home+type inserts    ",
  drive("world", ["\x1b[H", "hey "]).value === "hey world",
);
check(
  "unknown seq is inert ",
  drive("hello", ["\x1b[200~", "\x1bOP"]).value === "hello",
);

// ------------------------------------------------------- input history

const hist = new InputHistory();
hist.push("one");
hist.push("two");
hist.push("three");
check(
  "↑ walks back         ",
  hist.prev("draft") === "three" && hist.prev("") === "two" && hist.prev("") === "one",
);
check("↑ stops at the oldest", hist.prev("") === "one");
check(
  "↓ ends on the draft  ",
  hist.next() === "two" && hist.next() === "three" && hist.next() === "draft" && hist.next() === null,
);
check("↑ on empty history   ", new InputHistory().prev("") === null);
check(
  "no twins in a row    ",
  (() => {
    const h = new InputHistory(["a"]);
    return !h.push("a") && h.push("b") && h.entries.join() === "a,b";
  })(),
);
check(
  "capped at the limit  ",
  (() => {
    const h = new InputHistory();
    for (let i = 0; i < HISTORY_LIMIT + 20; i++) h.push(`m${i}`);
    return h.entries.length === HISTORY_LIMIT && h.entries[0] === "m20";
  })(),
);
check(
  "survives a restart   ",
  (() => {
    saveInputHistory(hist.entries);
    const reloaded = new InputHistory(loadInputHistory());
    return reloaded.entries.join() === "one,two,three" && reloaded.prev("") === "three";
  })(),
);

// --------------------------------------------------- transcript pager

const turns = [
  { role: "you", parts: [{ kind: "text" as const, text: "first question" }] },
  {
    role: "lain",
    model: "stub",
    parts: [
      { kind: "text" as const, text: "word ".repeat(40).trim() },
      {
        kind: "tool" as const,
        tool: { name: "get_balance", status: "ok", summary: "12 CYBER", input: { address: "0xbeef" } },
      },
    ],
  },
];
const flat = transcriptLines(turns, 40);
check(
  "labels every speaker ",
  flat[0] === "▸ you" && flat.some((l) => l.startsWith("◆ lain · stub")),
);
check("keeps the turn order ", flat.indexOf("  first question") < flat.findIndex((l) => l.startsWith("◆ lain")));
check("wraps to the width   ", flat.every((l) => [...l].length <= 40) && flat.length > 8);
check("renders tool calls   ", flat.some((l) => l.includes("get_balance") && l.includes("address=0xbeef")));

let ok = true;
for (const [name, pass] of results) {
  console.log(`${name}: ${pass ? "PASS" : "FAIL"}`);
  ok &&= pass;
}
console.log(ok ? "KEYS PROBE OK" : "KEYS PROBE FAILED");
process.exit(ok ? 0 : 1);
