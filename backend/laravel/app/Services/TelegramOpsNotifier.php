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
 */
class TelegramOpsNotifier
{
    private const API = 'https://api.telegram.org';

    public function configured(): bool
    {
        return $this->token() !== '' && $this->chatId() !== '';
    }

    /**
     * Send one message. Returns false when unconfigured or when Telegram
     * refused it — never throws, because no alert is worth failing the job
     * that was trying to report success.
     */
    public function send(string $text): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            $response = Http::timeout(10)->post(
                self::API.'/bot'.$this->token().'/sendMessage',
                [
                    'chat_id' => $this->chatId(),
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ],
            );

            if ($response->failed()) {
                Log::warning('Telegram ops alert refused', [
                    'status' => $response->status(),
                    // The body names the field Telegram disliked; it carries no
                    // token, and the token is never logged.
                    'body' => $response->json('description'),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Telegram ops alert failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function token(): string
    {
        return (string) config('services.telegram_ops.bot_token', '');
    }

    private function chatId(): string
    {
        return (string) config('services.telegram_ops.chat_id', '');
    }
}
