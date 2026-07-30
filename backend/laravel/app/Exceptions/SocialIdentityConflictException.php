<?php

namespace App\Exceptions;

use App\Enums\SocialProvider;
use Exception;

class SocialIdentityConflictException extends Exception
{
    public static function alreadyLinked(SocialProvider|string $provider): self
    {
        $label = $provider instanceof SocialProvider ? $provider->label() : $provider;

        return new self("A different {$label} account is already linked to your profile.");
    }

    public static function alreadyOwned(SocialProvider|string $provider): self
    {
        $label = $provider instanceof SocialProvider ? $provider->label() : $provider;

        return new self("This {$label} account is already linked to another user.");
    }

    public static function invalidIntent(): self
    {
        return new self('This account-linking request expired or belongs to another session. Please try again.');
    }
}
