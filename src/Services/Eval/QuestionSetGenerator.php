<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Eval;

use Condoedge\Ai\Services\LlmResolver;

/**
 * Builds a tagged, label-free closed-Q&A question set from the project config.
 *
 * "Label-free" = the eval is reference-free; we never store an expected answer.
 * Questions are derived purely from what the project told the system about
 * itself, so the harness is model- and domain-agnostic:
 *
 *   - config('ai.entities')                 → aliases / description / scopes /
 *                                             common_properties → entity & scope
 *                                             & property questions
 *   - config('ai.chat.example_questions')   → curated example questions verbatim
 *   - config('ai.project.business_rules')   → "is it true that ...?" questions
 *   - config('ai.file_context.physical_paths') → doc/knowledge questions
 *
 * Each question carries tags ('entity', 'scope', 'property', 'example',
 * 'business_rule', 'file', 'data_query', 'knowledge_query') so the command can
 * filter with --tags and attribution can reason about question category.
 *
 * Phrasing is improved by the LLM via LlmResolver::forRole('eval_generator')
 * when reachable; offline (or on any LLM error) it falls back to a DETERMINISTIC
 * TEMPLATE so the generator never throws and always yields the same set.
 *
 * @phpstan-type Question array{question: string, tags: string[]}
 */
class QuestionSetGenerator
{
    public function __construct(
        private readonly ?LlmResolver $llmResolver = null,
        private readonly array $config = []
    ) {}

    /**
     * Generate the question set.
     *
     * @param bool     $offline  When true, never call the LLM (template only).
     * @param int|null $limit    Optional cap on number of questions.
     * @return array<int, array{question: string, tags: string[]}>
     */
    public function generate(bool $offline = false, ?int $limit = null): array
    {
        $questions = [];

        foreach ($this->fromEntities() as $q) {
            $questions[] = $q;
        }
        foreach ($this->fromExampleQuestions() as $q) {
            $questions[] = $q;
        }
        foreach ($this->fromPageExampleQuestions() as $q) {
            $questions[] = $q;
        }
        foreach ($this->fromBusinessRules() as $q) {
            $questions[] = $q;
        }
        foreach ($this->fromFiles() as $q) {
            $questions[] = $q;
        }

        $questions = $this->dedupe($questions);

        // Optional LLM polish (phrasing only — never changes tags/intent).
        if (!$offline) {
            $questions = $this->maybePolish($questions);
        }

        if ($limit !== null && $limit > 0) {
            $questions = array_slice($questions, 0, $limit);
        }

        return array_values($questions);
    }

    /**
     * Questions derived from configured entities (aliases / scopes / properties).
     *
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function fromEntities(): array
    {
        $out = [];
        $entities = (array) ($this->config['entities'] ?? []);

        foreach ($entities as $name => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }

            $meta = $cfg['metadata'] ?? [];
            $aliases = array_values(array_filter((array) ($meta['aliases'] ?? [])));
            // Prefer a human alias over the raw class/label name (stays label-free).
            $term = $this->pickTerm($aliases, $meta['description'] ?? '', (string) $name);

            if ($term === '') {
                continue;
            }

            // Basic "how many" / "list" data questions.
            $out[] = $this->q("How many {$this->plural($term)} are there?", ['entity', 'data_query', 'count']);
            $out[] = $this->q("List the most recent {$this->plural($term)}.", ['entity', 'data_query', 'list']);

            // Scope questions (business terminology).
            foreach ((array) ($meta['scopes'] ?? []) as $scopeName => $scopeCfg) {
                $desc = is_array($scopeCfg) ? ($scopeCfg['description'] ?? '') : (string) $scopeCfg;
                $label = $this->humanize((string) $scopeName);
                $phrase = $desc !== '' ? $desc : "{$label} {$this->plural($term)}";
                $out[] = $this->q("Show me {$phrase}.", ['entity', 'scope', 'data_query']);
            }

            // Property questions ("what is the <prop> of ...").
            $props = (array) ($meta['common_properties'] ?? []);
            foreach (array_slice($this->propertyTerms($props), 0, 2) as $prop) {
                $out[] = $this->q(
                    "What information do we have about the {$prop} of {$this->plural($term)}?",
                    ['entity', 'property', 'data_query']
                );
            }
        }

        return $out;
    }

    /**
     * Curated example questions, taken verbatim (they are already label-free).
     *
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function fromExampleQuestions(): array
    {
        $out = [];
        foreach ((array) ($this->config['example_questions'] ?? []) as $question) {
            $question = trim((string) $question);
            if ($question !== '') {
                $out[] = $this->q($question, ['example', 'data_query']);
            }
        }
        return $out;
    }

    /**
     * Per-page recommended questions, taken verbatim and tagged so the eval command can
     * specifically smoke-test clicked recommendations with --tags=page_example.
     *
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function fromPageExampleQuestions(): array
    {
        $out = [];
        foreach ((array) ($this->config['page_examples'] ?? []) as $entityType => $questions) {
            foreach ((array) $questions as $question) {
                $question = trim((string) $question);
                if ($question === '') {
                    continue;
                }

                $tags = ['example', 'page_example', 'page_context', 'data_query'];
                if (is_string($entityType) && trim($entityType) !== '') {
                    $tags[] = 'page:' . trim($entityType);
                }

                $out[] = $this->q($question, $tags);
            }
        }

        return $out;
    }

    /**
     * "Is it true that ...?" questions from declared business rules.
     *
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function fromBusinessRules(): array
    {
        $out = [];
        foreach ((array) ($this->config['business_rules'] ?? []) as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            $rule = rtrim($rule, '.');
            $out[] = $this->q("Is it true that {$this->lcfirst($rule)}?", ['business_rule', 'knowledge_query']);
        }
        return $out;
    }

    /**
     * Knowledge questions seeded from configured documentation paths.
     *
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function fromFiles(): array
    {
        $out = [];
        foreach ((array) ($this->config['physical_paths'] ?? []) as $path) {
            $topic = $this->topicFromPath((string) $path);
            if ($topic === '') {
                continue;
            }
            $out[] = $this->q("What does the documentation say about {$topic}?", ['file', 'knowledge_query']);
        }
        return $out;
    }

    /**
     * Optionally rephrase questions via the LLM for natural variety.
     * Falls back silently to the template phrasing on any error/offline.
     *
     * @param array<int, array{question: string, tags: string[]}> $questions
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function maybePolish(array $questions): array
    {
        if ($this->llmResolver === null || empty($questions)) {
            return $questions;
        }

        try {
            $llm = $this->llmResolver->forRole('eval_generator');

            $list = [];
            foreach ($questions as $i => $q) {
                $list[] = ($i + 1) . '. ' . $q['question'];
            }

            $prompt = "Rephrase each numbered question below so it sounds like a real user "
                . "typed it. Keep the SAME meaning and the SAME count. Do NOT add or remove "
                . "questions. Do NOT mention any entity class names, database labels, or technical "
                . "terms the user would not use. Respond with strict JSON: "
                . '{"questions": ["...", "..."]} in the same order.' . "\n\n"
                . implode("\n", $list);

            $response = $llm->chatJson(
                [['role' => 'user', 'content' => $prompt]],
                ['temperature' => 0.4, 'max_tokens' => 1500]
            );

            $rephrased = is_array($response) ? ($response['questions'] ?? null) : ($response->questions ?? null);

            if (is_array($rephrased) && count($rephrased) === count($questions)) {
                foreach ($questions as $i => &$q) {
                    $candidate = trim((string) $rephrased[$i]);
                    if ($candidate !== '') {
                        $q['question'] = $candidate;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Deterministic template phrasing already in place — keep it.
        }

        return $questions;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @param array<int, array{question: string, tags: string[]}> $questions */
    private function dedupe(array $questions): array
    {
        $seen = [];
        $out = [];
        foreach ($questions as $q) {
            $key = strtolower(trim($q['question']));
            if ($key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                $index = $seen[$key];
                $out[$index]['tags'] = array_values(array_unique(array_merge(
                    $out[$index]['tags'],
                    $q['tags'] ?? []
                )));
                continue;
            }

            $seen[$key] = count($out);
            $out[] = $q;
        }
        return $out;
    }

    /** @param string[] $tags */
    private function q(string $question, array $tags): array
    {
        return ['question' => $question, 'tags' => array_values(array_unique($tags))];
    }

    /**
     * Choose the most natural human term for an entity (alias preferred).
     *
     * @param string[] $aliases
     */
    private function pickTerm(array $aliases, string $description, string $fallback): string
    {
        // Prefer the shortest single-word singular-ish alias for clean phrasing.
        $candidates = array_filter($aliases, fn ($a) => is_string($a) && trim($a) !== '');
        if (!empty($candidates)) {
            usort($candidates, fn ($a, $b) => strlen($a) <=> strlen($b));
            return strtolower(trim($candidates[0]));
        }

        if ($description !== '') {
            // Use the first noun-ish word of the description as a soft fallback.
            $first = trim(strtok($description, ' .,'));
            if ($first !== '' && strlen($first) > 2) {
                return strtolower($first);
            }
        }

        // Last resort: humanize the config key (still avoids raw label leakage in output).
        return $this->humanize($fallback);
    }

    /**
     * Extract property names from common_properties (keys or 'name' fields).
     *
     * @param array<mixed> $props
     * @return string[]
     */
    private function propertyTerms(array $props): array
    {
        $out = [];
        foreach ($props as $key => $val) {
            if (is_string($key) && !is_int($key)) {
                $out[] = $this->humanize($key);
            } elseif (is_string($val)) {
                $out[] = $this->humanize($val);
            } elseif (is_array($val) && isset($val['name'])) {
                $out[] = $this->humanize((string) $val['name']);
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    private function topicFromPath(string $path): string
    {
        // docs/**/*.mdx → "docs"; resources/legal/*.md → "legal"
        $path = str_replace('\\', '/', $path);
        $parts = array_values(array_filter(explode('/', $path), fn ($p) => $p !== '' && !str_contains($p, '*') && !str_contains($p, '.')));
        if (empty($parts)) {
            return '';
        }
        return $this->humanize(end($parts));
    }

    private function humanize(string $value): string
    {
        $value = preg_replace('/(?<!^)([A-Z])/', ' $1', $value) ?? $value; // CamelCase → spaced
        $value = str_replace(['_', '-'], ' ', $value);
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        return strtolower($value);
    }

    private function plural(string $term): string
    {
        $term = trim($term);
        if ($term === '') {
            return $term;
        }
        $last = strtolower(substr($term, -1));
        if ($last === 's') {
            return $term;
        }
        if ($last === 'y' && !in_array(strtolower(substr($term, -2, 1)), ['a', 'e', 'i', 'o', 'u'], true)) {
            return substr($term, 0, -1) . 'ies';
        }
        return $term . 's';
    }

    private function lcfirst(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower(mb_substr($value, 0, 1)) . mb_substr($value, 1);
        }

        return lcfirst($value);
    }
}
