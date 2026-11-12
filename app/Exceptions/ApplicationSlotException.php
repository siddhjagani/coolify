<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A deployment slot cannot be created or swapped. The message tells the user why, and the status is the HTTP status
 * of the API response.
 */
class ApplicationSlotException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
