<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * Anything the inference API refuses or cannot deliver.
 *
 * The shape it renders is OpenAI's error envelope, because the whole point of
 * this surface is that an existing OpenAI client can be pointed at it: a
 * client that understands `error.type` and `error.code` from one host should
 * not have to learn a second dialect for ours.
 *
 * `type` is the coarse family the caller branches on (invalid_request_error,
 * authentication_error, permission_error, rate_limit_error, api_error) and
 * `code` is the specific reason inside it.
 */
class AiApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        // Not `$code`: Exception already owns that name, as an int.
        public readonly string $errorCode,
        string $message,
        public readonly ?string $param = null,
        /** Seconds the caller should wait; only ever set on rate limits. */
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function invalidRequest(string $message, ?string $param = null, string $code = 'invalid_request'): self
    {
        return new self(400, 'invalid_request_error', $code, $message, $param);
    }

    public static function unauthorized(string $message, string $code = 'invalid_api_key'): self
    {
        return new self(401, 'authentication_error', $code, $message);
    }

    public static function forbidden(string $message, string $code = 'insufficient_holding'): self
    {
        return new self(403, 'permission_error', $code, $message);
    }

    public static function rateLimited(string $message, int $retryAfter, string $code = 'rate_limit_exceeded'): self
    {
        return new self(429, 'rate_limit_error', $code, $message, retryAfter: $retryAfter);
    }

    public static function upstream(string $message, string $code = 'upstream_error', int $status = 502, ?Throwable $previous = null): self
    {
        return new self($status, 'api_error', $code, $message, previous: $previous);
    }

    public function toResponse(): JsonResponse
    {
        $response = new JsonResponse([
            'error' => [
                'message' => $this->getMessage(),
                'type' => $this->type,
                'code' => $this->errorCode,
                'param' => $this->param,
            ],
        ], $this->status);

        return $this->retryAfter === null
            ? $response
            : $response->withHeaders(['Retry-After' => (string) $this->retryAfter]);
    }
}
