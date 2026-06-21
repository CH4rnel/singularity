<?php

namespace App\Http\Controllers;

use App\Services\FediverseLookup;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

/**
 * A dashboard tool for inspecting the Fediverse from Cyberia: enter an
 * ActivityPub handle (user@instance.tld) or profile URL and the page resolves
 * the actor via WebFinger and renders its ActivityStreams profile. This is the
 * read side of the identity bridge between Cyberia wallets and Fediverse actors.
 */
class FediverseController extends Controller
{
    public function index(Request $request, FediverseLookup $lookup): Response
    {
        $validated = $request->validate([
            'handle' => ['nullable', 'string', 'max:255'],
        ]);

        $handle = trim($validated['handle'] ?? '');
        $actor = null;
        $raw = null;
        $posts = [];
        $error = null;

        if ($handle !== '') {
            try {
                ['actor' => $actor, 'raw' => $raw, 'posts' => $posts] = $lookup->resolve($handle);
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $error = "Could not resolve “{$handle}”. The instance may be unreachable or the actor may not exist.";
            }
        }

        return Inertia::render('Fediverse', [
            'handle' => $handle,
            'actor' => $actor,
            'raw' => $raw,
            'posts' => $posts,
            'error' => $error,
        ]);
    }
}
