<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Exception thrown when a circuit breaker is open and rejects requests
 */
class CircuitBreakerOpenException extends \RuntimeException
{
}
