<?php

namespace App\Http\Controllers;

use App\Services\Console\ConsoleFeed;
use App\Services\Console\Snooze;
use Carbon\CarbonImmutable;
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
