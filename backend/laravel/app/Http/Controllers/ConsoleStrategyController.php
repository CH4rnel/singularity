<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ConsoleStrategyController extends Controller
{
    private const STORED_PATH = 'console/content-strategy.html';

    public function index(): InertiaResponse
    {
        $disk = Storage::disk('local');
        $edited = $disk->exists(self::STORED_PATH);

        return Inertia::render('crm/Strategy', [
            'edited' => $edited,
            'updatedAt' => $edited
                ? Carbon::createFromTimestamp($disk->lastModified(self::STORED_PATH))->toIso8601String()
                : null,
        ]);
    }

    /** Serve the report separately so its extensive CSS cannot leak into the console. */
    public function document(): Response
    {
        return response($this->html())
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self' data: blob:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'html' => ['required', 'string', 'max:7000000'],
        ]);

        Storage::disk('local')->put(self::STORED_PATH, $this->sanitise($validated['html']));

        return back()->with('success', 'Strategy saved');
    }

    public function reset(): RedirectResponse
    {
        Storage::disk('local')->delete(self::STORED_PATH);

        return back()->with('success', 'Strategy reset');
    }

    private function html(): string
    {
        $disk = Storage::disk('local');

        if ($disk->exists(self::STORED_PATH)) {
            return $disk->get(self::STORED_PATH);
        }

        $html = file_get_contents(resource_path('console-strategy/cyberia_content_strategy_report.html'));

        abort_if($html === false, 404);

        return $this->sanitise($html);
    }

    /** The frame cannot execute scripts; stripping active markup keeps saved copies inert too. */
    private function sanitise(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/<\/?(?:iframe|object|embed|form|base)\b[^>]*>/i', '', $html) ?? '';

        return $html;
    }
}
