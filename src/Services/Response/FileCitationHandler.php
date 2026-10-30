<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

/**
 * The citation markers [1], [2] of a composed answer.
 *
 * The model cites passages by number so the answer can be checked claim by claim
 * (AnswerValidator). A number means nothing to the reader: the markers leave the text here,
 * and the cited files are listed by name under the answer (RichDataRenderer::renderFileReferences).
 */
class FileCitationHandler extends AbstractContentLinkHandler
{
    public function getPatterns(): string
    {
        return '/\[(\d+)\]/';
    }

    public function createElements(string $content, array $context = []): array
    {
        return [];
    }

    /** A run of markers ("[2], [3] ,[4]") goes with its separators and the space before it. */
    public function stripLinks(string $content): string
    {
        return preg_replace('/\s*\[\d+\](?:\s*[,;]?\s*\[\d+\])*/u', '', $content) ?? $content;
    }

    protected function getDeduplicationKey(array $match): string
    {
        return $match[1];
    }

    protected function createElementForMatch(array $match, array $context): mixed
    {
        return null;
    }

    protected function getStripReplacement(array $match): string
    {
        return '';
    }
}
