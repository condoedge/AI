<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

/**
 * FileContextSection - Adds file content context to response generation
 *
 * Formats relevant file content with citation markers [1], [2], etc.
 * so the LLM can reference specific files in its response.
 *
 * Priority: 45 (After query_info at 40, before data at 50)
 */
class FileContextSection extends BaseResponseSection
{
    protected string $name = 'file_context';
    protected int $priority = 45;

    public function shouldInclude(array $context, array $options = []): bool
    {
        return !empty($context['file_context']['relevant_files']);
    }

    public function format(array $context, array $options = []): string
    {
        $files = $context['file_context']['relevant_files'] ?? [];

        if (empty($files)) {
            return '';
        }

        $output = $this->header('RELEVANT FILE CONTENT');
        $output .= "The following files contain information relevant to answering the question.\n";
        $output .= "Use citation markers [N] when referencing content from these files.\n\n";

        foreach ($files as $index => $file) {
            $refNumber = $index + 1;
            $filename = $file['filename'] ?? 'Unknown file';
            $snippet = $file['snippet'] ?? '';
            $relevance = $file['relevance'] ?? 0;

            $output .= "---\n";
            $output .= "[{$refNumber}] **{$filename}** (relevance: " . round($relevance * 100) . "%)\n\n";
            $output .= $snippet . "\n\n";
        }

        $output .= "---\n\n";
        $output .= "When using information from these files, include the citation marker like [1] or [2].\n\n";

        return $output;
    }
}
