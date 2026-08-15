#!/usr/bin/env -S npx tsx
/**
 * Markdown renderer smoke test (pure, headless): fenced code blocks highlight
 * into bordered panels, headings/lists/tables/blockquotes collapse into lines,
 * every line stays within `width`, and measurement agrees with rendering.
 * Run: npm run markdown:smoke
 */
import { THEMES } from "../src/clients/tui/theme.js";
import { mdToLines, hasOpenFence, highlightCode, lineWidth } from "../src/clients/tui/markdown.js";

const results: [string, boolean][] = [];
const check = (name: string, pass: boolean) => results.push([name, pass]);

const theme = THEMES.wired;
const W = 60;
const md = [
  "# Hello",
  "",
  "some **bold** text with `inline code` and [a link](https://cyberia.church).",
  "",
  "- first",
  "- second",
  "",
  "> a quiet quote",
  "",
  "| a | b |",
  "|---|---|",
  "| 1 | 2 |",
  "",
  "```js",
  "const x = 1;",
  "if (x) console.log(x);",
  "```",
].join("\n");

const lines = mdToLines(md, theme, W);
const text = lines.map((l) => l.map((s) => s.t).join("")).join("\n");

check("parses a heading       ", text.includes("Hello"));
check("bold is flagged        ", lines.some((l) => l.some((s) => s.b && s.t.includes("bold"))));
check("inline code on bg      ", lines.some((l) => l.some((s) => s.bg && s.t === "code")));
check("link shown underlined  ", lines.some((l) => l.some((s) => s.u && s.t === "link")));
check("bullets present        ", text.includes("• first") && text.includes("• second"));
check("quote has gutter       ", text.includes("▍") && text.includes("a quiet quote"));
check("table rows render      ", lines.some((l) => l.some((s) => s.t === "│") && /[12]/.test(l.map((x) => x.t).join(""))));
check("code fence is a panel  ", text.includes("╭ js ") && text.includes("╰") && text.includes("const x = 1"));
check("syntax highlighted     ", lines.some((l) => l.some((s) => s.c === theme.primary && s.t.includes("const"))));
check("all lines fit width    ", lines.every((l) => lineWidth(l) <= W));
check("empty md has one line  ", mdToLines("", theme, W).length === 1);

const hl = highlightCode("fn main() { println!(\"hi\"); }", "rust", theme);
check("highlight splits lines ", hl.length >= 1 && hl.every((l) => l.every((s) => s.bg)));
check("open fence detected    ", hasOpenFence("```\ncode") && !hasOpenFence("```\ncode\n```"));
check(
  "long token hard-wraps   ",
  (() => {
    const l = mdToLines(`\`\`\`\n${"a".repeat(200)}\n\`\`\``, theme, 30);
    return l.length >= 3 && l.every((x) => lineWidth(x) <= 30);
  })(),
);

let ok = true;
for (const [name, pass] of results) {
  console.log(`${name}: ${pass ? "PASS" : "FAIL"}`);
  ok &&= pass;
}
console.log(ok ? "MARKDOWN PROBE OK" : "MARKDOWN PROBE FAILED");
process.exit(ok ? 0 : 1);
