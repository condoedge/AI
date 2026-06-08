<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Eval;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\Eval\JudgeVerdict;
use Condoedge\Ai\Services\LlmResolver;

/**
 * Reference-free LLM-as-judge.
 *
 * Grades an answer against ONLY the context that was actually retrieved (no
 * gold answer). The judge runs at temperature 0, returns strict JSON, and
 * decomposes the answer into per-claim faithfulness/grounding, plus overall
 * relevance and usefulness — every dimension 0-1 with reasons.
 *
 * It also performs a MODEL-AGNOSTIC / PRIVACY check: the answer must not name
 * the LLM vendor/model or dump raw database schema.
 *
 * Provider is chosen via LlmResolver::forRole('eval_judge') so the judge is
 * model-agnostic. When no judge is reachable (no API key / offline), the
 * service SKIPS gracefully and returns {@see JudgeVerdict::skipped()} — it
 * NEVER throws.
 */
class JudgeService
{
    public function __construct(
        private readonly ?LlmResolver $llmResolver = null,
        private readonly array $config = []
    ) {}

    /**
     * Judge an answer given the question and the retrieved context.
     *
     * @param string $question  The original question.
     * @param string $answer    The assistant's final answer.
     * @param array  $context   Retrieved context: ['data' => [...], 'files' => [...], 'cypher' => string|null]
     * @return JudgeVerdict
     */
    public function judge(string $question, string $answer, array $context): JudgeVerdict
    {
        $llm = $this->resolveJudge();
        if ($llm === null) {
            return JudgeVerdict::skipped('no judge provider / api key');
        }

        if (trim($answer) === '') {
            return JudgeVerdict::skipped('empty answer — nothing to judge');
        }

        try {
            $response = $llm->chatJson(
                [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->userPrompt($question, $answer, $context)],
                ],
                [
                    'temperature' => (float) ($this->config['temperature'] ?? 0.0),
                    'max_tokens' => (int) ($this->config['max_tokens'] ?? 800),
                ]
            );

            return $this->parseVerdict($response);
        } catch (\Throwable $e) {
            return JudgeVerdict::skipped('judge error: ' . $e->getMessage());
        }
    }

    /**
     * Parse a raw LLM JSON response into a JudgeVerdict.
     * Tolerant: missing keys clamp to 0/false; malformed input → skipped.
     *
     * Public so it can be unit-tested with a stubbed payload.
     *
     * @param array|object|string $response
     */
    public function parseVerdict(array|object|string $response): JudgeVerdict
    {
        $data = $this->normalize($response);
        if ($data === null) {
            return JudgeVerdict::skipped('unparseable judge response');
        }

        $claims = [];
        foreach ((array) ($data['claims'] ?? []) as $claim) {
            $claim = (array) $claim;
            $claims[] = [
                'claim' => (string) ($claim['claim'] ?? ''),
                'grounded' => (bool) ($claim['grounded'] ?? false),
                'reason' => (string) ($claim['reason'] ?? ''),
            ];
        }

        return new JudgeVerdict(
            faithfulness: $this->clamp($data['faithfulness'] ?? 0),
            grounding: $this->clamp($data['grounding'] ?? 0),
            relevance: $this->clamp($data['relevance'] ?? 0),
            usefulness: $this->clamp($data['usefulness'] ?? 0),
            disclosesModelOrSchema: (bool) ($data['discloses_model_or_schema'] ?? false),
            claims: $claims,
            reason: (string) ($data['reason'] ?? ''),
            skipped: false,
        );
    }

    /**
     * Resolve the judge provider, or null when none is usable (no API key).
     */
    private function resolveJudge(): ?LlmProviderInterface
    {
        if ($this->llmResolver === null) {
            return null;
        }

        try {
            $role = (string) ($this->config['role'] ?? 'eval_judge');
            $llm = $this->llmResolver->forRole($role);
        } catch (\Throwable $e) {
            return null;
        }

        // Graceful offline: if the active provider has no API key, do not call it.
        $provider = $llm->getProvider();
        $apiKey = config("ai.llm.{$provider}.api_key");
        if (empty($apiKey) || strlen((string) $apiKey) < 10) {
            return null;
        }

        return $llm;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a strict, reference-free evaluator of an AI assistant's answer. You do
NOT have a gold answer. Judge ONLY against the CONTEXT provided (query results,
files, cypher). Decompose the answer into atomic factual claims and check each
one against the context.

Score every dimension from 0.0 to 1.0:
- faithfulness: claims logically follow from the context (no contradiction).
- grounding: every claim is supported by the context (no fabrication). If the
  context is empty and the answer still asserts facts, grounding is low.
- relevance: the answer actually addresses the question.
- usefulness: the answer is clear and actionable for the asker.

Also set discloses_model_or_schema = true if the answer names the underlying
AI model/vendor (e.g. "I am GPT", "powered by Claude/OpenAI/Anthropic") OR dumps
raw database schema / internal labels / table names the user should not see.

Respond with STRICT JSON only, no prose:
{
  "claims": [{"claim": "...", "grounded": true, "reason": "..."}],
  "faithfulness": 0.0,
  "grounding": 0.0,
  "relevance": 0.0,
  "usefulness": 0.0,
  "discloses_model_or_schema": false,
  "reason": "one-sentence overall rationale"
}
PROMPT;
    }

    private function userPrompt(string $question, string $answer, array $context): string
    {
        $data = $context['data'] ?? [];
        $files = $context['files'] ?? [];
        $cypher = $context['cypher'] ?? null;

        $dataJson = json_encode(
            is_array($data) ? array_slice($data, 0, 10) : $data,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        $fileSnips = [];
        foreach ((array) $files as $f) {
            $name = is_array($f) ? ($f['filename'] ?? $f['file_id'] ?? '') : '';
            $snip = is_array($f) ? ($f['snippet'] ?? '') : (string) $f;
            $fileSnips[] = trim($name . ': ' . $snip);
        }
        $filesText = empty($fileSnips) ? '(none)' : implode("\n", array_slice($fileSnips, 0, 10));

        return "QUESTION:\n{$question}\n\n"
            . "ANSWER:\n{$answer}\n\n"
            . "CONTEXT — query rows (truncated):\n{$dataJson}\n\n"
            . "CONTEXT — files:\n{$filesText}\n\n"
            . "CONTEXT — cypher: " . ($cypher ?: '(none)') . "\n\n"
            . "Grade the answer using the rubric.";
    }

    /**
     * @param array|object|string $response
     * @return array<string, mixed>|null
     */
    private function normalize(array|object|string $response): ?array
    {
        if (is_string($response)) {
            $trimmed = trim($response);
            // Strip ```json fences if present.
            $trimmed = preg_replace('/^```(?:json)?|```$/m', '', $trimmed) ?? $trimmed;
            $decoded = json_decode(trim($trimmed), true);
            return is_array($decoded) ? $decoded : null;
        }

        if (is_object($response)) {
            $arr = json_decode(json_encode($response) ?: '[]', true);
            return is_array($arr) ? $arr : null;
        }

        return $response;
    }

    private function clamp(mixed $value): float
    {
        $v = (float) $value;
        if ($v < 0) {
            return 0.0;
        }
        // A value clearly on a 0-100 percent scale (e.g. 80) is normalised.
        // Values just above 1 (e.g. 1.5) are treated as a malformed 0-1 score
        // and clamped to 1.0, not divided.
        if ($v > 2 && $v <= 100) {
            return round($v / 100, 4);
        }
        if ($v > 1) {
            return 1.0;
        }
        return round($v, 4);
    }
}
