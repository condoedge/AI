<?php

declare(strict_types=1);

namespace AiSystem\Exceptions;

/**
 * Query Generation Exception
 *
 * Thrown when query generation fails after retries or encounters
 * unrecoverable errors during the generation process.
 */
class QueryGenerationException extends \RuntimeException
{
}
