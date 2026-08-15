/** A tiny readline-style line editor: pure transforms over {value, cursor}.
 *
 * The composer renders the value soft-wrapped across several rows. When a
 * `width` is given, ↑/↓ walk the visual lines and home/end jump to the start/
 * end of the visual line (ctrl+a/ctrl+e stay buffer-wide, readline style).
 * Without a `width` the whole buffer is one line, so every transform collapses
 * to the classic single-line behaviour the smoke tests pin down. */
import type { TuiKey } from "./keys.js";
import { cursorToWrap, wrapIndices } from "./transcript.js";

export interface LineState {
  value: string;
  cursor: number;
}

/** Index of the start of the word at/just before `cursor`. */
export function wordLeft(value: string, cursor: number): number {
  let i = cursor;
  while (i > 0 && /\s/.test(value[i - 1])) i--;
  while (i > 0 && /\S/.test(value[i - 1])) i--;
  return i;
}

/** Index just past the word at/after `cursor`. */
export function wordRight(value: string, cursor: number): number {
  const n = value.length;
  let i = cursor;
  while (i < n && /\s/.test(value[i])) i++;
  while (i < n && /\S/.test(value[i])) i++;
  return i;
}

/**
 * Apply one keypress to the line. Returns the new state, or null if the key is
 * not an editing key (so the caller can treat it as submit/navigation/etc).
 *
 * Supports: ← →, home/end (visual line when `width` is given), ctrl+← → and
 * alt+b/alt+f (word move), ↑↓ (visual line walk, or null at the first/last
 * line so the caller can fall back to input history), backspace, delete
 * (forward), ctrl+w / alt+⌫ (delete word back), alt+d / alt+del (delete word
 * fwd), ctrl+u (kill to start), ctrl+k (kill to end), shift+enter (insert a
 * literal newline), and printable insert (incl. paste).
 */
export function editLine(s: LineState, input: string, key: TuiKey, width?: number): LineState | null {
  const { value, cursor } = s;
  const byWord = key.ctrl || key.meta;

  // shift+enter inserts a newline inside the multi-line composer.
  if (key.return && key.shift) {
    return { value: value.slice(0, cursor) + "\n" + value.slice(cursor), cursor: cursor + 1 };
  }

  // --- movement ---
  if (key.ctrl && input === "a") return { value, cursor: 0 };
  if (key.ctrl && input === "e") return { value, cursor: value.length };
  if (key.home) {
    if (width !== undefined && width > 0) {
      const at = cursorToWrap(value, cursor, width);
      const lines = wrapIndices(value, width);
      const line = lines[at.line];
      return { value, cursor: line?.start ?? 0 };
    }
    return { value, cursor: 0 };
  }
  if (key.end) {
    if (width !== undefined && width > 0) {
      const at = cursorToWrap(value, cursor, width);
      const lines = wrapIndices(value, width);
      const line = lines[at.line];
      return { value, cursor: line?.end ?? value.length };
    }
    return { value, cursor: value.length };
  }
  if (key.leftArrow) {
    return { value, cursor: byWord ? wordLeft(value, cursor) : Math.max(0, cursor - 1) };
  }
  if (key.rightArrow) {
    return { value, cursor: byWord ? wordRight(value, cursor) : Math.min(value.length, cursor + 1) };
  }
  if (key.meta && input === "b") return { value, cursor: wordLeft(value, cursor) };
  if (key.meta && input === "f") return { value, cursor: wordRight(value, cursor) };

  // ↑↓ walk the visual lines when the composer is multi-line; on the first/
  // last line return null so the caller falls back to input history.
  if ((key.upArrow || key.downArrow) && width !== undefined && width > 0) {
    const lines = wrapIndices(value, width);
    if (lines.length > 1) {
      const at = cursorToWrap(value, cursor, width);
      const target = key.upArrow ? at.line - 1 : at.line + 1;
      if (target < 0 || target >= lines.length) return null;
      const line = lines[target];
      return { value, cursor: Math.min(line.start + at.col, line.end) };
    }
    return null;
  }

  // --- deletion ---
  if (key.meta && key.backspace) {
    const w = wordLeft(value, cursor);
    return { value: value.slice(0, w) + value.slice(cursor), cursor: w };
  }
  if (key.ctrl && input === "w") {
    const w = wordLeft(value, cursor);
    return { value: value.slice(0, w) + value.slice(cursor), cursor: w };
  }
  if ((key.meta && input === "d") || (byWord && key.delete)) {
    const e = wordRight(value, cursor);
    return { value: value.slice(0, cursor) + value.slice(e), cursor };
  }
  if (key.ctrl && input === "u") return { value: value.slice(cursor), cursor: 0 };
  if (key.ctrl && input === "k") return { value: value.slice(0, cursor), cursor };
  // Delete eats the character under the cursor, backspace the one behind it.
  if (key.delete) return { value: value.slice(0, cursor) + value.slice(cursor + 1), cursor };
  if (key.backspace) {
    if (cursor > 0) {
      // A newline is a single cell in the flat string; delete it like a char.
      return { value: value.slice(0, cursor - 1) + value.slice(cursor), cursor: cursor - 1 };
    }
    return { value, cursor };
  }

  // --- printable insert (also handles pasted chunks) ---
  if (
    input &&
    !key.ctrl &&
    !key.meta &&
    !key.return &&
    !key.tab &&
    !key.escape &&
    !key.upArrow &&
    !key.downArrow
  ) {
    return { value: value.slice(0, cursor) + input + value.slice(cursor), cursor: cursor + input.length };
  }

  return null;
}
