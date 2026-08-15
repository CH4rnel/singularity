<?php

namespace App\Services\Ai;

use App\Exceptions\AiApiException;

/**
 * One caller's chat-completions body, checked and capped.
 *
 * The API is a passthrough in shape but not in trust: the body arrives from
 * the internet and is spent against Cyberia's upstream account, so everything
 * that reaches a provider passes through here first.
 *
 * Two rules decide what happens to an unknown field. Tuning parameters this
 * server understands are forwarded verbatim; anything else is dropped rather
 * than passed on, because a field we do not understand is a field we cannot
 * bound. `max_tokens` is clamped instead of rejected — a caller asking for
 * more output than the quota allows wants an answer, not an error.
 */
class AiChatPayload
{
    /**
     * Request fields forwarded upstream untouched.
     *
     * Sampling and formatting knobs only: nothing here changes who pays, how
     * long the call runs, or which model answers.
     */
    private const PASSTHROUGH = [
        'temperature',
        'top_p',
        'stop',
        'presence_penalty',
        'frequency_penalty',
        'seed',
        'response_format',
        'tools',
        'tool_choice',
        'parallel_tool_calls',
        'logprobs',
        'top_logprobs',
        'n',
        'reasoning_effort',
        'stream_options',
    ];

    /** Message fields that survive; a role outside this list is refused. */
    private const ROLES = ['system', 'developer', 'user', 'assistant', 'tool'];

    private function __construct(
        /** @var array<string, mixed> */
        public readonly array $body,
        public readonly bool $stream,
        public readonly int $inputChars,
    ) {}

    /**
     * @param  array<string, mixed>  $input  The decoded request body.
     */
    public static function fromRequest(array $input): self
    {
        $messages = self::messages($input['messages'] ?? null);

        $body = ['messages' => $messages];

        foreach (self::PASSTHROUGH as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null) {
                $body[$field] = $input[$field];
            }
        }

        $maxOutput = self::maxOutputTokens($input);

        if ($maxOutput !== null) {
            // Both spellings exist in the wild (`max_tokens` is the older one,
            // `max_completion_tokens` the current). Send the older: every
            // provider this API talks to still honours it.
            $body['max_tokens'] = $maxOutput;
        }

        return new self(
            body: $body,
            stream: filter_var($input['stream'] ?? false, FILTER_VALIDATE_BOOL),
            inputChars: self::countChars($messages),
        );
    }

    /**
     * @param  mixed  $raw
     * @return list<array<string, mixed>>
     */
    private static function messages($raw): array
    {
        if (! is_array($raw) || $raw === []) {
            throw AiApiException::invalidRequest('`messages` must be a non-empty array.', 'messages');
        }

        $limit = (int) config('ai.limits.max_messages', 200);

        if (count($raw) > $limit) {
            throw AiApiException::invalidRequest(
                "This API accepts at most {$limit} messages in one request.",
                'messages',
                'too_many_messages',
            );
        }

        $messages = [];

        foreach (array_values($raw) as $index => $message) {
            if (! is_array($message)) {
                throw AiApiException::invalidRequest("messages[{$index}] must be an object.", 'messages');
            }

            $role = is_string($message['role'] ?? null) ? $message['role'] : '';

            if (! in_array($role, self::ROLES, true)) {
                throw AiApiException::invalidRequest(
                    sprintf('messages[%d].role must be one of: %s.', $index, implode(', ', self::ROLES)),
                    'messages',
                );
            }

            $content = $message['content'] ?? null;

            // Content may be absent only on an assistant turn that is nothing
            // but tool calls — every other role must actually say something.
            if (! is_string($content) && ! is_array($content)) {
                $hasToolCalls = $role === 'assistant' && is_array($message['tool_calls'] ?? null);

                if (! $hasToolCalls) {
                    throw AiApiException::invalidRequest(
                        "messages[{$index}].content must be a string or an array of content parts.",
                        'messages',
                    );
                }
            }

            $kept = ['role' => $role];

            foreach (['content', 'name', 'tool_call_id', 'tool_calls'] as $field) {
                if (array_key_exists($field, $message) && $message[$field] !== null) {
                    $kept[$field] = $message[$field];
                }
            }

            $messages[] = $kept;
        }

        $chars = self::countChars($messages);
        $maxChars = (int) config('ai.limits.max_input_chars', 120000);

        if ($chars > $maxChars) {
            throw AiApiException::invalidRequest(
                "This request is {$chars} characters of input; the limit is {$maxChars}.",
                'messages',
                'input_too_long',
            );
        }

        return $messages;
    }

    /**
     * The output ceiling for this call: what the caller asked for, or the
     * server cap, whichever is smaller.
     *
     * @param  array<string, mixed>  $input
     */
    private static function maxOutputTokens(array $input): ?int
    {
        $cap = (int) config('ai.limits.max_output_tokens', 4096);
        $asked = $input['max_completion_tokens'] ?? $input['max_tokens'] ?? null;

        if ($asked === null) {
            return $cap > 0 ? $cap : null;
        }

        if (! is_numeric($asked) || (int) $asked < 1) {
            throw AiApiException::invalidRequest(
                '`max_tokens` must be a positive integer.',
                'max_tokens',
            );
        }

        return $cap > 0 ? min((int) $asked, $cap) : (int) $asked;
    }

    /**
     * Characters of prompt in a message list.
     *
     * Text parts of a multimodal message count; an image URL or a base64 blob
     * counts as its own length too, since it is what actually travels.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    private static function countChars(array $messages): int
    {
        $total = 0;

        array_walk_recursive($messages, function ($value) use (&$total): void {
            if (is_string($value)) {
                $total += mb_strlen($value);
            }
        });

        return $total;
    }
}
