<?php

namespace App\Support;

use App\Http\Middleware\EnsureCrmAdmin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Whether this page load is recorded by Microsoft Clarity, and how.
 *
 * The product tables (`analytics_*`) answer *what* happened — a swap failed, a
 * wallet was funded — and say nothing about *why*: the button nobody found, the
 * screen somebody left after three taps on something that is not a button.
 * Clarity is the other half: replays, click and scroll heatmaps, rage and dead
 * clicks, JavaScript errors, free and without a session cap.
 *
 * Who is never recorded is decided here, before a byte of the tag is sent:
 *
 *   - nobody, while no project id is configured or outside production;
 *   - the operators — their sessions are the noise the console already
 *     excludes from every product number, and the console itself is the most
 *     private screen this site has;
 *   - the store builds (`CyberiaStore/`), whose Data safety and App Privacy
 *     answers declare no session recording, and the desktop shell, which
 *     loads no remote script at all;
 *   - a browser that sends Do Not Track or Global Privacy Control, under the
 *     same switch the product analytics honour.
 *
 * The person's own "do not record" lives in the browser
 * (`cyberia.analytics.optout`, the same key Wallet → Security writes), so the
 * tag checks it before loading anything.
 *
 * What is recorded is masked in three layers: Clarity masks every input in
 * every mode, the wallet and the other screens that hold secrets or the
 * person's own addresses carry `data-clarity-mask` (which no dashboard setting
 * overrides), and the screens below are not recorded at all — Clarity is paused
 * on them, because a modal teleported to `<body>` escapes any masked root.
 */
class SessionReplay
{
    /**
     * Inertia components on which recording is paused, by prefix.
     *
     * Two-factor secrets, recovery codes and the console's contacts live here.
     *
     * @var list<string>
     */
    public const PAUSED_PAGES = ['crm/', 'settings/', 'teams/', 'admin/'];

    /**
     * The tag's settings for this request, or null when it is not recorded.
     *
     * @return array{project: string, paused: list<string>, autostart: bool}|null
     */
    public static function for(Request $request, ?string $component = null): ?array
    {
        $project = trim((string) config('services.clarity.project_id'));

        if (! config('services.clarity.enabled') || $project === '') {
            return null;
        }

        if (EnsureCrmAdmin::allows($request->user())) {
            return null;
        }

        $agent = (string) $request->userAgent();

        if (str_contains($agent, 'CyberiaStore/') || str_contains($agent, 'CyberiaDesktop/')) {
            return null;
        }

        if (config('analytics.respect_dnt') && ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1')) {
            return null;
        }

        return [
            'project' => $project,
            'paused' => self::PAUSED_PAGES,
            'autostart' => ! self::paused($component),
        ];
    }

    /**
     * A static page, with the tag put in front of its `</head>`.
     *
     * The landing pages are plain HTML files rather than Blade, and the landing
     * page is where most visits begin — leaving it out would start every
     * recording one page after the first impression.
     */
    public static function page(string $path): Response
    {
        $html = (string) file_get_contents($path);
        $tag = view('partials.session-replay')->render();

        if (trim($tag) !== '') {
            $html = preg_replace('~</head>~i', $tag.'</head>', $html, 1) ?? $html;
        }

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public static function paused(?string $component): bool
    {
        if ($component === null) {
            return false;
        }

        foreach (self::PAUSED_PAGES as $prefix) {
            if (str_starts_with($component, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
