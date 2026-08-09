<?php

namespace App\Http\Controllers;

use App\Services\AppDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one page that answers "where do I get the app".
 *
 * Until this existed the desktop shell could only be run from a checkout and the
 * APK travelled by hand through Telegram, which is not distribution — it is a
 * favour that does not scale and that nobody can verify.
 */
class DownloadController extends Controller
{
    public function __construct(private AppDownloadService $downloads) {}

    public function index(): Response
    {
        return Inertia::render('Download', [
            'catalog' => $this->downloads->catalog(),
        ]);
    }

    /** Same catalogue for the Telegram bot, the shells, and anything else. */
    public function json(): JsonResponse
    {
        return response()->json($this->downloads->catalog());
    }

    /**
     * A short link per platform: /download/android is a URL that fits in a
     * message and survives every release, which is exactly what was being done
     * by hand before.
     */
    public function platform(string $platform): RedirectResponse
    {
        $catalog = $this->downloads->catalog();

        foreach ($catalog['builds'] as $build) {
            if ($build['platform'] === $platform && $build['primary']) {
                return redirect()->away($build['url']);
            }
        }

        return redirect()->route('download');
    }
}
