<?php

namespace App\Enums;

enum SocialProvider: string
{
    case GitHub = 'github';
    case Telegram = 'telegram';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::Telegram => 'Telegram',
        };
    }

    public function idColumn(): string
    {
        return $this->value.'_id';
    }

    public function usernameColumn(): string
    {
        return $this->value.'_username';
    }

    public function linkedStatus(): string
    {
        return $this->label().' account linked.';
    }
}
