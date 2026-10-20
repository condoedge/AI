<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

/**
 * Token accounting for prompt payloads.
 *
 * Pure, side-effect-free: estimates token usage and enforces an optional
 * per-turn token budget over the assembled prompt sections (SemanticPromptBuilder).
 */
class TokenBudgeter
{
    /**
     * Enforce a token budget over an ordered list of prompt sections.
     *
     * Each entry is the assembled contribution of one prompt section:
     *   ['name' => string, 'role' => string, 'priority' => int,
     *    'content' => string, 'keep' => bool]
     *
     * When the summed token estimate of all entries exceeds $budget, the least
     * important DROPPABLE sections (keep === false) are removed first — least
     * important meaning the highest priority number (priorities run low->high =
     * most->least important). Sections flagged keep are never dropped, even if the
     * result still exceeds the budget: correctness (the question + task cue must
     * survive) wins over the budget. A non-positive budget disables enforcement and
     * returns the input unchanged, so the default (no budget) path is a no-op.
     *
     * The relative order of the surviving sections is preserved.
     *
     * @param array<int, array{name: string, role: string, priority: int, content: string, keep: bool}> $sections
     * @param int $budget Maximum total token estimate; <= 0 disables enforcement.
     * @return array<int, array{name: string, role: string, priority: int, content: string, keep: bool}>
     */
    public function enforce(array $sections, int $budget): array
    {
        if ($budget <= 0 || empty($sections)) {
            return $sections;
        }

        $total = 0;
        foreach ($sections as $section) {
            $total += $this->estimateTokens($section['content'] ?? '');
        }

        if ($total <= $budget) {
            return $sections;
        }

        // Candidates for dropping: droppable sections, least important first
        // (highest priority number first; ties broken by later position so the
        // last-assembled section is shed first).
        $droppable = [];
        foreach ($sections as $index => $section) {
            if (empty($section['keep'])) {
                $droppable[] = ['index' => $index, 'priority' => (int) ($section['priority'] ?? 0)];
            }
        }

        usort($droppable, function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority']
                ?: $b['index'] <=> $a['index'];
        });

        $dropped = [];
        foreach ($droppable as $candidate) {
            if ($total <= $budget) {
                break;
            }
            $dropped[$candidate['index']] = true;
            $total -= $this->estimateTokens($sections[$candidate['index']]['content'] ?? '');
        }

        $kept = [];
        foreach ($sections as $index => $section) {
            if (!isset($dropped[$index])) {
                $kept[] = $section;
            }
        }

        return $kept;
    }

    /**
     * Estimate token count for a text string.
     *
     * Rough approximation: ~4 characters per token for English text, with a
     * small uplift for JSON structure overhead.
     */
    public function estimateTokens(string $text): int
    {
        if (empty($text)) {
            return 0;
        }

        $baseTokens = (int) ceil(strlen($text) / 4);

        // If it looks like JSON, add overhead for structure
        if (str_starts_with(trim($text), '{') || str_starts_with(trim($text), '[')) {
            $baseTokens = (int) ceil($baseTokens * 1.2);
        }

        return $baseTokens;
    }
}
