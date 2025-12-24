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
}