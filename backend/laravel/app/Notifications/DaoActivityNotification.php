<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Generic DAO activity notification (new proposal, comment, vote, reaction).
 *
 * Sent synchronously (not ShouldQueue) — callers defer delivery with
 * dispatch(...)->afterResponse() because production runs no queue worker.
 */
class DaoActivityNotification extends Notification
{
    public function __construct(
        public string $type,
        public User $actor,
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
            'actor_id' => $this->actor->id,
            'actor_name' => $this->actor->name,
            'actor_wallet' => $this->actor->wallet_address,
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
