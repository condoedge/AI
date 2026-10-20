<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

use Exception;

/**
 * The provider REFUSED the request itself (HTTP 400): a parameter or a payload it
 * does not accept — never an outage or a rate limit, which are transient.
 *
 * Still a plain \Exception with the historical message, so existing catch sites are
 * unaffected; a caller that sent an OPTIONAL request feature (chatJson's strict
 * output schema) can tell "this model does not take it" from every other failure
 * and make the call again without it.
 */
class LlmBadRequestException extends Exception
{
}
