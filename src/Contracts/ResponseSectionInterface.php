<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * ResponseSectionInterface
 *
 * Defines a section that can be added to the response generator prompt pipeline.
 * Each section is responsible for formatting a specific part of the explanation prompt.
 *
 * Sections are processed in priority order (lower = earlier) and can be:
 * - Registered via config('ai.response_generator_sections')
 * - Added/removed at runtime via ResponseGenerator::addModule()/removeModule()
 *
 * Example implementation:
 * ```php
 * class CustomInsightsSection implements ResponseSectionInterface
 * {
 *     public function getName(): string { return 'custom_insights'; }
 *     public function getPriority(): int { return 50; }
 *     public function format(array $context, array $options): string
 *     {
 *         return "=== CUSTOM INSIGHTS ===\n\nYour insights here\n\n";
 *     }
 * }
 * ```
 */
interface ResponseSectionInterface extends SectionModuleInterface
{
    /**
     * Get the unique name of this section
     *
     * @return string Section name (e.g., 'system', 'question', 'data')
     */
    public function getName(): string;

    /**
     * Get the priority of this section
     *
     * Lower numbers are processed first. Standard priorities:
     * - 10: System prompt
     * - 20: Project context
     * - 30: Original question
     * - 40: Query information
     * - 50: Results data
     * - 60: Statistics
     * - 70: Guidelines
     * - 80: Task instructions
     *
     * @return int Priority (lower = earlier in prompt)
     */
    public function getPriority(): int;

    /**
     * Format this section of the prompt
     *
     * @param PipelineContext $context Pipeline context with question, data, stats, etc.
     * @param array $options Additional options (style, format, etc.)
     * @return string Formatted section content (empty string to skip)
     */
    public function format(PipelineContext $context, array $options = []): string;

    /**
     * Check if this section should be included
     *
     * @param PipelineContext $context Pipeline context
     * @param array $options Additional options
     * @return bool True to include, false to skip
     */
    public function shouldInclude(PipelineContext $context, array $options = []): bool;
}
