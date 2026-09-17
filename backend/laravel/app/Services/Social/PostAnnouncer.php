<?php

namespace App\Services\Social;

use App\Models\AnalyticsUser;
use App\Models\Post;
use App\Models\User;
use App\Notifications\FeedPostNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A new post, delivered to everyone who agreed to be delivered to.
 *
 * Two audiences and not one, because this project has two kinds of person: the
 * site's accounts, and the wallet installations that never signed up for
 * anything (`AnalyticsUser` — the wallet is non-custodial, so a subscription
 * hangs off the installation's own uuid rather than off a login). Most people
 * here are the second kind, which is exactly why pushing only accounts would
 * mean pushing almost nobody.
 *
 * Only devices that *have* a subscription are considered: everything else is a
 * row that cannot be reached, and walking it would turn one post into a full
 * table scan of people who would hear nothing.
 *
 * The author is excluded. Delivery is per recipient and one failure never stops
 * the rest — a push service drops endpoints without telling anybody, and the
 * next person in the list has nothing to do with it.
 */
class PostAnnouncer
{
    /** Rows held in memory at once while the list is walked. */
    private const CHUNK = 200;

    /**
     * How many devices one post may wake.
     *
     * Not a policy about attention — a stop on a synchronous loop that runs
     * inside a web request on a host with no queue worker. If the audience
     * ever outgrows it, the fan-out belongs on a queue, and this constant is
     * where that becomes visible instead of as a request that times out.
     */
    private const CEILING = 2000;

    public function announce(Post $post): void
    {
        $post->loadMissing('user:id,name,onchain_nickname');

        $notification = FeedPostNotification::for($post);
        $sent = 0;

        $deliver = function (object $recipient) use ($notification, &$sent): void {
            if ($sent >= self::CEILING) {
                return;
            }

            try {
                $recipient->notify($notification);
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        };

        $this->accounts((int) $post->user_id)
            ->chunkById(self::CHUNK, fn ($users) => $users->each($deliver));

        $this->installations()
            ->chunkById(self::CHUNK, fn ($installs) => $installs->each($deliver));
    }

    /** Site accounts with at least one live subscription, minus the author. */
    private function accounts(int $authorId): Builder
    {
        return User::query()
            ->whereNull('merged_into_id')
            ->whereKeyNot($authorId)
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('push_subscriptions')
                ->whereColumn('push_subscriptions.subscribable_id', 'users.id')
                ->where('push_subscriptions.subscribable_type', User::class));
    }

    /**
     * Wallet installations with a subscription.
     *
     * An installation is not matched against the author: the uuid is minted in
     * a browser and this server never learns whose wallet it is, so the author
     * getting their own post on their own phone is the honest price of not
     * being able to identify them. The wallet drops a notification for a post
     * it has just written on the device that wrote it.
     */
    private function installations(): Builder
    {
        return AnalyticsUser::query()
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('push_subscriptions')
                ->whereColumn('push_subscriptions.subscribable_id', 'analytics_users.id')
                ->where('push_subscriptions.subscribable_type', AnalyticsUser::class));
    }
}
