<?php

namespace App\Http\Controllers;

use App\Services\GamificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NO CARRIER, unlocked with experience.
 *
 * The build is served *through* this controller rather than from `public/`,
 * so the gate covers the whole game and not only its front door. That costs a
 * little throughput on a 40 MB wasm file and buys the thing the unlock is for:
 * an asset path anybody could guess would make the price a formality.
 *
 * The build itself is not in the repository — it is a Godot export, and
 * committing forty megabytes of wasm to serve twenty people is the wrong
 * trade. It is uploaded to the host, and a missing build says so plainly
 * instead of rendering a broken canvas.
 */
class NoCarrierController extends Controller
{
    /** Every file a Godot 4 web export asks for, and nothing else. */
    private const TYPES = [
        'js' => 'text/javascript',
        'wasm' => 'application/wasm',
        'pck' => 'application/octet-stream',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'json' => 'application/json',
        'html' => 'text/html',
    ];

    public function show(Request $request, GamificationService $gamification)
    {
        if (! $this->unlocked($request, $gamification)) {
            return Inertia::render('game/NoCarrierLocked', [
                'cost' => $this->cost(),
                'spendable' => $request->user() === null
                    ? 0
                    : $gamification->spendable($request->user()),
            ]);
        }

        $index = $this->path('index.html');

        if ($index === null) {
            return Inertia::render('game/NoCarrierMissing');
        }

        /*
         * Godot's shell asks for its siblings by relative name — `index.js`,
         * `index.wasm` — which resolves against the *directory* of the current
         * URL. Laravel normalises away the trailing slash, so `/game/nocarrier`
         * and `/game/nocarrier/` are the same route and the browser would look
         * for `/game/index.js`. A `<base>` states the directory outright and
         * makes the question moot.
         */
        $html = (string) file_get_contents($index);
        $base = '<base href="'.route('nocarrier').'/">';

        // Prepended when there is no <head> to sit inside, because a silent
        // miss here is a game whose every asset 404s.
        $html = str_contains($html, '<head>')
            ? str_replace('<head>', '<head>'.$base, $html)
            : $base.$html;

        return ResponseFactory::make($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // The export runs without threads, so cross-origin isolation is
            // not required — sent anyway so a threaded re-export keeps working.
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Embedder-Policy' => 'require-corp',
        ]);
    }

    public function asset(
        Request $request,
        GamificationService $gamification,
        string $file,
    ): BinaryFileResponse|StreamedResponse|Response {
        abort_unless($this->unlocked($request, $gamification), 404);

        $path = $this->path($file);
        $extension = mb_strtolower(pathinfo($file, PATHINFO_EXTENSION));

        abort_if($path === null || ! isset(self::TYPES[$extension]), 404);

        return ResponseFactory::file($path, [
            'Content-Type' => self::TYPES[$extension],
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            // Immutable: every filename here changes when the game is
            // re-exported, and the wasm is 40 MB.
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    private function unlocked(Request $request, GamificationService $gamification): bool
    {
        $user = $request->user();

        return $user !== null && ($gamification->perksFor($user)['nocarrier'] ?? 0) > 0;
    }

    private function cost(): int
    {
        foreach ((array) config('gamification.unlocks', []) as $unlock) {
            if (($unlock['effects']['nocarrier'] ?? 0) > 0) {
                return (int) $unlock['cost'];
            }
        }

        return 0;
    }

    /**
     * A file inside the build, or null.
     *
     * The name is matched against a flat listing rather than joined onto a
     * root: a path that never leaves this directory cannot be talked into
     * doing so with `..`, and the export has no subdirectories to lose.
     */
    private function path(string $file): ?string
    {
        $root = rtrim((string) config('gamification.nocarrier_path', ''), '/');

        if ($root === '' || ! is_dir($root)) {
            return null;
        }

        $name = basename($file);
        $full = $root.'/'.$name;

        return $name !== '' && is_file($full) ? $full : null;
    }
}
