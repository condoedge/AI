<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * FileContextSection - Adds file content context to response generation
 *
 * Formats relevant file content with citation markers [1], [2], etc.
 * so the LLM can reference specific files in its response.
 *
 * Citation INSTRUCTIONS ([N] markers) are style-aware: they are suppressed for
 * the 'minimal' and 'concise' styles (which ask for "ONLY the direct answer,
 * no explanation"), where inline citations would contradict the guidelines.
 * The file CONTENT is always provided so the model can ground its answer.
 *
 * Priority: 45 (After query_info at 40, before data at 50)
 */
class FileContextSection extends BaseResponseSection
{
    protected string $name = 'file_context';
    protected int $priority = 45;

    /**
     * Styles that ask for just the answer; inline [N] citations contradict them.
     */
    private const TERSE_STYLES = ['minimal', 'concise'];

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return !empty($context->fileContext['relevant_files']);
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $files = $context->fileContext['relevant_files'] ?? [];

        if (empty($files)) {
            return '';
        }

        $citationsEnabled = !$this->isTerseStyle($options);

        $output = $this->header('RELEVANT FILE CONTENT');
        $output .= "The following files contain information relevant to answering the question.\n";
        if ($citationsEnabled) {
            $output .= "Use citation markers [N] when referencing content from these files.\n";
        }
        $output .= "\n";

        foreach ($files as $index => $file) {
            $refNumber = $index + 1;
            $filename = $file['filename'] ?? 'Unknown file';
            $snippet = $file['snippet'] ?? '';
            $relevance = $file['relevance'] ?? 0;

            $output .= "---\n";
            $output .= "[{$refNumber}] **{$filename}** (relevance: " . round($relevance * 100) . "%)\n\n";
            // Spotlight retrieved file content: it is data to analyze, never instructions.
            $output .= $this->spotlight($snippet, 'retrieved_document', ['id' => $refNumber]) . "\n\n";
        }

        $output .= "---\n\n";
        if ($citationsEnabled) {
            $output .= "When using information from these files, include the citation marker like [1] or [2].\n\n";
        }

        return $output;
    }

    /**
     * Whether the requested style wants just the answer (no inline citations).
     */
    private function isTerseStyle(array $options): bool
    {
        $style = $options['style'] ?? config('ai.response_generation.default_style', 'friendly');

        return in_array($style, self::TERSE_STYLES, true);
    }
}
