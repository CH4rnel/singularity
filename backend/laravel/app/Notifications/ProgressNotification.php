<?php

namespace App\Notifications;

use App\Models\AnalyticsUser;
use App\Models\User;
use App\Support\Localised;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Level-up and quest-completion notice. Shares the bell dropdown with
 * DaoActivityNotification and, when the user has a push subscription, is the
 * one thing that can pull them back into the app on its own — so keep it rare
 * and only ever fire it on real progress.
 *
 * Sent synchronously (not ShouldQueue): production runs no queue worker.
 *
 * Title and body are locale maps, not strings. A notification is the one
 * surface that speaks to somebody who is not looking at a browser, so it
 * cannot borrow the page's language the way every other surface does — it
 * writes in whatever the browser said when it registered for push, and falls
 * back to English exactly like `t()` does.
 */
class ProgressNotification extends Notification
{
    /**
     * @param  array<string, string>  $title
     * @param  array<string, string>  $body
     * @param  array<string, string|int>  $replace
     */
    public function __construct(
        public string $type,
        public array $title,
        public array $body,
        public string $url,
        public array $replace = [],
    ) {}

    /** Named for the notifiable, because Notification::locale() is taken. */
    private function localeOf(object $notifiable): ?string
    {
        return $notifiable instanceof User || $notifiable instanceof AnalyticsUser
            ? $notifiable->notification_locale
            : null;
    }

    public function titleFor(object $notifiable): string
    {
        return Localised::pick($this->title, $this->localeOf($notifiable), $this->replace);
    }

    public function bodyFor(object $notifiable): string
    {
        return Localised::pick($this->body, $this->localeOf($notifiable), $this->replace);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /*
         * An installation has no bell to read, so it gets push and nothing
         * else. A site account gets both: the row is what the bell shows on
         * the next visit, and the push is what arrives while nobody is
         * looking.
         */
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
            'type' => $this->type,
            'title' => $this->titleFor($notifiable),
            'body' => $this->bodyFor($notifiable),
            'url' => $this->url,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->titleFor($notifiable))
            ->body($this->bodyFor($notifiable))
            ->icon('/apple-touch-icon.png')
            ->data(['url' => $this->url]);
    }
}
