<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

/**
 * ResponseTaskSection
 *
 * Final task instruction for the LLM.
 * Priority: 80
 */
class ResponseTaskSection extends BaseResponseSection
{
    protected string $name = 'task';
    protected int $priority = 80;

    private ?string $customTask = null;

    /**
     * Set custom task instruction
     */
    public function setTask(string $task): self
    {
        $this->customTask = $task;
        return $this;
    }

    public function format(array $context, array $options = []): string
    {
        if ($this->customTask !== null) {
            return $this->customTask;
        }

        return "Generate response:";
    }
}
