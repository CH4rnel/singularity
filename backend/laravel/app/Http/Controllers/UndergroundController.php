<?php

namespace App\Http\Controllers;

use App\Models\LaunchpadToken;
use App\Services\Launchpad\Underground;
use App\Support\HitCounter;
use App\Support\NetartCover;
use App\Support\UndergroundCopy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * underground.cyberia.church: the directory of every token's homepage, the
 * ring that links them, their hit counters and their covers.
 *
 * Everything here is public and read-only; the one thing that writes is a
 * visit to a homepage, which LaunchpadController counts when it serves one.
 */
class UndergroundController extends Controller
{
    public function __construct(private readonly Underground $underground) {}

    public function index(Request $request): Response
    {
        $locale = $this->locale($request);
        $directory = $this->underground->directory();

        return response()
            ->view('underground.index', [
                'copy' => UndergroundCopy::for($locale),
                'locale' => $locale,
                'directory' => $directory,
                'snippetFor' => fn (string $subdomain) => $this->snippet($subdomain),
            ])
            // The underground says more in its headers than on its page.
            ->header('X-Wired', 'knights')
            ->header('X-Layer', '08');
    }

    /** The stop that is not on the map. */
    public function lost(Request $request): Response
    {
        $locale = $this->locale($request);

        return response()->view('underground.lost', [
            'copy' => UndergroundCopy::for($locale),
            'locale' => $locale,
            'next' => $this->underground->url('ring/next/lost'),
        ]);
    }

    public function ring(string $direction, ?string $from = null): RedirectResponse
    {
        return redirect()->away($this->underground->step($direction, $from));
    }

    public function cover(string $address): Response
    {
        $address = Str::lower($address);
        $symbol = (string) LaunchpadToken::query()->where('address', $address)->value('symbol');
        $label = $symbol === '' ? '' : '$'.Str::upper($symbol);

        $svg = Cache::remember(
            'underground:cover:v1:'.$address.':'.$label,
            now()->addDay(),
            fn () => NetartCover::svg($address, $label),
        );

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // An SVG opened on its own is a document; this one has nothing to run.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }

    public function counter(string $subdomain): Response
    {
        $hits = (int) LaunchpadToken::query()
            ->where('site_subdomain', Str::lower($subdomain))
            ->value('site_hits');

        return response(HitCounter::svg($hits), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    /** The same directory, for the wallet's layer 08 and workshop. */
    public function directory(): JsonResponse
    {
        return response()->json($this->underground->directory());
    }

    /** Three links that make a page a stop on the ring. */
    private function snippet(string $subdomain): string
    {
        $link = fn (string $direction, string $label) => sprintf(
            '<a href="%s">%s</a>',
            $this->underground->url('ring/'.$direction.'/'.$subdomain),
            $label,
        );

        return $link('prev', '&larr;').' '
            .sprintf('<a href="%s">underground</a>', $this->underground->url())
            .' '.$link('next', '&rarr;');
    }

    private function locale(Request $request): string
    {
        $asked = (string) $request->query('lang', '');

        if (in_array($asked, UndergroundCopy::LOCALES, true)) {
            return $asked;
        }

        $preferred = (string) $request->getPreferredLanguage(UndergroundCopy::LOCALES);

        return in_array($preferred, UndergroundCopy::LOCALES, true) ? $preferred : 'en';
    }
}
