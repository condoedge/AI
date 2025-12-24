<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * PromptSectionInterface
 *
 * Defines a section that can be added to the prompt builder pipeline.
 * Each section is responsible for formatting a specific part of the prompt.
 *
 * Sections are processed in priority order (lower = earlier) and can be:
 * - Added dynamically via SemanticPromptBuilder::addSection()
 * - Extended via SemanticPromptBuilder::extendBuild()
 * - Removed or replaced at runtime
 *
 * Example implementation:
 * ```php
 * class CustomContextSection implements PromptSectionInterface
 * {
 *     public function getName(): string { return 'custom_context'; }
 *     public function getPriority(): int { return 50; }
 *     public function format(string $question, array $context, array $options): string
 *     {
 *         return "=== CUSTOM CONTEXT ===\n\nYour custom info here\n\n";
 *     }
 * }
 * ```
 */
interface PromptSectionInterface
{
    /**
     * Get the unique name of this section
     *
     * Used for identification, replacement, and removal
     *
     * @return string Section name (e.g., 'project_context', 'schema', 'examples')
     */
    public function getName(): string;

    /**
     * Get the priority of this section
     *
     * Lower numbers are processed first. Standard priorities:
     * - 10: Project context
     * - 20: Schema and structure
     * - 30: Relationships
     * - 40: Examples and data
     * - 50: Similar queries
     * - 60: Detected entities/scopes
     * - 70: Patterns and rules
     * - 80: Question
     * - 90: Task instructions
     *
     * @return int Priority (lower = earlier in prompt)
     */
    public function getPriority(): int;

    /**
     * Format this section of the prompt
     *
     * @param string $question The user's natural language question
     * @param array $context Full context array with schema, entities, etc.
     * @param array $options Additional options (allowWrite, etc.)
     * @return string Formatted section content (empty string to skip)
     */
    public function format(string $question, array $context, array $options = []): string;

    /**
     * Check if this section should be included
     *
     * Allows conditional inclusion based on context
     *
     * @param string $question The user's question
     * @param array $context Full context array
     * @param array $options Additional options
     * @return bool True to include, false to skip
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool;
}
