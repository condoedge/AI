<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Hook for project-specific file context enrichment.
 * Implementations can add priority tiers, team-based ordering,
 * metadata annotations, or custom scoring to file search results.
 *
 * Example: CondoEdge adds priority tiers where team-specific rule files
 * rank above provincial law files, and annotates each file with its
 * authority level.
 */
interface FileContextEnricherInterface
{
    /**
     * Enrich file results with project-specific priority/annotations.
     * Called after files are found by semantic search, before they're returned to the pipeline.
     * Can reorder, annotate, or filter files.
     *
     * @param array $files File results (each has: file_id, filename, snippet, relevance, source, match_type)
     * @param string $question The original question
     * @param mixed $user The current user (for team/access context)
     * @return array Enriched files, optionally with: priority_tier, authority_note, priority_score
     */
    public function enrich(array $files, string $question, mixed $user): array;
}
