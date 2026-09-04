<?php

namespace App\Http\Controllers;

use App\Models\TrackerRelease;
use App\Services\Tracker\ReleaseIndex;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The tracker as a web page.
 *
 * Public and server-rendered, which is the difference between a tracker and an
 * app feature: a release has an address anyone can open, paste and archive,
 * whether or not they have this wallet or any wallet at all. The wallet's own
 * screen reads the same rows through the JSON API.
 */
class TrackerController extends Controller
{
    public function index(Request $request, ReleaseIndex $index): Response
    {
        return Inertia::render('Tracker', [
            'results' => $index->search($request->only(['q', 'category', 'sort', 'owner', 'page'])),
            'context' => $index->context(),
            'release' => null,
        ]);
    }

    public function show(string $infoHash, ReleaseIndex $index): Response
    {
        $release = TrackerRelease::query()
            ->listed()
            ->where('info_hash', strtolower($infoHash))
            ->first();

        if ($release === null) {
            throw new NotFoundHttpException('No such release.');
        }

        return Inertia::render('Tracker', [
            'results' => null,
            'context' => $index->context(),
            'release' => $release->toPublicArray(),
        ]);
    }
}
