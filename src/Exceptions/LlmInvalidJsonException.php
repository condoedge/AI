<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

use Exception;

/**
 * A chatJson reply that did not decode as JSON.
 *
 * Still a plain \Exception with the historical message, so existing catch
 * sites are unaffected; callers that care can inspect the raw reply and
 * whether the provider reported the reply was cut at the output budget
 * (OpenAI finish_reason=length / Anthropic stop_reason=max_tokens) — the
 * signal a caller needs to retry with a larger budget (Task 1.17).
 */
class LlmInvalidJsonException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $rawText = '',
        public readonly bool $truncated = false,
    ) {
        parent::__construct($message);
    }
}
