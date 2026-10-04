<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OpenRouterException;
use App\Http\Controllers\Controller;
use App\Services\LainChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lain on Cyberia's IRC.
 *
 * The bot in services/irc/lain-bot sits in the channels and forwards the lines
 * addressed to her; this answers with LainChatService — the same tool-less
 * persona as /lain and the wallet, never the LainOS daemon, which is the
 * operator's personal agent with a wallet and a shell.
 *
 * The bot is the only legitimate caller, so the gate is the heartbeat's: a
 * shared token compared in constant time, and no token means 404. Without it
 * this would be an open, anonymous pipe into a paid model.
 */
class IrcLainController extends Controller
{
    /** Channel lines the bot may replay as context. */
    public const CONTEXT_MESSAGES = 20;

    /** One IRC line is 512 bytes; a pasted burst of them is still small. */
    public const MAX_TEXT_CHARS = 2000;

    public function __invoke(Request $request, LainChatService $lain): JsonResponse
    {
        $token = (string) config('services.lain.irc_token', '');

        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Irc-Token'))) {
            abort(404);
        }

        $data = $request->validate([
            // A channel (#name) or, for a private query, the sender's nick.
            'target' => ['required', 'string', 'max:64', 'regex:/^[^\s,]+$/'],
            'nick' => ['required', 'string', 'max:32', 'regex:/^[^\s,]+$/'],
            'text' => ['required', 'string', 'max:'.self::MAX_TEXT_CHARS],
            'history' => ['sometimes', 'array', 'max:'.self::CONTEXT_MESSAGES],
            'history.*.nick' => ['required', 'string', 'max:32'],
            'history.*.text' => ['required', 'string', 'max:'.self::MAX_TEXT_CHARS],
        ]);

        if (! $lain->enabled()) {
            return response()->json(['message' => 'Lain is not wired up on this server.'], 503);
        }

        // Her own earlier lines go back as hers; everybody else's as `<nick> text`,
        // so a channel of several voices stays several voices.
        $history = array_map(
            fn (array $line): array => strtolower($line['nick']) === 'lain'
                ? ['role' => 'assistant', 'content' => (string) $line['text']]
                : ['role' => 'user', 'content' => "<{$line['nick']}> {$line['text']}"],
            array_slice($data['history'] ?? [], -self::CONTEXT_MESSAGES),
        );

        try {
            $reply = $lain->replyForIrc($data['target'], $data['nick'], array_values($history), trim($data['text']));
        } catch (OpenRouterException $exception) {
            Log::warning('IRC Lain failed', ['error' => $exception->getMessage()]);

            return response()->json(['message' => 'unreachable'], 503);
        } catch (Throwable $exception) {
            Log::warning('IRC Lain failed', ['error' => $exception->getMessage()]);

            return response()->json(['message' => 'unreachable'], 503);
        }

        return response()->json(['text' => $reply['text'], 'model' => $reply['model']]);
    }
}
