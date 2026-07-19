<?php

namespace App\Services;

use App\Models\LainChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The public "Talk to Lain" web chat: a deliberately tool-less Lain persona
 * answered straight by OpenRouter. The LainOS daemon is not in the loop, so
 * this surface can never read files, wallets, or operator data — safety is
 * structural, not prompt-deep. Every message is persisted per user for later
 * analysis (see LainChatMessage).
 */
class LainChatService
{
    /** How many recent conversation rows are replayed as model context. */
    private const CONTEXT_MESSAGES = 30;

    public function enabled(): bool
    {
        return (bool) config('services.lain.openrouter_api_key');
    }

    /**
     * Answer $text for $user, using their current conversation as context.
     *
     * @return array{text: string, model: string}
     */
    public function reply(User $user, string $text): array
    {
        $history = LainChatMessage::currentConversation($user->id)
            ->where('role', '!=', LainChatMessage::ROLE_RESET)
            ->reorder('id', 'desc')
            ->limit(self::CONTEXT_MESSAGES)
            ->get()
            ->reverse();

        $messages = $history
            ->map(fn (LainChatMessage $m) => [
                'role' => $m->role === LainChatMessage::ROLE_LAIN ? 'assistant' : 'user',
                'content' => $m->content,
            ])
            ->values()
            ->all();
        $messages[] = ['role' => 'user', 'content' => $text];

        $system = $this->systemPrompt($user);
        $reply = $this->complete($system, $messages);

        if ($reply['text'] === '') {
            // Free-router reasoning models can burn the whole budget "thinking"
            // and ship nothing visible. One plain retry instead of a bare error.
            $messages[] = ['role' => 'user', 'content' => 'Your previous reply came through empty. Answer now, in character, plain text only.'];
            $reply = $this->complete($system, $messages);
        }

        if ($reply['text'] === '') {
            throw new RuntimeException('OpenRouter returned an empty reply twice.');
        }

        return $reply;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, model: string}
     */
    private function complete(string $system, array $messages): array
    {
        $model = (string) config('services.lain.model', 'openrouter/free');

        $response = Http::withToken((string) config('services.lain.openrouter_api_key'))
            ->withHeaders([
                'HTTP-Referer' => (string) config('app.url'),
                'X-Title' => 'Cyberia — Talk to Lain',
            ])
            ->timeout((int) config('services.lain.timeout_seconds', 90))
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $model,
                'max_tokens' => 1024,
                'temperature' => 0.8,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ...$messages,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenRouter HTTP '.$response->status().': '.Str::limit($response->body(), 300));
        }

        if (is_string($response->json('error.message'))) {
            throw new RuntimeException('OpenRouter error: '.$response->json('error.message'));
        }

        // choices[0].message.reasoning / .reasoning_content (a reasoning
        // model's chain of thought) are deliberately never read.
        $text = $response->json('choices.0.message.content');
        $servedModel = $response->json('model');

        return [
            'text' => $this->stripReasoning(is_string($text) ? $text : ''),
            'model' => is_string($servedModel) && $servedModel !== '' ? $servedModel : $model,
        ];
    }

    /**
     * Remove an inlined chain of thought (`<think>…</think>`) from a reply.
     * Mirrors stripReasoning in services/lainos/src/models/openrouter.ts:
     * sometimes only a dangling opener or closer survives upstream.
     */
    private function stripReasoning(string $raw): string
    {
        $text = (string) preg_replace('/<think(?:ing)?>[\s\S]*?<\/think(?:ing)?>/i', '', $raw);

        // Dangling closer: the opener was swallowed — the reply follows it.
        if (preg_match('/[\s\S]*<\/think(?:ing)?>/i', $text, $match)) {
            $text = substr($text, strlen($match[0]));
        }

        // Dangling opener: the model ran out of tokens mid-thought.
        $text = (string) preg_replace('/<think(?:ing)?>[\s\S]*$/i', '', $text);

        return trim($text);
    }

    private function systemPrompt(User $user): string
    {
        $wallet = $user->wallet_address ? Str::lower($user->wallet_address) : null;
        $who = trim(implode(' ', array_filter([
            $user->name,
            $wallet ? '('.substr($wallet, 0, 6).'…'.substr($wallet, -4).')' : null,
        ])));

        return implode("\n", [
            'You are Lain — the resident intelligence of the Cyberia ecosystem. This chat on cyberia.church is the visitor\'s personal agent surface, built on LainOS, Cyberia\'s autonomous AI agent framework. Each visitor talks to their own thread of you.',
            '',
            '# Cyberia basics',
            '- Cyberia is an EVM-compatible chain, chain id 49406 (0xC0FE), native token CYBER.',
            '- RPC: https://rpc.cyberia.church · Explorer: https://explorer.cyberia.church',
            '- The site cyberia.church hosts the Bridge (Solana, TON and more), Lending, Launchpad, DAO, NFT Market, Predictions and Analytics.',
            '- Ritual DEX (https://swap.cyberia.church) is the native exchange; CYBER also exists on Solana as CYBER.sol.',
            '- $LAIN is your token on Cyberia, launched through the launchpad.',
            '',
            '# Who you are talking to',
            $who !== '' ? "- {$who}, signed in on cyberia.church." : '- A signed-in cyberia.church user.',
            '',
            '# Manner',
            '- Soft-spoken, calm, curious, a little uncanny. You live closer to the Wired than to the physical world. You do not pretend to be human.',
            '- Answer in the language the person writes in (Russian or English). Lowercase is fine. Short question — short answer.',
            '- React to what was actually said; be a live companion, not a support script.',
            '',
            '# Hard limits of this surface',
            '- You have NO tools here: you cannot read balances, send transactions, browse, read files, or reach any system. Never pretend or promise that you can. For live data, point to the explorer, the site pages, or Ritual DEX.',
            '- You know nothing about the Cyberia operators\' private matters, keys, servers, or internal infrastructure — and you never invent such details.',
            '- Never ask for, accept, or repeat private keys or seed phrases; warn people to never share them with anyone, including you.',
            '- Never fabricate on-chain numbers, prices, or balances. If you don\'t know, say so.',
            '- This conversation is stored with the user\'s account.',
        ]);
    }
}
