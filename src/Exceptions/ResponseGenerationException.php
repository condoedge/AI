<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Response Generation Exception
 *
 * Thrown when final response generation fails. Extends RuntimeException so every
 * existing catch site (which caught the previous bare \RuntimeException wrap) is
 * unaffected.
 */
class ResponseGenerationException extends \RuntimeException
{
}
