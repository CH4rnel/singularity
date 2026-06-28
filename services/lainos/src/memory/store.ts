import { randomUUID } from "node:crypto";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import type { Memory, MemoryStore } from "../types.js";

/**
 * File-backed memory store. Deliberately dependency-free: the whole store is
 * an in-memory array persisted to a JSON file. Retrieval is a naive blend of
 * keyword overlap, recency, and importance — good enough for an agent's
 * working memory, and swappable for a vector store later (the MemoryStore
 * interface is the contract).
 */
export class FileMemoryStore implements MemoryStore {
  private memories: Memory[] = [];
  private learned: { fact: string; metadata?: Record<string, unknown>; at: number }[] = [];
  private loaded = false;
  private readonly file: string;

  constructor(dataDir: string) {
    this.file = join(dataDir, "memory.json");
  }

  private async ensureLoaded() {
    if (this.loaded) return;
    try {
      const raw = await readFile(this.file, "utf8");
      const parsed = JSON.parse(raw);
      this.memories = parsed.memories ?? [];
      this.learned = parsed.learned ?? [];
    } catch {
      // Fresh store.
    }
    this.loaded = true;
  }

  private async persist() {
    await mkdir(dirname(this.file), { recursive: true });
    const payload = JSON.stringify(
      { memories: this.memories, learned: this.learned },
      null,
      2,
    );
    await writeFile(this.file, payload, "utf8");
  }

  async add(memory: Memory): Promise<void> {
    await this.ensureLoaded();
    if (!memory.id) memory.id = randomUUID();
    this.memories.push(memory);
    // Keep the store bounded; archive oldest beyond a cap.
    const CAP = 5000;
    if (this.memories.length > CAP) {
      this.memories = this.memories.slice(this.memories.length - CAP);
    }
    await this.persist();
  }

  async recent(roomId: string, limit: number): Promise<Memory[]> {
    await this.ensureLoaded();
    return this.memories
      .filter((m) => m.roomId === roomId)
      .slice(-limit);
  }

  async search(roomId: string, query: string, limit: number): Promise<Memory[]> {
    await this.ensureLoaded();
    const terms = tokenize(query);
    const now = Date.now();
    const scored = this.memories
      .filter((m) => m.roomId === roomId)
      .map((m) => {
        const overlap = keywordOverlap(terms, tokenize(m.content));
        const ageHours = (now - m.createdAt) / 3_600_000;
        const recency = 1 / (1 + ageHours); // decays with age
        const importance = (m.importance ?? 0) * 0.1;
        return { m, score: overlap * 2 + recency + importance };
      })
      .filter((s) => s.score > 0)
      .sort((a, b) => b.score - a.score)
      .slice(0, limit)
      .map((s) => s.m);
    return scored;
  }

  async remember(fact: string, metadata?: Record<string, unknown>): Promise<void> {
    await this.ensureLoaded();
    const norm = fact.trim();
    if (!norm) return;
    if (this.learned.some((l) => l.fact.toLowerCase() === norm.toLowerCase())) return;
    this.learned.push({ fact: norm, metadata, at: Date.now() });
    const CAP = 500;
    if (this.learned.length > CAP) this.learned = this.learned.slice(-CAP);
    await this.persist();
  }

  async facts(limit: number): Promise<string[]> {
    await this.ensureLoaded();
    return this.learned.slice(-limit).map((l) => l.fact);
  }
}

const STOPWORDS = new Set([
  "the", "a", "an", "and", "or", "but", "is", "are", "was", "to", "of", "in",
  "on", "for", "with", "i", "you", "it", "this", "that", "my", "your",
]);

function tokenize(text: string): string[] {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9а-я\s]/gi, " ")
    .split(/\s+/)
    .filter((t) => t.length > 1 && !STOPWORDS.has(t));
}

function keywordOverlap(a: string[], b: string[]): number {
  if (a.length === 0 || b.length === 0) return 0;
  const setB = new Set(b);
  let hits = 0;
  for (const t of a) if (setB.has(t)) hits++;
  return hits / a.length;
}
