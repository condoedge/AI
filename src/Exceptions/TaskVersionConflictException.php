<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Thrown when a task is saved from a version that is no longer the stored one: someone
 * else changed the task since it was read, and nothing was written.
 */
class TaskVersionConflictException extends \RuntimeException
{
}
