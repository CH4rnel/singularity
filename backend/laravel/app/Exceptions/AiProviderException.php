<?php

namespace App\Exceptions;

use Throwable;

/**
 * An upstream provider (OpenRouter, Groq) refused or failed a call.
 *
 * It is an AiApiException so it renders to the caller in the same envelope as
 * everything else, and carries the two extra facts the gateway needs to decide
 * what to do next: which provider and model failed, and whether the failure is
 * the kind another model could survive.
 *
 * `allowsFallback` is deliberately narrow. A rate limit, a timeout, a 5xx or a
 * model that has vanished are all worth retrying elsewhere; a rejected prompt
 * or a bad key are not — the second attempt would fail identically and only
 * cost the caller another wait.
 */
class AiProviderException extends AiApiException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $upstreamModel,
        public readonly int $upstreamStatus,
        public readonly bool $allowsFallback,
        int $status,
        string $type,
        string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($status, $type, $errorCode, $message, previous: $previous);
    }
}
