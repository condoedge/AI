<?php

// src/Services/Chat/AmbiguityDetector.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

class AmbiguityDetector
{
    private array $vagueTerms = [
        'data', 'stuff', 'things', 'info', 'information',
        'list', 'records', 'items', 'results', 'it', 'them',
    ];

    private array $specificIndicators = [
        'how many', 'count', 'total', 'sum', 'average',
        'customers', 'orders', 'products', 'users', 'teams',
        'active', 'pending', 'completed', 'recent', 'last',
        'by', 'grouped', 'sorted', 'filtered', 'where',
    ];

    public function analyze(string $question, array $availableEntities = []): array
    {
        $questionLower = strtolower($question);
        $isAmbiguous = false;
        $clarificationQuestions = [];
        $reasons = [];

        // Check for vague terms without specific context
        $hasVagueTerms = $this->hasVagueTerms($questionLower);
        $hasSpecificIndicators = $this->hasSpecificIndicators($questionLower);

        if ($hasVagueTerms && !$hasSpecificIndicators) {
            $isAmbiguous = true;
            $reasons[] = 'Question contains vague terms without specifics';

            // Suggest entity clarification if we know available entities
            if (!empty($availableEntities)) {
                $entityList = implode(', ', array_slice($availableEntities, 0, 5));
                $clarificationQuestions[] = "Which type of data are you interested in? ({$entityList})";
            } else {
                $clarificationQuestions[] = 'What specific information are you looking for?';
            }
        }

        // Check if question is too short (likely incomplete)
        if (str_word_count($question) < 3) {
            $isAmbiguous = true;
            $reasons[] = 'Question is very short';
            $clarificationQuestions[] = 'Could you provide more details about what you\'re looking for?';
        }

        // Check for missing entity reference
        if (!$this->hasEntityReference($questionLower, $availableEntities) && !empty($availableEntities)) {
            $isAmbiguous = true;
            $reasons[] = 'No clear entity reference found';
            $entityList = implode(', ', array_slice($availableEntities, 0, 5));
            $clarificationQuestions[] = "Which entity are you asking about? ({$entityList})";
        }

        return [
            'is_ambiguous' => $isAmbiguous,
            'confidence' => $isAmbiguous ? 0.4 : 0.9,
            'reasons' => $reasons,
            'clarification_questions' => array_unique($clarificationQuestions),
        ];
    }

    private function hasVagueTerms(string $question): bool
    {
        foreach ($this->vagueTerms as $term) {
            if (str_contains($question, $term)) {
                return true;
            }
        }
        return false;
    }

    private function hasSpecificIndicators(string $question): bool
    {
        $count = 0;
        foreach ($this->specificIndicators as $indicator) {
            if (str_contains($question, $indicator)) {
                $count++;
            }
        }
        return $count >= 2; // Need at least 2 specific indicators
    }

    private function hasEntityReference(string $question, array $entities): bool
    {
        foreach ($entities as $entity) {
            $entityLower = strtolower($entity);
            $entityPlural = $entityLower . 's';
            if (str_contains($question, $entityLower) || str_contains($question, $entityPlural)) {
                return true;
            }
        }
        return false;
    }
}
