<?php

namespace App\Services\Console;

use Illuminate\Support\Facades\File;

/**
 * The design the console was built from, kept inside the console.
 *
 * `resources/console-mockup/` holds the nine artboards exactly as they were
 * drawn, plus the three annotations that argue for the decisions. They still
 * say "Мостик" — the name the console carried until it was pointed out that
 * this site already has a bridge — and they are not edited to agree with the
 * running console, because a design is a record of a decision and a record
 * that is kept current is not a record. It is frozen source, not a build artifact: nothing imports it,
 * Vite never sees it, and it is served only to an operator, because a design
 * shows numbers, names and thresholds that the public console does not.
 *
 * The screen key never reaches the filesystem. A request names a key, this
 * manifest maps the key to a file it already knows about, and anything else
 * is a 404 — so there is no path here for a request to traverse.
 */
class Mockup
{
    /**
     * The canvas as it was published: artboards in reading order, and the
     * annotations that sit beside them.
     *
     * @return array{screens: list<array{key: string, title: string, width: int, height: int}>, notes: list<array{key: string, text: string}>, source: string}
     */
    public function manifest(): array
    {
        $canvas = $this->canvas();

        $screens = [];

        foreach ($canvas['artboards'] ?? [] as $artboard) {
            $key = $this->keyFor((string) ($artboard['file'] ?? ''));

            if ($key === null || ! File::exists($this->pathFor($key))) {
                continue;
            }

            $screens[] = [
                'key' => $key,
                'title' => (string) ($artboard['title'] ?? $key),
                'width' => (int) ($artboard['w'] ?? 1440),
                'height' => (int) ($artboard['h'] ?? 1010),
            ];
        }

        $notes = array_map(fn (array $note): array => [
            'key' => (string) ($note['id'] ?? ''),
            'text' => (string) ($note['text'] ?? ''),
        ], $canvas['annotations'] ?? []);

        return [
            'screens' => $screens,
            'notes' => array_values($notes),
            'source' => (string) config('crm.console.mockup_url', ''),
        ];
    }

    /**
     * One artboard's markup, or null when the key is not one of ours.
     */
    public function screen(string $key): ?string
    {
        foreach ($this->manifest()['screens'] as $screen) {
            if ($screen['key'] === $key) {
                return File::get($this->pathFor($key));
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function canvas(): array
    {
        $path = $this->directory().'/canvas.json';

        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * `Main.dc.html` in the canvas is `Main.html` on disk and `main` in a URL.
     */
    protected function keyFor(string $file): ?string
    {
        $stem = str_replace('.dc.html', '', $file);

        return preg_match('/^[A-Za-z]+$/', $stem) === 1 ? strtolower($stem) : null;
    }

    protected function pathFor(string $key): string
    {
        return $this->directory().'/'.ucfirst($key).'.html';
    }

    protected function directory(): string
    {
        return resource_path('console-mockup');
    }
}
