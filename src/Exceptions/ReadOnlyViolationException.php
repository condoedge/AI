<?php

declare(strict_types=1);

namespace AiSystem\Exceptions;

/**
 * Read Only Violation Exception
 *
 * Thrown when a query attempts to perform write operations
 * (CREATE, DELETE, MERGE, SET) while in read-only mode.
 */
class ReadOnlyViolationException extends \RuntimeException
{
}
