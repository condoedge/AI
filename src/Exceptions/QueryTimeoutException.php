<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Query Timeout Exception
 *
 * Thrown when a query exceeds the maximum allowed execution time.
 * This helps prevent long-running queries from blocking the system.
 */
class QueryTimeoutException extends \RuntimeException
{
}
