<?php

namespace App\Notifications;

use App\Models\User;
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
 */
class ProgressNotification extends Notification
{
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('webpush.vapid.public_key')
            && $notifiable instanceof User
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
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/apple-touch-icon.png')
            ->data(['url' => $this->url]);
    }
}
