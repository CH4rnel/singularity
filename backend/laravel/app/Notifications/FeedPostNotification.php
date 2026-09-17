<?php

namespace App\Notifications;

use App\Models\AnalyticsUser;
use App\Models\Post;
use App\Models\User;
use App\Support\Localised;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Somebody wrote in the feed, announced to everyone who can be reached.
 *
 * This is the loudest thing this server does: one post wakes every device that
 * has ever allowed notifications, accounts and wallet installations alike. It
 * is deliberate — a feed nobody is told about is a feed nobody reads, and this
 * project's whole audience is already carrying the wallet rather than visiting
 * the site — but it is also why the author is excluded, why the trigger is a
 * *new* post and never an edit, and why `PostController` keeps its ten-a-minute
 * throttle: the cost of a mistake here is paid by everybody at once.
 *
 * Sent synchronously (not ShouldQueue), like every other notification here:
 * production runs no queue worker, so the caller defers the fan-out to after
 * the response instead.
 *
 * The title is a locale map and the body is the post. A notification is the one
 * surface that speaks to somebody who is not looking at a browser, so it cannot
 * borrow the page's language — it writes in whatever the browser said when it
 * registered for push, and falls back to English exactly as `t()` does. What
 * the person actually wrote is not translated and not summarised, only cut: a
 * push that rewrites what somebody said is a push that misquotes them.
 */
class FeedPostNotification extends Notification
{
    /** As much of a post as a phone shows before it truncates anyway. */
    private const EXCERPT = 140;

    public function __construct(
        public string $author,
        public string $excerpt,
        public string $url,
    ) {}

    public static function for(Post $post): self
    {
        $author = $post->user;

        return new self(
            author: $author?->onchain_nickname ?: ($author?->name ?? 'Cyberia'),
            // One character of ellipsis rather than three dots, so the whole
            // thing is EXCERPT characters and not EXCERPT plus punctuation.
            excerpt: Str::limit(trim((string) $post->body), self::EXCERPT - 1, '…'),
            // The wallet is where these people are; `?section=feed` opens the
            // feed rather than the portfolio, and the site page answers the
            // same address for a browser that has no wallet.
            url: '/wallet?section=feed',
        );
    }

    /** Named for the notifiable, because Notification::locale() is taken. */
    private function localeOf(object $notifiable): ?string
    {
        return $notifiable instanceof User || $notifiable instanceof AnalyticsUser
            ? $notifiable->notification_locale
            : null;
    }

    public function titleFor(object $notifiable): string
    {
        return Localised::pick(
            [
                'en' => 'New post from {name}',
                'ru' => 'Новый пост: {name}',
                'zh' => '{name} 发布了新动态',
            ],
            $this->localeOf($notifiable),
            ['name' => $this->author],
        );
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // An installation has no bell to read, so it gets push and nothing
        // else; an account gets both, since the row is what the bell shows on
        // the next visit.
        $channels = $notifiable instanceof AnalyticsUser ? [] : ['database'];

        if (config('webpush.vapid.public_key')
            && ($notifiable instanceof User || $notifiable instanceof AnalyticsUser)
            && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'feed.post',
            'title' => $this->titleFor($notifiable),
            'body' => $this->excerpt,
            'url' => $this->url,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->titleFor($notifiable))
            ->body($this->excerpt)
            ->icon('/apple-touch-icon.png')
            ->data(['url' => $this->url]);
    }
}
