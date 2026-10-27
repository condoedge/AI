<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * A task that says, under an answer whose start chip offers it, what the assistant can do here and
 * what to give it ("I can send the invitation for you here: give me the person's email and role.").
 *
 * Optional, next to TaskDefinitionInterface. Without it the package's `ai.tasks.offer` line names
 * the task by its label. The user's reply to the line may start the task (TaskOffer).
 */
interface TaskOfferInterface
{
    /** The offer sentence: a translation key or plain text. */
    public function offer(): string;
}
