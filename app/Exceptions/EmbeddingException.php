<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class EmbeddingException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly bool $retryable = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function permanent(string $message): self
    {
        return new self($message, retryable: false);
    }
}
