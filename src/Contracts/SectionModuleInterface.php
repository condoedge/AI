<?php

namespace Condoedge\Ai\Contracts;

interface SectionModuleInterface 
{
    /**
     * Get the unique name of this module
     *
     * @return string Module name
     */
    public function getName(): string;

    /**
     * Get the priority of this module
     *
     * Lower numbers are processed first.
     *
     * @return int Priority (lower = earlier)
     */
    public function getPriority(): int;

    /**
     * Which prompt role this module's content belongs to.
     *
     * 'system' content (identity, security policy, static rules) is delivered in
     * the real system message so the model treats it as non-negotiable framing.
     * 'user' content (context, the spotlighted question, data) is delivered in the
     * user message. Defaults to 'user'.
     *
     * @return string 'system' or 'user'
     */
    public function role(): string;
}