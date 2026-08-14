/**
 * Flatten the transcript into plain lines for the scrollback pager.
 *
 * Completed turns are printed into the terminal's own scrollback by <Static>
 * and are never touched again, so they cannot be scrolled by the app. PageUp
 * therefore renders a *window* over the same turns, re-derived here as flat
 * lines. Pure (no ink, no terminal) so it can be tested headless.
 */
import { GLYPH } from "./theme.js";

export type TranscriptPart =
  | { kind: "text"; text: string }
  | {
      kind: "tool";
      tool: { name: string; status: string; summary?: string; input: Record<string, unknown> };
    };

export interface TranscriptTurn {
  role: string;
  model?: string;
  parts: readonly TranscriptPart[];
}

/** Greedy word wrap onto `width` columns, every line carrying `indent`.
 *  Hard newlines in `text` are kept as line breaks. */
export function wrapText(text: string, width: number, indent = ""): string[] {
  const room = Math.max(8, width - indent.length);
  const out: string[] = [];
  const wrapLine = (line: string) => {
    const rows: string[] = [];
    for (const word of line.split(/\s+/).filter(Boolean)) {
      const last = rows[rows.length - 1];
      if (last !== undefined && last.length + 1 + word.length <= room) {
        rows[rows.length - 1] = `${last} ${word}`;
      } else if (word.length <= room) {
        rows.push(word);
      } else {
        // A single monster token (an address, a base64 blob) is cut to fit.
        for (let i = 0; i < word.length; i += room) rows.push(word.slice(i, i + room));
      }
    }
    if (!rows.length) rows.push("");
    out.push(...rows);
  };
  for (const line of text.split("\n")) wrapLine(line);
  return out.map((l) => indent + l);
}

/** One wrapped display line plus its source range in the flat string. */
export interface WrappedLine {
  text: string;
  /** Flat index of the first character of the line. */
  start: number;
  /** Flat index just past the last character of the line. */
  end: number;
}

/** Word-wrap `value` exactly like {@link wrapText}, tracking flat indices so
 *  a cursor position can be mapped onto the visual layout. Hard newlines in
 *  `value` start a fresh display line, so shift+enter stays visible. */
export function wrapIndices(value: string, width: number): WrappedLine[] {
  const w = Math.max(4, width);
  const out: WrappedLine[] = [];
  let offset = 0;
  for (const logical of value.split("\n")) {
    const words: { text: string; index: number }[] = [];
    const re = /\S+/g;
    let m: RegExpExecArray | null;
    while ((m = re.exec(logical)) !== null) words.push({ text: m[0], index: offset + m.index });
    if (!words.length) {
      out.push({ text: logical, start: offset, end: offset + logical.length });
    } else {
      let lineWords: typeof words = [];
      let lineStart = words[0].index;
      let lineEnd = words[0].index + words[0].text.length;
      let used = 0;
      const flush = () => {
        if (lineWords.length) {
          out.push({ text: lineWords.map((x) => x.text).join(" "), start: lineStart, end: lineEnd });
        }
        lineWords = [];
      };
      for (const word of words) {
        if (word.text.length > w) {
          flush();
          for (let i = 0; i < word.text.length; i += w) {
            out.push({
              text: word.text.slice(i, i + w),
              start: word.index + i,
              end: word.index + Math.min(i + w, word.text.length),
            });
          }
          lineStart = word.index + word.text.length;
          continue;
        }
        const need = lineWords.length ? 1 + word.text.length : word.text.length;
        if (lineWords.length && used + need > w) {
          flush();
          lineStart = word.index;
          used = 0;
        }
        lineWords.push(word);
        used += need;
        lineEnd = word.index + word.text.length;
      }
      flush();
    }
    offset += logical.length + 1;
  }
  if (!out.length) out.push({ text: "", start: 0, end: 0 });
  return out;
}

/** Map a flat cursor index to (line, col) in the wrapped layout. */
export function cursorToWrap(value: string, cursor: number, width: number): { line: number; col: number } {
  const lines = wrapIndices(value, width);
  for (let i = 0; i < lines.length; i++) {
    const { start, end } = lines[i];
    if (cursor <= start) return { line: i, col: 0 };
    if (cursor <= end) return { line: i, col: cursor - start };
    if (i === lines.length - 1) return { line: i, col: lines[i].text.length };
  }
  return { line: 0, col: 0 };
}

/** The header line of a turn: who spoke, and through which model. */
function label(turn: TranscriptTurn): string {
  switch (turn.role) {
    case "you":
      return `${GLYPH.you} you`;
    case "lain":
      return `${GLYPH.lain} lain${turn.model ? ` · ${turn.model}` : ""}`;
    case "pulse":
      return `${GLYPH.spark} wired`;
    default:
      return `${GLYPH.dot} sys`;
  }
}

/**
 * Render the whole transcript as flat lines, wrapped to `width`. Turns are
 * separated by a blank line, mirroring what <Static> printed.
 */
export function transcriptLines(turns: readonly TranscriptTurn[], width: number): string[] {
  const w = Math.max(24, width);
  const out: string[] = [];
  for (const turn of turns) {
    out.push(label(turn));
    for (const part of turn.parts) {
      if (part.kind === "text") {
        if (!part.text.trim()) continue;
        for (const raw of part.text.trimEnd().split("\n")) out.push(...wrapText(raw, w, "  "));
      } else {
        const mark = part.tool.status === "ok" ? GLYPH.ok : part.tool.status === "fail" ? GLYPH.fail : "…";
        const args = Object.entries(part.tool.input)
          .map(([k, v]) => `${k}=${typeof v === "string" ? v : JSON.stringify(v)}`)
          .join("  ");
        out.push(...wrapText(`${GLYPH.tool} ${mark} ${part.tool.name}${args ? `  ${args}` : ""}`, w, "  "));
        if (part.tool.summary) out.push(...wrapText(part.tool.summary, w, "      "));
      }
    }
    out.push("");
  }
  return out;
}
