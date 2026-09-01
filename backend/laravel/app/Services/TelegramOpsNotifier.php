<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One-way operator alerts over Telegram.
 *
 * Deliberately not a bot: it receives nothing, has no commands and no state.
 * Conversations with users are the Python bot's job (services/telegram-bot);
 * this exists so a scheduled command can say "a market closed and only a
 * person can settle it" to somebody who can act on it.
 *
 * Unconfigured is a normal state, not an error — a deploy without a token
 * still resolves markets, it just cannot tell anyone about the ones it
 * couldn't. Callers get false and log for themselves.
 *
 * There is more than one operator, so every channel is a *list*. Two rules
 * follow from that and both are load-bearing:
 *
 *  - `send()` is true when **at least one** recipient accepted the message.
 *    Callers stamp `notified_at` off that return (`ServiceMonitor`), and an
 *    incident that re-alerts every five minutes because the second operator
 *    has not pressed Start on the bot yet is a worse failure than the one it
 *    is reporting. Refusals are logged per recipient instead.
 *  - Telegram only resolves `@name` for channels, never for a private chat, so
 *    a person is a numeric id and that id exists only once they have started
 *    the bot. `telegram:whoami` reads it off getUpdates rather than asking an
 *    operator to find it themselves.
 */
class TelegramOpsNotifier
{
    private const API = 'https://api.telegram.org';

    /** Alerts: something is broken and a person has to act. */
    public const OPS = 'ops';

    /** Reports: nothing is wrong, this is what happened. */
    public const ANALYTICS = 'analytics';

    public function configured(string $channel = self::OPS): bool
    {
        return $this->token() !== '' && $this->recipients($channel) !== [];
    }

    /**
     * Who this channel reaches. Config holds one string because .env holds
     * strings; a list separated by commas, spaces or newlines is the shape an
     * operator actually types.
     *
     * @return array<int, string>
     */
    public function recipients(string $channel = self::OPS): array
    {
        $raw = (string) config('services.telegram_ops.chat_id', '');

        if ($channel === self::ANALYTICS) {
            $analytics = (string) config('services.telegram_ops.analytics_chat_id', '');

            // Unset means "wherever the alerts go". An operator who wants the
            // daily report to reach more people than the incidents says so;
            // one who says nothing gets the behaviour they already had.
            $raw = trim($analytics) === '' ? $raw : $analytics;
        }

        $ids = preg_split('/[\s,;]+/', trim($raw)) ?: [];

        return array_values(array_unique(array_filter(
            array_map('trim', $ids),
            fn (string $id): bool => $id !== '',
        )));
    }

    /**
     * Send one message to every recipient of a channel. Returns true when at
     * least one of them accepted it — never throws, because no alert is worth
     * failing the job that was trying to report success.
     */
    public function send(string $text, string $channel = self::OPS): bool
    {
        if ($this->token() === '') {
            return false;
        }

        $delivered = false;

        foreach ($this->recipients($channel) as $chatId) {
            $delivered = $this->sendTo($chatId, $text) || $delivered;
        }

        return $delivered;
    }

    /**
     * Send to one chat. Telegram caps a message at 4096 characters and refuses
     * the whole thing when it is longer, so a long report is split on line
     * boundaries rather than truncated — a digest that silently loses its last
     * section is the kind of missing number nobody goes looking for.
     */
    public function sendTo(string $chatId, string $text): bool
    {
        if ($this->token() === '' || trim($chatId) === '') {
            return false;
        }

        foreach ($this->chunk($text) as $part) {
            if (! $this->post($chatId, $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split a message into Telegram-sized parts on line boundaries.
     *
     * @return array<int, string>
     */
    private function chunk(string $text, int $limit = 3800): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $parts = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            // A single line longer than the limit cannot be split on a
            // boundary that does not exist; hand it over whole and let
            // Telegram be the one to complain about it.
            if ($current !== '' && mb_strlen($current) + mb_strlen($line) + 1 > $limit) {
                $parts[] = $current;
                $current = '';
            }

            $current = $current === '' ? $line : $current."\n".$line;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private function post(string $chatId, string $text): bool
    {
        try {
            $response = Http::timeout(10)->post(
                self::API.'/bot'.$this->token().'/sendMessage',
                [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ],
            );

            if ($response->failed()) {
                Log::warning('Telegram ops alert refused', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    // The body names the field Telegram disliked; it carries no
                    // token, and the token is never logged.
                    'body' => $response->json('description'),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Telegram ops alert failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Chats that have spoken to this bot, so an operator id can be read off
     * the wire instead of hunted for. Used by `telegram:whoami`.
     *
     * @return array<int, array{id: string, name: string, type: string}>
     */
    public function knownChats(): array
    {
        if ($this->token() === '') {
            return [];
        }

        try {
            $response = Http::timeout(15)->get(
                self::API.'/bot'.$this->token().'/getUpdates',
                ['limit' => 100, 'allowed_updates' => '["message"]'],
            );

            if ($response->failed()) {
                return [];
            }

            $chats = [];

            foreach ((array) $response->json('result', []) as $update) {
                $chat = $update['message']['chat'] ?? null;

                if (! is_array($chat) || ! isset($chat['id'])) {
                    continue;
                }

                $name = trim(implode(' ', array_filter([
                    $chat['title'] ?? null,
                    $chat['first_name'] ?? null,
                    $chat['last_name'] ?? null,
                ])));

                $chats[(string) $chat['id']] = [
                    'id' => (string) $chat['id'],
                    'name' => isset($chat['username']) ? '@'.$chat['username'] : ($name ?: '—'),
                    'type' => (string) ($chat['type'] ?? '—'),
                ];
            }

            return array_values($chats);
        } catch (\Throwable $e) {
            Log::warning('Telegram getUpdates failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function token(): string
    {
        return (string) config('services.telegram_ops.bot_token', '');
    }
}
