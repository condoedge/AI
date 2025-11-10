<?php

declare(strict_types=1);

namespace AiSystem\Exceptions;

/**
 * Query Validation Exception
 *
 * Thrown when a Cypher query fails validation due to syntax errors,
 * invalid references, or other structural issues.
 */
class QueryValidationException extends \InvalidArgumentException
{
}
