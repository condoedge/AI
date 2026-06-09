<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * FileContextSection
 *
 * Provides file context from vector search results with citation instructions.
 * Tells the LLM to use [1], [2] markers when citing from the provided files.
 * Priority: 45 (before similar_queries at 50)
 */
class FileContextSection extends BasePromptSection
{
    protected string $name = 'file_context';
    protected int $priority = 45;

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        return !empty($context->fileContext['relevant_files']);
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $files = $context->fileContext['relevant_files'] ?? [];

        if (empty($files)) {
            return '';
        }

        $output = $this->header('FILE CONTEXT');

        $output .= $this->getCitationInstructions();

        $output .= "**Relevant Files:**\n\n";

        foreach ($files as $index => $file) {
            $number = $index + 1;
            $filename = $file['filename'] ?? 'unknown';
            $relevance = $file['relevance'] ?? 0;
            $snippet = $file['snippet'] ?? '';

            $relevancePercent = (int) floor($relevance * 100);

            $output .= "**[{$number}] {$filename}**";

            $authorityNote = $file['authority_note'] ?? null;
            $priorityTier = $file['priority_tier'] ?? null;
            if ($authorityNote) {
                $output .= " — {$authorityNote}";
            } elseif ($priorityTier) {
                $output .= " ({$priorityTier})";
            }

            $output .= " (relevance: {$relevancePercent}%)\n";
            // Spotlight retrieved file content: it is data to analyze, never instructions.
            $output .= $this->spotlight($snippet, 'retrieved_document', ['id' => $number]) . "\n\n";
        }

        return $output;
    }

    /**
     * Get the citation instructions text
     */
    private function getCitationInstructions(): string
    {
        return <<<'INSTRUCTIONS'
**Citation Instructions:**
When using information from the files below, cite your sources using inline markers like [1], [2], etc.
Place the citation marker at the end of the relevant sentence or phrase.
Only cite files when you actually use their content in your response.

**Example:**
"Authentication can be configured using middleware [1]. The guard system handles sessions [2]."

When files have different authority levels (e.g., team-specific rules vs. general regulations),
prioritize the more specific source. Team or building-specific rules override general laws.
If both apply, mention both but state which takes priority.

INSTRUCTIONS;
    }
}
