<?php

declare(strict_types=1);

namespace Condoedge\Ai\LlmProviders;

use Exception;

/**
 * Marks a transient LLM API failure (HTTP 429 / 5xx or a network-level error)
 * that is safe to retry. The RetryPolicy is configured to retry ONLY this
 * exception type, so permanent failures (400/401/404/422) bubble up immediately.
 */
class TransientLlmException extends Exception
{
}
