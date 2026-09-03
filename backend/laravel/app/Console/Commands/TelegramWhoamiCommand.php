<?php

namespace App\Console\Commands;

use App\Services\TelegramOpsNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Which chats this bot can write to, and their numeric ids.
 *
 * Telegram resolves `@name` for channels and never for a private chat, so
 * reaching a person needs their numeric id, and that id exists only once they
 * have started the bot. Both facts are invisible from the outside: an operator
 * added to the config by handle simply never receives anything, with no error
 * anywhere. This turns that into a list you can read.
 */
#[Signature('telegram:whoami')]
#[Description('List the chats that have written to the ops bot, with their ids')]
class TelegramWhoamiCommand extends Command
{
    public function handle(TelegramOpsNotifier $telegram): int
    {
        $configured = $telegram->recipients(TelegramOpsNotifier::OPS);
        $analytics = $telegram->recipients(TelegramOpsNotifier::ANALYTICS);

        $this->line('Alerts go to:  '.($configured === [] ? 'nobody' : implode(', ', $configured)));
        $this->line('Reports go to: '.($analytics === [] ? 'nobody' : implode(', ', $analytics)));
        $this->newLine();

        $chats = $telegram->knownChats();

        if ($chats === []) {
            $this->warn('No chats seen.');
            $this->line('  getUpdates only returns recent, unconsumed updates, and returns nothing');
            $this->line('  at all while a webhook is set. Ask the person to send the bot any message,');
            $this->line('  then run this again.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'name', 'type', 'configured'],
            array_map(fn (array $chat): array => [
                $chat['id'],
                $chat['name'],
                $chat['type'],
                in_array($chat['id'], $analytics, true) ? 'yes' : '',
            ], $chats),
        );

        return self::SUCCESS;
    }
}
