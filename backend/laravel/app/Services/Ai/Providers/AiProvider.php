<?php

namespace App\Services\Ai\Providers;

use Generator;

/**
 * One upstream that can answer a chat completion.
 *
 * Everything behind this interface speaks the OpenAI chat-completions dialect,
 * which is why the API in front of it can be OpenAI-compatible without
 * translating anything: the payload the caller sent is (after validation and
 * capping) the payload that goes upstream.
 */
interface AiProvider
{
    /** Registry name, e.g. `groq`. Reported back on responses and errors. */
    public function key(): string;

    /** Whether this provider has a key on this server. */
    public function configured(): bool;

    /**
     * One completion.
     *
     * @param  array<string, mixed>  $payload  OpenAI-shaped body, without `model`.
     * @return array<string, mixed> The provider's decoded response.
     */
    public function chat(string $upstreamModel, array $payload): array;

    /**
     * The same call, streamed.
     *
     * Yields the decoded object from each `data:` frame in upstream order and
     * stops before the terminating `[DONE]`, which the caller re-emits itself.
     *
     * @param  array<string, mixed>  $payload  OpenAI-shaped body, without `model`.
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(string $upstreamModel, array $payload): Generator;
}
