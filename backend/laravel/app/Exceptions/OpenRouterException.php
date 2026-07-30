<?php

namespace App\Exceptions;

use RuntimeException;

class OpenRouterException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $model,
        public readonly string $category,
        public readonly bool $allowsFallback,
    ) {
        parent::__construct("OpenRouter HTTP {$status} ({$category}) for {$model}.");
    }
}
