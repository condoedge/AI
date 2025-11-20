<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Exception thrown when potential Cypher injection is detected
 */
class CypherInjectionException extends \InvalidArgumentException
{
}
