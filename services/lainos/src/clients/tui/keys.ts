/**
 * Raw key-sequence parser for the TUI composer.
 *
 * ink's `useInput` throws the escape sequence away before we can see it: Home,
 * End and the real Delete key all reach the handler as an empty `input`, and
 * ink's `key.delete` is set by plain Backspace (\x7f) too — so those keys were
 * either dead or wrong in the input line. The composer therefore parses the raw
 * stdin chunks itself and hands the editor a {@link TuiKey}: ink's `Key` plus
 * `home`/`end`, with `backspace` and `delete` finally meaning two different
 * keys, and with modifiers (ctrl+←/→) preserved.
 *
 * Only `type Key` is imported here — this module stays pure so it can be tested
 * without a terminal (`npm run keys:smoke`).
 */
import type { Key } from "ink";

/** ink's `Key` plus the keys ink cannot report. */
export interface TuiKey extends Key {
  /** Home (\x1b[H, \x1bOH, \x1b[1~, \x1b[7~). */
  home: boolean;
  /** End (\x1b[F, \x1bOF, \x1b[4~, \x1b[8~). */
  end: boolean;
}

export interface KeyPress {
  /** Printable text (or the letter of a ctrl/alt chord); "" for named keys. */
  input: string;
  key: TuiKey;
}

/** A bare ESC is indistinguishable from the head of a longer sequence, so the
 *  reader waits this long for the tail before calling it the Escape key. */
export const ESC_TIMEOUT_MS = 30;

type Flags = { -readonly [K in keyof TuiKey]: boolean };

const BLANK: TuiKey = {
  upArrow: false,
  downArrow: false,
  leftArrow: false,
  rightArrow: false,
  pageUp: false,
  pageDown: false,
  return: false,
  escape: false,
  ctrl: false,
  shift: false,
  tab: false,
  backspace: false,
  delete: false,
  meta: false,
  home: false,
  end: false,
};

const mk = (over: Partial<TuiKey> = {}): TuiKey => ({ ...BLANK, ...over });
const named = (over: Partial<TuiKey>): KeyPress => ({ input: "", key: mk(over) });
/** An unrecognised escape sequence: consumed, but never typed into the line. */
const ignored = (): KeyPress => ({ input: "", key: mk() });

/** Key names → the `TuiKey` flag they raise. Names with no flag are ignored. */
const FLAG: Record<string, keyof TuiKey> = {
  up: "upArrow",
  down: "downArrow",
  left: "leftArrow",
  right: "rightArrow",
  home: "home",
  end: "end",
  pageup: "pageUp",
  pagedown: "pageDown",
  delete: "delete",
};

/** Final letter of a CSI/SS3 sequence → key name (xterm, vt, rxvt). */
const LETTER: Record<string, string> = {
  A: "up",
  B: "down",
  C: "right",
  D: "left",
  E: "clear",
  F: "end",
  H: "home",
  a: "up",
  b: "down",
  c: "right",
  d: "left",
  e: "clear",
};

/** `\x1b[<n>~` numbers → key name (vt220 and the rxvt ^/$ modifier variants). */
const TILDE: Record<string, string> = {
  "1": "home",
  "2": "insert",
  "3": "delete",
  "4": "end",
  "5": "pageup",
  "6": "pagedown",
  "7": "home",
  "8": "end",
};

const CSI_RE = /^\x1b(\[|O)(?:(\d+)(?:;(\d+))?)?([~^$]|[A-Za-z])$/;

/** Decode a CSI/SS3 sequence, or null if it is not one we know. */
function parseCsi(seq: string): KeyPress | null {
  // alt/meta arrives as an extra leading ESC (\x1b\x1b[D = alt+left).
  let s = seq;
  let meta = false;
  while (s.startsWith("\x1b\x1b")) {
    s = s.slice(1);
    meta = true;
  }
  const m = CSI_RE.exec(s);
  if (!m) return null;
  const [, intro, num, mod, final] = m;

  let name: string | undefined;
  // Modifier bitmask, xterm style: 1 shift, 2 alt, 4 ctrl, 8 meta.
  let bits: number;
  if (final === "~" || final === "^" || final === "$") {
    name = TILDE[num ?? ""];
    bits = Number(mod ?? 1) - 1;
    if (final === "^") bits |= 4; // rxvt: \x1b[3^ = ctrl+delete
    if (final === "$") bits |= 1; // rxvt: \x1b[3$ = shift+delete
  } else {
    name = LETTER[final];
    // xterm sends \x1b[1;5D for ctrl+left; some terminals drop the "1;" and
    // send \x1b[5D, so a lone number in front of a letter is the modifier.
    bits = Number(mod ?? num ?? 1) - 1;
    if (/[a-z]/.test(final)) bits |= intro === "O" ? 4 : 1; // rxvt Od / [d
  }
  if (!name) return ignored();

  const key: Flags = {
    ...BLANK,
    ctrl: !!(bits & 4),
    shift: !!(bits & 1),
    meta: meta || !!(bits & 10),
  };
  const flag = FLAG[name];
  if (!flag) return { input: "", key }; // insert, clear — nothing to type
  key[flag] = true;
  return { input: "", key };
}

/**
 * Turn one key sequence (or one pasted chunk) into a keypress. Sequences we do
 * not recognise are swallowed rather than typed, so an exotic terminal never
 * sprays `[3;5~` into the input line.
 */
export function parseKey(seq: string): KeyPress {
  if (seq === "\r" || seq === "\n") return named({ return: true });
  if (seq === "\t") return named({ tab: true });
  if (seq === "\x1b[Z") return named({ tab: true, shift: true });
  if (seq === "\x7f" || seq === "\b") return named({ backspace: true });
  if (seq === "\x1b\x7f" || seq === "\x1b\b") return named({ backspace: true, meta: true });
  // ink reports Escape with meta set as well; the picker relies on `escape`.
  if (seq === "\x1b" || seq === "\x1b\x1b") return named({ escape: true, meta: true });

  const csi = parseCsi(seq);
  if (csi) return csi;

  // alt/meta + character: alt+b, alt+f, alt+d …
  if (/^\x1b[^\x1b[O]$/.test(seq)) {
    const ch = seq[1];
    return { input: ch, key: mk({ meta: true, shift: /[A-Z]/.test(ch) }) };
  }
  // Anything else that still starts with ESC is an unknown sequence.
  if (seq.startsWith("\x1b")) return ignored();

  // ctrl + letter (\x01…\x1a) — reported the way ink does, as the bare letter.
  if (seq.length === 1 && seq >= "\x01" && seq <= "\x1a") {
    return { input: String.fromCharCode(seq.charCodeAt(0) + 96), key: mk({ ctrl: true }) };
  }
  if (seq.length === 1 && seq < " ") return ignored(); // other control bytes

  // Printable text: a single character, or a whole pasted chunk.
  return { input: seq, key: mk({ shift: seq.length === 1 && /[A-Z]/.test(seq) }) };
}

/**
 * Take the escape sequence starting at `i`, or null when the chunk ends before
 * the sequence does (the tail is still in flight on a slow link).
 */
function escapeAt(data: string, i: number): string | null {
  let j = i;
  while (data[j] === "\x1b") j++; // \x1b\x1b… = alt prefix
  if (j >= data.length) return null; // a lone ESC: the key, or a prefix
  if (data[j] === "[") {
    j++;
    while (j < data.length && /[\d;?]/.test(data[j])) j++;
    return j < data.length ? data.slice(i, j + 1) : null;
  }
  if (data[j] === "O") return j + 1 < data.length ? data.slice(i, j + 2) : null;
  return data.slice(i, j + 1); // ESC + one character
}

/**
 * Stateful stdin → keypress reader. A multi-byte sequence normally arrives in
 * one chunk, but over ssh it can be split; the tail is kept in `partial` until
 * the next chunk (or until {@link flush} gives up on it).
 */
export class KeyReader {
  private buffer = "";

  /** True while an unfinished escape sequence is being held back. */
  get partial(): boolean {
    return this.buffer.length > 0;
  }

  feed(chunk: string): KeyPress[] {
    const data = this.buffer + chunk;
    this.buffer = "";
    const out: KeyPress[] = [];
    let i = 0;
    while (i < data.length) {
      if (data[i] !== "\x1b") {
        // A run of plain bytes is one keypress — that is also how a paste of
        // several characters arrives.
        let j = data.indexOf("\x1b", i);
        if (j === -1) j = data.length;
        out.push(parseKey(data.slice(i, j)));
        i = j;
        continue;
      }
      const seq = escapeAt(data, i);
      if (seq === null) {
        this.buffer = data.slice(i);
        break;
      }
      out.push(parseKey(seq));
      i += seq.length;
    }
    return out;
  }

  /** Emit whatever is held back — a lone ESC is the Escape key after all. */
  flush(): KeyPress[] {
    const rest = this.buffer;
    this.buffer = "";
    return rest ? [parseKey(rest)] : [];
  }
}
