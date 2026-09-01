<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\ProgressNotification;
use App\Support\VapidHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Send a notification to somebody, from the console.
 *
 * The gamification system writes on its own schedule and only about what it
 * can detect. This is the other half — an operator with something to say and
 * nowhere to say it. It exists because the first question after switching push
 * on was "why did nothing arrive", and the only way to answer it was a tinker
 * session.
 *
 * Three things are on the screen for that reason, and none of them are the
 * message: whether the keys can actually sign (a key that is set and unusable
 * is worse than a missing one, because a missing one turns the button off),
 * who is reachable at all, and what was sent recently. A composer without
 * those is a send button that lies.
 */
class ConsolePushController extends Controller
{
    private const RECENT = 30;

    public function index(): Response
    {
        return Inertia::render('crm/Push', $this->props());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'audience' => ['required', 'string', 'in:all,user'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'required_if:audience,user'],
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:300'],
            'url' => ['nullable', 'string', 'max:200', 'starts_with:/'],
            'title_en' => ['nullable', 'string', 'max:80'],
            'body_en' => ['nullable', 'string', 'max:300'],
        ]);

        $health = VapidHealth::check();

        if (! $health['ok']) {
            return back()->withErrors(['title' => 'Ключи не в порядке: '.implode(' ', $health['problems'])]);
        }

        $recipients = $this->reachable()
            ->when(
                $validated['audience'] === 'user',
                fn ($query) => $query->where('users.id', (int) $validated['user_id']),
            )
            ->get();

        if ($recipients->isEmpty()) {
            return back()->withErrors(['title' => 'Некому отправлять: ни одной подписки.']);
        }

        /*
         * Russian is what the operator typed, English is what everyone else
         * falls back to — so the Russian text is the `ru` entry and also the
         * `en` one unless a translation was given. Writing one language and
         * silently sending it as "English" would put Russian text in front of
         * a reader who asked for English.
         */
        $title = ['ru' => $validated['title'], 'en' => $validated['title_en'] ?? '' ?: $validated['title']];
        $body = ['ru' => $validated['body'], 'en' => $validated['body_en'] ?? '' ?: $validated['body']];

        $sent = 0;
        $failed = 0;

        foreach ($recipients as $user) {
            try {
                $user->notify(new ProgressNotification(
                    type: 'console.broadcast',
                    title: $title,
                    body: $body,
                    url: $validated['url'] ?? '' ?: '/profile',
                ));
                $sent++;
            } catch (\Throwable $e) {
                // One dead endpoint must not stop the rest: a push service
                // drops subscriptions without telling anybody, and the next
                // person in the list has nothing to do with it.
                $failed++;
                report($e);
            }
        }

        return back()->with('status', "push-sent:{$sent}:{$failed}");
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        $recipients = $this->reachable()->get();

        return [
            'health' => VapidHealth::check(),
            'recipients' => $recipients->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->onchain_nickname ?: $user->name,
                'locale' => $user->notification_locale,
                'devices' => (int) $user->devices,
            ])->values(),
            'coverage' => [
                'reachable' => $recipients->count(),
                'accounts' => User::query()->whereNull('merged_into_id')->count(),
                'devices' => (int) $recipients->sum('devices'),
            ],
            'recent' => $this->recent(),
        ];
    }

    /**
     * Everyone with at least one live subscription. This is the denominator
     * that matters — an account with no subscription cannot be pushed to, and
     * printing it as a possible recipient would be a lie.
     */
    private function reachable()
    {
        return User::query()
            ->whereNull('merged_into_id')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('push_subscriptions')
                ->whereColumn('push_subscriptions.subscribable_id', 'users.id')
                ->where('push_subscriptions.subscribable_type', User::class))
            ->select('users.*')
            ->selectSub(
                DB::table('push_subscriptions')
                    ->selectRaw('count(*)')
                    ->whereColumn('push_subscriptions.subscribable_id', 'users.id')
                    ->where('push_subscriptions.subscribable_type', User::class),
                'devices',
            )
            ->orderBy('users.id');
    }

    /**
     * What has been sent lately, read from the notification log rather than a
     * table of its own — every push is also a database notification, so a
     * second record would be a second place to disagree.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recent(): array
    {
        return DB::table('notifications')
            ->where('type', ProgressNotification::class)
            ->orderByDesc('created_at')
            ->limit(self::RECENT)
            ->get(['data', 'created_at', 'notifiable_id', 'read_at'])
            ->map(function ($row): array {
                $data = json_decode((string) $row->data, true) ?: [];

                return [
                    'kind' => $data['type'] ?? '—',
                    'title' => $data['title'] ?? '',
                    'body' => $data['body'] ?? '',
                    'user_id' => (int) $row->notifiable_id,
                    'read' => $row->read_at !== null,
                    'at' => (string) $row->created_at,
                ];
            })
            ->all();
    }
}
