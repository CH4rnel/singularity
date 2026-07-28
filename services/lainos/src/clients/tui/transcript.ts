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

/** Greedy word wrap onto `width` columns, every line carrying `indent`. */
export function wrapText(text: string, width: number, indent = ""): string[] {
  const room = Math.max(8, width - indent.length);
  const out: string[] = [];
  for (const word of text.split(/\s+/).filter(Boolean)) {
    const last = out[out.length - 1];
    if (last !== undefined && last.length + 1 + word.length <= room) {
      out[out.length - 1] = `${last} ${word}`;
    } else if (word.length <= room) {
      out.push(word);
    } else {
      // A single monster token (an address, a base64 blob) is cut to fit.
      for (let i = 0; i < word.length; i += room) out.push(word.slice(i, i + room));
    }
  }
  if (!out.length) out.push("");
  return out.map((l) => indent + l);
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
