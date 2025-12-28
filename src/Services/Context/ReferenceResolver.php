<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

/**
 * ReferenceResolver
 *
 * Resolves conversational references like "those", "them", "the same"
 * by using conversation context to understand what entities are being discussed.
 */
class ReferenceResolver
{
    /**
     * Patterns that indicate a follow-up question
     */
    private array $followUpPatterns = [
        '/^and\s+/i',
        '/^but\s+/i',
        '/^also\s+/i',
        '/^what about\s+/i',
        '/\b(those|them|these|it)\b/i',
        '/^(show|filter|sort|group)\s+(me\s+)?the\s+/i',
        '/^the\s+(same|top|first|last)\b/i',
        '/^(top|first|last)\s+\d+/i',
    ];

    /**
     * Pronoun patterns
     */
    private array $pronounPatterns = [
        'pronoun' => '/\b(them|they|it)\b/i',
        'demonstrative' => '/\b(those|these|that|this)\b/i',
        'definite' => '/\bthe\s+(same|top|first|last|\w+s)\b/i',
    ];

    /**
     * Check if a question is a follow-up to previous context
     */
    public function isFollowUp(string $question): bool
    {
        foreach ($this->followUpPatterns as $pattern) {
            if (preg_match($pattern, $question)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect the type of reference in a question
     *
     * @return string|null 'pronoun', 'demonstrative', 'definite', 'implicit', or null
     */
    public function detectReferenceType(string $question): ?string
    {
        foreach ($this->pronounPatterns as $type => $pattern) {
            if (preg_match($pattern, $question)) {
                return $type;
            }
        }

        // Check for implicit continuation (e.g., "top 5 by revenue" without entity)
        if (preg_match('/^(top|first|last|show me|filter|sort)\s+/i', $question)) {
            // Only implicit if no explicit entity mentioned
            if (!preg_match('/\b[A-Z][a-z]+s?\b/', $question)) {
                return 'implicit';
            }
        }

        return null;
    }

    /**
     * Resolve references in a question using conversation context
     *
     * @param string $question The user's question
     * @param array $context Conversation context
     * @return array Resolution result
     */
    public function resolve(string $question, array $context): array
    {
        $referenceType = $this->detectReferenceType($question);
        $focusedEntity = $context['focused_entity'] ?? null;
        $lastQuery = $context['last_cypher_query'] ?? null;

        // No context available
        if (empty($focusedEntity) && empty($lastQuery)) {
            return [
                'resolved' => false,
                'resolved_entity' => null,
                'operation' => null,
                'enriched_question' => $question,
                'base_query' => null,
                'reference_type' => $referenceType,
            ];
        }

        // Determine operation type
        $operation = $this->determineOperation($question);

        // Build enriched question with resolved references
        $enrichedQuestion = $this->buildEnrichedQuestion($question, $focusedEntity, $operation);

        return [
            'resolved' => true,
            'resolved_entity' => $focusedEntity,
            'operation' => $operation,
            'enriched_question' => $enrichedQuestion,
            'base_query' => $operation === 'extend' ? $lastQuery : null,
            'reference_type' => $referenceType,
        ];
    }

    /**
     * Determine the operation type from the question
     *
     * Order matters: check more specific patterns before generic ones
     */
    private function determineOperation(string $question): string
    {
        // Check for modify operations first (sort, order, group)
        if (preg_match('/\b(sort|order|group)\b/i', $question)) {
            return 'modify';
        }

        // Check for extend operations (same, also, include)
        if (preg_match('/\b(same|also|include)\b/i', $question)) {
            return 'extend';
        }

        // Check for filter operations
        if (preg_match('/\b(filter|where|in|with|by)\b/i', $question)) {
            return 'filter';
        }

        return 'filter';
    }

    /**
     * Build an enriched question with resolved references
     */
    private function buildEnrichedQuestion(string $question, ?string $entity, string $operation): string
    {
        if ($entity === null) {
            return $question;
        }

        // Replace pronouns with entity name
        $enriched = preg_replace('/\b(those|them|these|they|it)\b/i', strtolower($entity) . 's', $question);

        // If question starts with "and", make it a complete sentence
        if (preg_match('/^and\s+/i', $enriched)) {
            $enriched = preg_replace('/^and\s+/i', "Show {$entity}s ", $enriched);
        }

        // Add entity context if implicit
        if ($enriched === $question && !str_contains(strtolower($question), strtolower($entity))) {
            $enriched = "{$entity}s: {$question}";
        }

        return $enriched;
    }
}
