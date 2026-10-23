<?php

namespace App\Exceptions;

use Exception;

class InsurerTemporaryFailureException extends Exception
{
    public function __construct(string $message = "Insurer service temporarily unavailable", int $code = 503, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
