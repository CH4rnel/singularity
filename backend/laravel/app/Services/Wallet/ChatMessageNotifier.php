<?php

namespace App\Services\Wallet;

use App\Models\AnalyticsUser;
use App\Models\User;
use App\Notifications\WalletChatMessageNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tell an address that somebody wrote to it.
 *
 * A message nobody hears about is a message nobody reads: the wallet only
 * checks its mail while it is open, and most people here open it a few times
 * a week. So the relay, which knows who a message is addressed to whether or
 * not it can read it, tells every route back to that address it has:
 *
 *  - the site accounts whose wallet it is — the bell, and push if they have it;
 *  - the wallet installations that hold it and allowed push;
 *  - the Telegram accounts linked to it, through the outbox the bot drains.
 *
 * At most once per sender per window (`wallet.chat.notify_every_minutes`):
 * five messages in a row are one reason to come back, not five. Delivery is
 * per route and one failure never stops the rest — a push service drops
 * endpoints without telling anybody.
 */
class ChatMessageNotifier
{
    public function notify(string $from, string $to): void
    {
        $from = Str::lower($from);
        $to = Str::lower($to);

        // Writing to yourself is a note, not news.
        if ($from === $to) {
            return;
        }

        $window = max(1, (int) config('wallet.chat.notify_every_minutes', 10));

        if (! Cache::add("wallet-chat-notified:{$to}:{$from}", true, now()->addMinutes($window))) {
            return;
        }

        $notification = new WalletChatMessageNotification;

        foreach ($this->recipients($to) as $recipient) {
            try {
                $recipient->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->queueTelegram($to, $notification);
    }

    /**
     * Accounts and installations that answer to this address.
     *
     * @return list<User|AnalyticsUser>
     */
    private function recipients(string $address): array
    {
        $accounts = User::query()
            ->whereNull('merged_into_id')
            ->whereRaw('lower(wallet_address) = ?', [$address])
            ->get()
            ->all();

        $installations = AnalyticsUser::query()
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('analytics_addresses')
                ->whereColumn('analytics_addresses.user_id', 'analytics_users.id')
                ->whereRaw('lower(analytics_addresses.address) = ?', [$address]))
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('push_subscriptions')
                ->whereColumn('push_subscriptions.subscribable_id', 'analytics_users.id')
                ->where('push_subscriptions.subscribable_type', AnalyticsUser::class))
            ->get()
            ->all();

        return [...$accounts, ...$installations];
    }

    /**
     * The same notice, left in the outbox the bot drains.
     *
     * The bot skips anybody who switched its notifications off (`/remind off`),
     * so that choice is honoured there rather than duplicated here.
     */
    private function queueTelegram(string $address, WalletChatMessageNotification $notification): void
    {
        if (! Schema::hasTable('tg_outbox')) {
            return;
        }

        $telegramIds = User::query()
            ->whereNull('merged_into_id')
            ->whereRaw('lower(wallet_address) = ?', [$address])
            ->whereNotNull('telegram_id')
            ->pluck('telegram_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (Schema::hasTable('tg_wallets')) {
            $telegramIds = [...$telegramIds, ...DB::table('tg_wallets')
                ->whereRaw('lower(address) = ?', [$address])
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id)
                ->all()];
        }

        $text = $notification->telegramText();

        foreach (array_unique($telegramIds) as $telegramId) {
            DB::table('tg_outbox')->insert([
                'tg_user_id' => $telegramId,
                'kind' => 'chat.message',
                'text' => $text,
                'url' => WalletChatMessageNotification::URL,
                'created_at' => Carbon::now('UTC')->toDateTimeString(),
            ]);
        }
    }
}
