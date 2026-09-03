<?php

namespace App\Console\Commands;

use App\Services\Analytics\ProductDigest;
use App\Services\TelegramOpsNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The daily product report, delivered rather than published.
 *
 * `/crm/numbers` has answered these questions for weeks and the operators do
 * not open it, which is the same failure the quest system has: a thing you
 * must remember to go to is a thing you stop going to. So the report comes to
 * the phone instead, once a day, to everyone who is supposed to act on it.
 *
 * Prints by default and sends only with `--send`, because a command that
 * messages two people every time somebody runs it to see what it looks like
 * is a command people stop running.
 */
#[Signature('analytics:digest {--days=1 : Window in days} {--send : Deliver it to the operators over Telegram}')]
#[Description('Compose the product report and optionally send it to the operators')]
class AnalyticsDigestCommand extends Command
{
    public function handle(ProductDigest $digest, TelegramOpsNotifier $telegram): int
    {
        $days = max(1, min((int) $this->option('days'), 365));

        $this->line($digest->toText($days));
        $this->newLine();

        if (! $this->option('send')) {
            $this->comment('Not sent. Add --send to deliver it.');

            return self::SUCCESS;
        }

        $recipients = $telegram->recipients(TelegramOpsNotifier::ANALYTICS);

        if ($recipients === []) {
            $this->warn('Nobody to send to: TELEGRAM_ANALYTICS_CHAT_ID and TELEGRAM_OPS_CHAT_ID are both unset.');
            $this->line('  Run `php artisan telegram:whoami` to read an operator id off the bot.');

            return self::SUCCESS;
        }

        // Per recipient rather than through send(), so the run says which
        // operator did not get it. A person who has not started the bot is the
        // expected reason, and it is invisible in a single boolean.
        $failed = [];

        foreach ($recipients as $chatId) {
            if ($telegram->sendTo($chatId, $digest->toTelegram($days))) {
                $this->info('Sent to '.$chatId);

                continue;
            }

            $failed[] = $chatId;
            $this->warn('Refused by Telegram for '.$chatId.' — has that account started the bot?');
        }

        return $failed === $recipients ? self::FAILURE : self::SUCCESS;
    }
}
