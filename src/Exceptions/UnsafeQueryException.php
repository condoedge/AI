<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Unsafe Query Exception
 *
 * Thrown when a query contains dangerous operations like DELETE, DROP,
 * or other operations that could modify or destroy data.
 */
class UnsafeQueryException extends \RuntimeException
{
}
