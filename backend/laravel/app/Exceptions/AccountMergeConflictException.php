<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when two accounts being merged both hold different non-null
 * values for the same identity field (e.g. each already has a distinct
 * EVM wallet) — an irreconcilable conflict that must abort the whole
 * merge rather than silently pick a winner.
 */
class AccountMergeConflictException extends Exception
{
    private const MESSAGES = [
        'wallet_address' => 'Your account already has a different EVM wallet linked. These accounts could not be merged automatically — contact support.',
        'solana_wallet_address' => 'Your account already has a different Solana wallet linked. These accounts could not be merged automatically — contact support.',
        'twitter_id' => 'Your account already has a different X account linked. These accounts could not be merged automatically — contact support.',
        'github_id' => 'Your account already has a different GitHub account linked. These accounts could not be merged automatically — contact support.',
        'telegram_id' => 'Your account already has a different Telegram account linked. These accounts could not be merged automatically — contact support.',
    ];

    private function __construct(string $message, private readonly string $field)
    {
        parent::__construct($message);
    }

    public static function forField(string $field): self
    {
        return new self(self::MESSAGES[$field] ?? 'These accounts could not be merged automatically — contact support.', $field);
    }

    public static function alreadyMerged(): self
    {
        return new self('This account was already merged into another profile. Please sign in again.', 'merged_into_id');
    }

    public function field(): string
    {
        return $this->field;
    }
}
