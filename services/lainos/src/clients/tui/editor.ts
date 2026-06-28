/** A tiny readline-style line editor: pure transforms over {value, cursor}. */
import type { Key } from "ink";

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
 * Supports: ← →, ctrl+a/ctrl+e (home/end), alt+b/alt+f (word move),
 * backspace, ctrl+w / alt+⌫ (delete word back), alt+d (delete word fwd),
 * ctrl+u (kill to start), ctrl+k (kill to end), and printable insert (incl. paste).
 */
export function editLine(s: LineState, input: string, key: Key): LineState | null {
  const { value, cursor } = s;

  // --- movement ---
  if (key.leftArrow) return { value, cursor: Math.max(0, cursor - 1) };
  if (key.rightArrow) return { value, cursor: Math.min(value.length, cursor + 1) };
  if (key.ctrl && input === "a") return { value, cursor: 0 };
  if (key.ctrl && input === "e") return { value, cursor: value.length };
  if (key.meta && input === "b") return { value, cursor: wordLeft(value, cursor) };
  if (key.meta && input === "f") return { value, cursor: wordRight(value, cursor) };

  // --- deletion ---
  if (key.meta && (key.backspace || key.delete)) {
    const w = wordLeft(value, cursor);
    return { value: value.slice(0, w) + value.slice(cursor), cursor: w };
  }
  if (key.ctrl && input === "w") {
    const w = wordLeft(value, cursor);
    return { value: value.slice(0, w) + value.slice(cursor), cursor: w };
  }
  if (key.meta && input === "d") {
    const e = wordRight(value, cursor);
    return { value: value.slice(0, cursor) + value.slice(e), cursor };
  }
  if (key.ctrl && input === "u") return { value: value.slice(cursor), cursor: 0 };
  if (key.ctrl && input === "k") return { value: value.slice(0, cursor), cursor };
  if (key.backspace || key.delete) {
    if (cursor > 0) return { value: value.slice(0, cursor - 1) + value.slice(cursor), cursor: cursor - 1 };
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
