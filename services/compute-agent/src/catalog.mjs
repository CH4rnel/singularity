// Turn a catalog entry plus an image digest into a manifest — what CI does
// after it pushes an image, and what a DAO proposal then commits to.

import { readFile } from "node:fs/promises";
import { SCHEMA } from "./manifest.mjs";

const OUT_OF_MANIFEST = new Set(["wave", "treasury", "build", "notes"]);

export async function loadCatalog(path = new URL("../catalog.json", import.meta.url)) {
  return JSON.parse(await readFile(path, "utf8"));
}

export function render(entry, digest) {
  if (!/^sha256:[0-9a-f]{64}$/.test(digest)) throw new Error("digest must be sha256:<64 hex>");
  const m = { schema: SCHEMA };
  for (const [k, v] of Object.entries(entry)) if (!OUT_OF_MANIFEST.has(k)) m[k] = v;
  m.image = `${entry.image}@${digest}`;
  return m;
}
