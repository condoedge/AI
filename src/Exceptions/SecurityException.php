<?php
// src/Exceptions/SecurityException.php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Exception thrown when AI security checks fail.
 */
class SecurityException extends \Exception
{
    public function __construct(string $message, int $code = 403, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
