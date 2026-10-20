<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Support\UntrustedLabel;

/**
 * FileContextSection (query side)
 *
 * Tells the Cypher generator that documents relevant to the question EXIST, by
 * name only (Task 5.9). The generator never cites and never reads documents — the
 * response prompt's ResponseSections\FileContextSection carries the document text,
 * the citation rules and the per-file authority notes. What the generator needs is
 * the fact that documents cover the topic, so a purely document question can be
 * answered without a query while data questions are still answered from the graph.
 *
 * It used to ship up to 5 x 6000 chars of document text, citation instructions and
 * a hardcoded "team or building-specific rules override general laws" line (wrong
 * for mandatory CCQ provisions) — all removed.
 *
 * Priority: 45 (before similar_queries at 50).
 */
class FileContextSection extends BasePromptSection
{
    private const MAX_NAME_LENGTH = 120;

    protected string $name = 'file_context';
    protected int $priority = 45;

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        return !empty($context->fileContext['relevant_files']);
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $files = $context->fileContext['relevant_files'] ?? [];
        if (empty($files) || !is_array($files)) {
            return '';
        }

        $names = [];
        foreach ($files as $file) {
            $name = is_array($file) ? ($file['filename'] ?? null) : null;
            if (!is_string($name)) {
                continue;
            }
            // File names are user-controlled: the shared sanitiser (one line, no
            // markup, bounded length), then spotlighted.
            $name = UntrustedLabel::of($name, self::MAX_NAME_LENGTH);
            if ($name === '') {
                continue;
            }
            $names[$name] = '- ' . $name;
        }

        if ($names === []) {
            return '';
        }

        return $this->header('RELATED DOCUMENTS')
            . "Documents exist on this topic (they are read separately when the answer is written); "
            . "answer data questions from the graph.\n"
            . $this->spotlight(implode("\n", $names), 'retrieved_document', ['id' => 'names']) . "\n\n";
    }
}
