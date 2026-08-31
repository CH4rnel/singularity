<?php

namespace App\Http\Controllers;

use App\Services\Console\ChatRoom;
use App\Services\Console\ConsoleFeed;
use App\Services\Console\ConsolePulse;
use App\Services\Console\Snooze;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Сейчас" — the home of the console.
 *
 * The old CRM home was a list of contacts, which answered a question nobody
 * arrives with. This one answers the question they do: what requires me right
 * now. Everything on it is computed by `ConsoleFeed`; the controller only
 * decides that an operator may see it and hands over what they pressed.
 */
class ConsoleController extends Controller
{
    public function __construct(
        private ConsoleFeed $feed,
        private Snooze $snooze,
    ) {}

    public function index(): Response
    {
        return Inertia::render('crm/Now', $this->feed->cached());
    }

    /**
     * The console's heartbeat: one version per lens, and the rail's badges.
     *
     * Every lens is open on more than one desk, so none of them may wait for
     * a reload to tell the truth. This is the cheapest question that answers
     * "has what I am looking at changed" — the browser compares the versions
     * it holds and re-reads only the lens that moved. It writes nothing and
     * rebuilds nothing (see `ConsolePulse`).
     */
    public function pulse(Request $request, ConsolePulse $pulse, ChatRoom $room): JsonResponse
    {
        $user = $request->user();

        /*
         * Presence in the room is, and stays, "this person's browser asked
         * the room for news". The room's own polling now hangs off this
         * heartbeat, so the reader who has it on screen says so here — and
         * somebody sitting on another lens is not drawn into the room.
         */
        if ($user !== null && $request->query('lens') === 'chat') {
            $room->markRead($user);
        }

        return response()->json($pulse->build($user));
    }

    /**
     * Put one item down until morning, or wake it up again.
     *
     * A snooze is a decision worth keeping: it says the operator has seen the
     * row and judged it. That is why it survives a page reload, and why the
     * item stays visible in the watch list with its wake-up time instead of
     * vanishing.
     */
    public function snooze(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:190'],
            'until' => ['nullable', 'date'],
        ]);

        $until = isset($data['until'])
            ? CarbonImmutable::parse($data['until'])
            : null;

        $this->snooze->put($data['key'], $until, $request->user()?->id);
        ConsoleFeed::forget();

        return back();
    }

    public function wake(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:190']]);

        $this->snooze->wake($data['key']);
        ConsoleFeed::forget();

        return back();
    }
}
