<?php

namespace App\Notifications;

use App\Models\AnalyticsUser;
use App\Models\User;
use App\Support\Localised;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Somebody wrote to this address in the wallet's chat.
 *
 * Says that, and nothing else: no sender and no text. The mailbox is opened
 * with the address's own key, but the routes this notice travels are matched
 * to an address more loosely — an installation reports the addresses it holds
 * without proving them, and a Telegram account can name a wallet with
 * `/set_wallet` — so anything more specific would tell whoever claimed the
 * address who writes to its owner. The wallet itself, once unlocked, shows who
 * and what.
 *
 * Sent synchronously (not ShouldQueue), like every other notification here:
 * production runs no queue worker, so the relay defers it past the response.
 */
class WalletChatMessageNotification extends Notification
{
    /** Where the wallet opens on its messages. */
    public const URL = '/wallet?section=chat';

    /** Named for the notifiable, because Notification::locale() is taken. */
    private function localeOf(object $notifiable): ?string
    {
        return $notifiable instanceof User || $notifiable instanceof AnalyticsUser
            ? $notifiable->notification_locale
            : null;
    }

    /** @var array<string, string> */
    private const TITLE = [
        'en' => 'New message',
        'ru' => 'Новое сообщение',
        'zh' => '新消息',
    ];

    /** @var array<string, string> */
    private const BODY = [
        'en' => 'Somebody wrote to you in Cyberia Wallet. Open it to read.',
        'ru' => 'Вам написали в Cyberia Wallet. Откройте кошелёк, чтобы прочитать.',
        'zh' => '有人在 Cyberia 钱包里给你发了消息，打开即可查看。',
    ];

    public function titleFor(object $notifiable): string
    {
        return Localised::pick(self::TITLE, $this->localeOf($notifiable));
    }

    public function bodyFor(object $notifiable): string
    {
        return Localised::pick(self::BODY, $this->localeOf($notifiable));
    }

    /**
     * The notice as the bot sends it. In the bot's language rather than the
     * browser's: a Telegram account linked to an address has no browser locale
     * on record, and everything else the bot says is Russian.
     */
    public function telegramText(): string
    {
        return Localised::pick(self::TITLE, 'ru')."\n".Localised::pick(self::BODY, 'ru');
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
            'type' => 'chat.message',
            'title' => $this->titleFor($notifiable),
            'body' => $this->bodyFor($notifiable),
            'url' => self::URL,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->titleFor($notifiable))
            ->body($this->bodyFor($notifiable))
            ->icon('/apple-touch-icon.png')
            // One per device, replaced rather than stacked: three messages are
            // still one thing to go and read.
            ->tag('wallet-chat')
            ->data(['url' => self::URL]);
    }
}
