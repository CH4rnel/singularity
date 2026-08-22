<?php

namespace App\Http\Controllers;

use App\Services\Console\Mockup;
use Illuminate\Http\Response;
use Inertia\Inertia;

/**
 * The console's own design, as a lens.
 *
 * The mockup answers a question the running console cannot: what was this
 * supposed to be, and why is it shaped this way. Keeping it at a URL beside
 * the thing it describes is the only way it stays reachable a year from now —
 * a canvas link rots, an exported PNG loses its text.
 *
 * An artboard is served as its own document rather than inlined, because it
 * carries a whole page's worth of CSS: dropped into the console's DOM it
 * would restyle the console. The page frames it in a sandbox, so nothing in
 * the design can script, navigate or reach the session it is being shown in.
 */
class ConsoleMockupController extends Controller
{
    public function __construct(protected Mockup $mockup) {}

    public function index()
    {
        return Inertia::render('crm/Mockup', $this->mockup->manifest());
    }

    public function screen(string $screen): Response
    {
        $html = $this->mockup->screen($screen);

        abort_if($html === null, 404);

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            // The artboards are style and markup only — no script ever ran in
            // them, and this says so out loud instead of trusting the file.
            ->header('Content-Security-Policy', implode('; ', [
                "default-src 'none'",
                "style-src 'unsafe-inline' https://fonts.googleapis.com",
                'font-src https://fonts.gstatic.com',
                'img-src data:',
                "frame-ancestors 'self'",
            ]));
    }
}
