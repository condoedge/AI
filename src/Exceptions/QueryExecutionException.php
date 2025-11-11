<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Query Execution Exception
 *
 * Thrown when a Cypher query fails to execute properly,
 * including syntax errors, connection issues, or other runtime errors.
 */
class QueryExecutionException extends \RuntimeException
{
}
