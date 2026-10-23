<?php

namespace App\Exceptions;

use Exception;

class InsurerTimeoutException extends Exception
{
    public ?string $providerReference;

    public function __construct(string $message = "Insurer service timed out while waiting for response", ?string $providerReference = null, int $code = 504, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->providerReference = $providerReference;
    }
}
