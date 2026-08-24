<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Ask one person what happened to them.
 *
 * Not a broadcast and not a campaign: this is sent by hand, to somebody whose
 * session an operator has actually read, and it exists because a funnel can
 * say where a person stopped and never why. The first outside user of this
 * wallet funded it, made a trade, watched a bridge not pay out and left the
 * next morning — none of which any dashboard will ever explain.
 *
 * It points *out* of the app on purpose. There is nowhere inside this product
 * to have a conversation with a stranger, and inventing one for a single
 * message would be building a feature to avoid sending a message.
 */
class FeedbackRequestNotification extends Notification
{
    public function __construct(
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
            'type' => 'feedback_request',
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
            ->action('Open', 'open');
    }
}
