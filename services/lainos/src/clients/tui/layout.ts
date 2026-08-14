/**
 * Screen layout: the transcript feed (banner, boot line, turns, tools) and the
 * right-hand sidebar, both flattened into the same styled-line model the whole
 * TUI renders from (see markdown.ts). Pure — no ink, no React — so the app can
 * measure heights exactly, slice the scrollable feed, and map mouse clicks to
 * the exact tool / sidebar row they land on.
 */
import { THEMES, BANNER, CHAIN_ID, GLYPH, VERSION, lerpColor, type Theme } from "./theme.js";
import {
  blank,
  concat,
  fitLine,
  mdToLines,
  sp,
  stableMarkdown,
  truncateLine,
  wrapSpans,
  type Line,
  type Span,
} from "./markdown.js";

// ------------------------------------------------------------------ turns

export interface ToolBlock {
  id: string;
  name: string;
  input: Record<string, unknown>;
  status: "running" | "ok" | "fail";
  summary?: string;
}
export type Part = { kind: "text"; text: string } | { kind: "tool"; tool: ToolBlock };
export type Role = "you" | "lain" | "sys" | "pulse";
export type Turn = { id: string; role: Role; parts: Part[]; model?: string };

/** The tail of a line that carries a click action. */
export type FeedRegion = { kind: "tool"; toolId: string };

const SPIN = ["⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏"];
export const spinnerChar = (frame: number): string => SPIN[frame % SPIN.length];
// ---------------------------------------------------------------- banner

export function bannerLines(theme: Theme, width: number): Line[] {
  const c = theme;
  const wide = width >= BANNER[0].length;
  const out: Line[] = [];
  if (wide) {
    BANNER.forEach((row, i) => {
      out.push([{ t: row, c: lerpColor(c.gradFrom, c.gradTo, BANNER.length <= 1 ? 0 : i / (BANNER.length - 1)) }]);
    });
  } else {
    out.push([
      ..."LAIN OS".split("").map((ch, i) => ({
        t: ch,
        c: lerpColor(c.gradFrom, c.gradTo, 6 <= 1 ? 0 : i / 5),
      })),
    ]);
  }
  const tagline = "the wired remembers  ·  autonomous agent of cyberia";
  out.push(concat([sp(`${GLYPH.spark} `, c.mutedDim)], ...wrapSpans([{ t: tagline, c: c.gradFrom }], width)));
  out.push(blank(width));
  return out;
}

export function bootLines(theme: Theme, width: number): Line[] {
  const c = theme;
  const line1 = wrapSpans([{ t: "welcome to the wired. type to speak with Lain, or /help for commands.", c: c.fg }], width);
  const line2 = wrapSpans(
    [{ t: `${GLYPH.spark} tip: she reads the Cyberia chain live — try “what's the latest block?”`, c: c.mutedDim }],
    width,
  );
  return [...line1, ...line2, blank(width)];
}

// ------------------------------------------------------------------ turns

function turnHeader(turn: Turn, theme: Theme): Line {
  const c = theme;
  const meta =
    turn.role === "you"
      ? { label: `${GLYPH.you} you`, color: c.secondary }
      : turn.role === "lain"
        ? { label: `${GLYPH.lain} lain`, color: c.primary }
        : turn.role === "pulse"
          ? { label: `${GLYPH.spark} wired`, color: c.muted }
          : { label: `${GLYPH.dot} sys`, color: c.mutedDim };
  const head: Line = [{ t: meta.label, c: meta.color, b: true }];
  if (turn.role === "lain" && turn.model) head.push(sp(` · ${turn.model}`, c.mutedDim));
  return head;
}

function fmtArgs(input: Record<string, unknown>): string {
  return Object.entries(input)
    .map(([k, v]) => `${k}=${typeof v === "string" ? v : JSON.stringify(v)}`)
    .join("  ");
}

/** One tool call, collapsed to a single line or expanded with its I/O. */
function toolLines(tool: ToolBlock, theme: Theme, width: number, spin: string): { lines: Line[]; region: FeedRegion } {
  const c = theme;
  const statusColor = tool.status === "running" ? c.warn : tool.status === "ok" ? c.ok : c.err;
  const statusGlyph = tool.status === "running" ? spin : tool.status === "ok" ? GLYPH.ok : GLYPH.fail;
  const marker = GLYPH.expand;
  const args = fmtArgs(tool.input);
  const header: Line = concat(
    [sp(`${marker} `, c.mutedDim)],
    [sp(`${statusGlyph} `, statusColor)],
    [sp(tool.name, c.secondary)],
    args ? [sp(`  ${args}`, c.mutedDim)] : [],
  );
  const lines: Line[] = [truncateLine(header, width)];
  const region: FeedRegion = { kind: "tool", toolId: tool.id };
  return { lines, region };
}

/** Expand a tool into its full input/result view. */
function expandedToolLines(tool: ToolBlock, theme: Theme, width: number): { header: Line; details: Line[] } {
  const c = theme;
  const statusColor = tool.status === "running" ? c.warn : tool.status === "ok" ? c.ok : c.err;
  const statusGlyph = tool.status === "running" ? GLYPH.dots : tool.status === "ok" ? GLYPH.ok : GLYPH.fail;
  const args = fmtArgs(tool.input);
  const header: Line = concat(
    [sp(`${GLYPH.collapse} `, c.mutedDim)],
    [sp(`${statusGlyph} `, statusColor)],
    [sp(tool.name, c.secondary)],
    args ? [sp(`  ${args}`, c.mutedDim)] : [],
  );
  const details: Line[] = [];
  const indent = 2;
  const pushLabel = (label: string) => details.push(concat(blank(indent), [sp(`─ ${label} ─`, c.mutedDim)]));
  const pushBody = (text: string, color: string) => {
    for (const l of wrapSpans([{ t: text, c: color }], width - indent - 2)) {
      details.push(concat(blank(indent + 2), l));
    }
  };
  if (args) {
    pushLabel("input");
    pushBody(args, c.fg);
  }
  if (tool.summary) {
    pushLabel("result");
    pushBody(tool.summary, tool.status === "ok" ? c.ok : c.fg);
  }
  return { header, details };
}

/** Flat text body for you/sys/pulse turns (plain, wrapped). */
function plainBody(text: string, theme: Theme, width: number, color: string): Line[] {
  return wrapSpans([{ t: text, c: color }], width);
}

/**
 * Render one turn into lines, tagging every line that belongs to a tool so a
 * click can expand/collapse it. `expanded` holds the tool ids shown in full.
 */
export function turnLines(
  turn: Turn,
  expanded: ReadonlySet<string>,
  theme: Theme,
  width: number,
  spin: string,
): { lines: Line[]; regions: (FeedRegion | undefined)[] } {
  const c = theme;
  const lines: Line[] = [turnHeader(turn, theme)];
  const regions: (FeedRegion | undefined)[] = [undefined];
  for (const part of turn.parts) {
    if (part.kind === "tool") {
      const block = part.tool;
      if (expanded.has(block.id)) {
        const tool = expandedToolLines(block, theme, width);
        // The expanded header keeps the tool region; the detail rows don't.
        lines.push(truncateLine(tool.header, width));
        regions.push({ kind: "tool", toolId: block.id });
        lines.push(...tool.details);
        regions.push(...tool.details.map(() => undefined));
      } else {
        const tool = toolLines(block, theme, width, spin);
        lines.push(...tool.lines);
        regions.push(tool.region);
      }
    } else if (part.text.trim()) {
      const body =
        turn.role === "lain"
          ? stableMarkdown(part.text)
            ? mdToLines(part.text.trimEnd(), theme, width)
            : wrapSpans([{ t: part.text, c: c.fg }], width)
          : plainBody(part.text.trimEnd(), theme, width, turn.role === "pulse" ? c.muted : turn.role === "sys" ? c.mutedDim : c.fg);
      lines.push(...body);
      regions.push(...body.map(() => undefined));
    }
  }
  return { lines, regions };
}

// --------------------------------------------------------------- sidebar

export type SidebarRegion = { kind: "pick"; action: "model" | "effort" | "skin" };

export interface SidebarData {
  provider: string;
  model: string;
  block: number | null;
  tokens: number;
  room: string;
  effort: string;
  skinLabel: string;
  watches: number;
  wishes: number;
  topics: number;
  skills: number;
}

/** The right sidebar as exactly `rows` lines (clickable rows carry regions). */
export function sidebarLines(
  theme: Theme,
  sidebarW: number,
  rows: number,
  data: SidebarData,
): { lines: Line[]; regions: (SidebarRegion | undefined)[] } {
  const c = theme;
  const inner = Math.max(4, sidebarW - 2);
  const box = (l: Line): Line => concat([sp("│", c.border)], fitLine(l, inner), [sp("│", c.border)]);
  const pickRow = (label: string, value: string, valColor: string, action: SidebarRegion["action"]): { line: Line; region?: SidebarRegion } => ({
    line: box(concat([sp(`${label} `, c.mutedDim)], [sp(value, valColor)])),
    region: { kind: "pick", action },
  });
  const infoRow = (label: string, value: string): { line: Line; region?: SidebarRegion } => ({
    line: box(concat([sp(`${label} `, c.mutedDim)], [sp(value, c.fg)])),
  });
  const gap: { line: Line; region?: SidebarRegion } = { line: box(blank(inner)) };
  const hintRow = (label: string, value: string): { line: Line; region?: SidebarRegion } => ({
    line: box(concat([sp(`${label} `, c.mutedDim)], [sp(value, c.muted)])),
  });

  const rowsList: { line: Line; region?: SidebarRegion }[] = [
    { line: [{ t: `╭─ session ${"─".repeat(Math.max(0, inner - 10))}╮`, c: c.border }] },
    pickRow("model", data.provider, c.primary, "model"),
    pickRow("effort", data.effort, c.secondary, "effort"),
    pickRow("skin", data.skinLabel, c.primary, "skin"),
    gap,
    infoRow("chain", `cyberia ${CHAIN_ID}`),
    infoRow("height", data.block === null ? "—" : data.block.toLocaleString("en-US")),
    infoRow("room", data.room),
    infoRow("tokens", data.tokens >= 1000 ? `${(data.tokens / 1000).toFixed(1)}k` : String(data.tokens)),
    gap,
    infoRow("watches", String(data.watches)),
    infoRow("wishes", String(data.wishes)),
    infoRow("topics", String(data.topics)),
    infoRow("skills", String(data.skills)),
    gap,
    hintRow("wheel", "scroll · pgup page"),
    hintRow("click", "tool to expand"),
    hintRow("x", "expand last tool"),
    hintRow("alt↑↓", "input history"),
  ];

  // Fill to `rows`, pushing the bottom border to the last screen row.
  const padGaps = Math.max(0, rows - rowsList.length - 1);
  const head = rowsList[0];
  const tail = rowsList.slice(1);
  const spaced: typeof rowsList = [];
  for (let i = 0; i < padGaps; i++) spaced.push(gap);
  const all = [head, ...tail, ...spaced, { line: [{ t: `╰${"─".repeat(Math.max(1, inner))}╯`, c: c.border }] }].slice(0, rows);

  const lines: Line[] = all.map((r) => r.line);
  const regions: (SidebarRegion | undefined)[] = all.map((r) => r.region);
  while (lines.length < rows) {
    lines.push(blank(sidebarW));
    regions.push(undefined);
  }
  return { lines, regions };
}
