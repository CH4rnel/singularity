<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrackerRelease;
use App\Services\Tracker\RegistrationFailed;
use App\Services\Tracker\ReleaseIndex;
use App\Services\Tracker\ReleaseRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The index, as JSON.
 *
 * Read freely and publish by minting: there is no account here and no session,
 * because the credential for putting something on this tracker is a token on a
 * chain and the credential for reading it is nothing at all.
 *
 * `store` is the one write, and what it takes is deliberately tiny — a chain
 * and a token id. Everything else about the release is read by the server from
 * the chain and from the document the token points at, so this endpoint has no
 * field anyone could put a lie in.
 */
class TrackerController extends Controller
{
    public function index(Request $request, ReleaseIndex $index): JsonResponse
    {
        return response()->json(
            $index->search($request->only(['q', 'category', 'sort', 'owner', 'page']))
                + ['context' => $index->context()],
        );
    }

    public function show(string $infoHash, ReleaseIndex $index): JsonResponse
    {
        $release = TrackerRelease::query()
            ->listed()
            ->where('info_hash', strtolower($infoHash))
            ->first();

        if ($release === null) {
            return response()->json(['message' => 'No such release.'], 404);
        }

        return response()->json([
            'release' => $release->toPublicArray(),
            'context' => $index->context(),
        ]);
    }

    public function store(Request $request, ReleaseRegistrar $registrar): JsonResponse
    {
        $validated = $request->validate([
            'chain_id' => ['required', 'integer'],
            'token_id' => ['required', 'string', 'max:78'],
        ]);

        try {
            $release = $registrar->register(
                (int) $validated['chain_id'],
                (string) $validated['token_id'],
            );
        } catch (RegistrationFailed $failure) {
            // 422 and the sentence, because every one of these is something
            // the person can act on: mint again with the field filled in, or
            // wait for the transaction they just sent to be mined.
            return response()->json(['message' => $failure->getMessage()], 422);
        }

        return response()->json(['release' => $release->toPublicArray()], 201);
    }
}
