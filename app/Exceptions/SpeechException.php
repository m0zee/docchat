<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Speech to text or text to speech failed. Retryable means busy or rate
 * limited, so the caller can say "try again in a minute".
 */
class SpeechException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function busy(string $message = 'Speech recognition is busy, try again in a minute.'): self
    {
        return new self($message, retryable: true);
    }
}
