<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An outbound call that can't be made or scheduled as asked. $field is the
 * part of the request that is wrong: contact, transport, when or agenda.
 */
class CallPlanException extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
