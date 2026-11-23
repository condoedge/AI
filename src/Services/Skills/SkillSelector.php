<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Skills;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Retrieval\CalculationMatcher;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Support\Audiences;

/**
 * Which skill references the question calls for ({@see SkillLibrary}).
 *
 * Code decides, on the same measure as the canonical calculations
 * ({@see CalculationMatcher}): the embedding similarity between the question (as asked
 * and as Query Understanding rewrote it) and each reference's "title — description
 * and typical questions". A reference is a candidate only when the asker's audience
 * may read it and it is written in the question's language (a reference naming no
 * language serves every language; when none is written in the question's language,
 * the language is not a filter). The best reference names the skill; the references
 * of that skill within `margin` of it are loaded whole, `max_references` at most.
 */
final class SkillSelector
{
    public const DEFAULT_MIN_SIMILARITY = 0.40;
    public const DEFAULT_MARGIN = 0.08;
    public const DEFAULT_MAX_REFERENCES = 3;

    public function __construct(
        private readonly EmbeddingProviderInterface $embedding,
        private readonly AiLogger $logger,
    ) {}

    /**
     * @return array{}|array{name: string, instructions: string, references: list<array{id: string, title: string, content: string, pages: list<string>, similarity: float}>}
     */
    public function select(string $question, QueryUnderstandingResult $qu, mixed $user): array
    {
        $skills = SkillLibrary::all();
        if ($skills === []) {
            return [];
        }

        $candidates = $this->candidates($skills, $qu->language, Audiences::documents($user));
        if ($candidates === []) {
            return [];
        }

        $matcher = new CalculationMatcher(
            $this->embedding,
            $this->logger,
            (float) config('ai.skills.min_similarity', self::DEFAULT_MIN_SIMILARITY),
            (float) config('ai.skills.margin', self::DEFAULT_MARGIN),
        );
        $matched = $matcher->match(
            array_map(static fn (array $c): array => ['label' => $c['title'], 'description' => $c['matched_on']], $candidates),
            [$question, $qu->clarifiedQuestion, $qu->searchQuery]
        );
        if ($matched === null || $matched === []) {
            return [];
        }

        $skill = $candidates[array_key_first($matched)]['skill'];
        $references = [];
        foreach ($matched as $key => $similarity) {
            if ($candidates[$key]['skill'] !== $skill) {
                continue;
            }
            $references[] = [
                'id' => $candidates[$key]['id'],
                'title' => $candidates[$key]['title'],
                'content' => $candidates[$key]['content'],
                'pages' => $candidates[$key]['pages'],
                'similarity' => round($similarity, 4),
            ];
        }

        return [
            'name' => $skill,
            'instructions' => $skills[$skill]['instructions'],
            'disclaimer' => $skills[$skill]['disclaimer'] ?? [],
            'references' => array_slice($references, 0, max(1, (int) config('ai.skills.max_references', self::DEFAULT_MAX_REFERENCES))),
        ];
    }

    /**
     * @param list<string>|null $readable the asker's document audiences; null = nothing is filtered
     * @return array<string, array{skill: string, id: string, title: string, matched_on: string, content: string, pages: list<string>}>
     */
    private function candidates(array $skills, string $language, ?array $readable): array
    {
        $all = [];
        foreach ($skills as $name => $skill) {
            foreach ($skill['references'] as $id => $reference) {
                if ($readable !== null && $reference['audience'] !== [] && array_intersect($reference['audience'], $readable) === []) {
                    continue;
                }
                $all["{$name}/{$id}"] = [
                    'skill' => $name,
                    'id' => $id,
                    'title' => $reference['title'],
                    'matched_on' => trim($reference['description'] . ' ' . implode(' ', $reference['questions'])),
                    'content' => $reference['content'],
                    'pages' => $reference['pages'],
                    'language' => $reference['language'],
                ];
            }
        }

        $inLanguage = array_filter($all, static fn (array $c): bool => $c['language'] === null || $c['language'] === $language);
        $written = array_filter($inLanguage, static fn (array $c): bool => $c['language'] !== null);

        return $language !== '' && $written !== [] ? $inLanguage : $all;
    }
}
