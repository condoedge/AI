<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Support;

use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Support\TraceRedactor;

/**
 * The production turn trace (Phase 4.2): a compact, size-capped record of the
 * pipeline one chat turn ran, persisted on the assistant AiMessage under
 * metadata.trace so a live failure can be diagnosed after the fact.
 *
 * Shape (toArray()):
 *   version   int     trace schema version
 *   total_ms  float   wall time since the observer was created
 *   stages    list    [{stage, ms, data?}] in FINISH order (data = the stage's
 *                     summary: QU output, file ids + chunk indexes + scores,
 *                     cypher, row counts, branch, ...)
 *   errors    list    [{stage, class, message}]
 *   usage     object  {by_stage: {stage: {input, output, total, calls}},
 *                      total: {input, output, total, calls}, models: [..]}
 *   prompts   object  {stage: [messages, ...]} — ONLY when capture_prompts
 *   truncated list    the shrink steps applied when the trace exceeded max_bytes
 *
 * Privacy (persisted data only — the in-memory RecordingPipelineObserver keeps
 * everything verbatim for the eval):
 *   - the cypher is stored with string/number literals masked to `?`
 *     (ai.trace.redact_literals, default true — TraceRedactor / CypherLexer);
 *   - the understanding stage keeps only UNDERSTANDING_QU_FIELDS (no person
 *     names, clarified/clarifying questions, candidate readings, translations);
 *   - file_context references keep id / chunk / scores / source — never the
 *     snippet text (document content); the export stage never keeps its
 *     exported-row sample;
 *   - every error message is literal-masked and capped at 300 chars.
 *
 * Observation only: never read back by the pipeline; the chat use case calls it
 * behind GuardedPipelineObserver.
 */
final class MessageTraceObserver implements PipelineObserverInterface
{
    public const VERSION = 1;

    /** The only QU fields a persisted understanding stage keeps. */
    public const UNDERSTANDING_QU_FIELDS = [
        'question_type', 'intent', 'entity_types', 'language', 'search_query',
        'follow_up_reference', 'digest_request', 'export_format', 'needs_clarification',
        'is_possessive',
    ];

    /** Per-message content cap applied in the first shrink step. */
    private const PROMPT_CONTENT_CHARS = 2000;

    /** Long strings / lists inside stage data are cut to these in the data step. */
    private const DATA_STRING_CHARS = 500;
    private const DATA_LIST_ITEMS = 20;

    /** Error messages are always masked and bounded. */
    private const ERROR_MESSAGE_CHARS = 300;

    /** The skeleton fallback keeps this many errors, messages cut to SKELETON_MESSAGE_CHARS. */
    private const SKELETON_ERRORS = 5;
    private const SKELETON_MESSAGE_CHARS = 100;

    /** @var array<int, array<string, mixed>> */
    private array $stages = [];

    /** @var array<int, array{stage: string, class: string, message: string}> */
    private array $errors = [];

    /** @var array<string, array<int, array>> */
    private array $prompts = [];

    /** @var array<string, array{input: int, output: int, total: int, calls: int}> */
    private array $usage = [];

    /** @var array<int, string> */
    private array $models = [];

    private readonly int|float $startedAt;

    public function __construct(
        private readonly bool $capturePrompts = false,
        private readonly int $maxBytes = 65536,
        private readonly bool $redactLiterals = true,
    ) {
        $this->startedAt = hrtime(true);
    }

    /**
     * Built from config('ai.trace.*').
     */
    public static function fromConfig(): self
    {
        return new self(
            capturePrompts: (bool) config('ai.trace.capture_prompts', false),
            maxBytes: max(1024, (int) config('ai.trace.max_bytes', 65536)),
            redactLiterals: (bool) config('ai.trace.redact_literals', true),
        );
    }

    public function stageStarted(string $stage, array $data = []): void
    {
        // Timings ride stageFinished; the timeline is recorded in finish order.
    }

    public function stageFinished(string $stage, array $data = [], float $ms = 0.0): void
    {
        $entry = ['stage' => $stage, 'ms' => round($ms, 3)];
        $data = $this->redactStageData($stage, $data);
        if ($data !== []) {
            $entry['data'] = $data;
        }
        $this->stages[] = $entry;
    }

    /**
     * Persisted-trace privacy: whitelist the QU fields, mask the cypher, mask +
     * cap every error string a stage summary carries.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redactStageData(string $stage, array $data): array
    {
        if ($stage === 'understanding' && is_array($data['qu'] ?? null)) {
            $qu = [];
            foreach (self::UNDERSTANDING_QU_FIELDS as $field) {
                if (array_key_exists($field, $data['qu'])) {
                    $qu[$field] = $field === 'needs_clarification'
                        ? (bool) $data['qu'][$field]
                        : $data['qu'][$field];
                }
            }
            $data['qu'] = $qu;
        }

        // Document content never lands in the persisted trace: keep the
        // reference (id / chunk / scores / source), drop the snippet text.
        if ($stage === 'file_context' && is_array($data['files'] ?? null)) {
            foreach ($data['files'] as $i => $file) {
                if (is_array($file)) {
                    unset($data['files'][$i]['snippet']);
                }
            }
        }

        // Exported rows (the export stage's sample) are record data: never persisted.
        if ($stage === 'export') {
            unset($data['sample']);
        }

        if (is_array($data['fallbacks'] ?? null)) {
            foreach ($data['fallbacks'] as $i => $fallback) {
                if (is_array($fallback) && is_string($fallback['reason'] ?? null)) {
                    $data['fallbacks'][$i]['reason'] = $this->redactMessage($fallback['reason']);
                }
            }
        }

        if (is_string($data['cypher'] ?? null) && $this->redactLiterals) {
            $data['cypher'] = TraceRedactor::maskCypher($data['cypher']);
        }

        if (is_string($data['error'] ?? null)) {
            $data['error'] = $this->redactMessage($data['error']);
        }

        foreach (['errors', 'warnings'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $data[$key] = array_map(
                    fn ($message) => is_string($message) ? $this->redactMessage($message) : $message,
                    $data[$key]
                );
            }
        }

        return $data;
    }

    /**
     * Literal-mask (when enabled) THEN cap, so a cut never exposes half a literal.
     */
    private function redactMessage(string $message): string
    {
        if ($this->redactLiterals) {
            $message = TraceRedactor::maskText($message);
        }

        return mb_substr($message, 0, self::ERROR_MESSAGE_CHARS);
    }

    public function promptBuilt(string $stage, array $messages): void
    {
        if ($this->capturePrompts) {
            $this->prompts[$stage][] = $messages;
        }
    }

    public function stageError(string $stage, \Throwable $e): void
    {
        $this->errors[] = [
            'stage' => $stage,
            'class' => get_class($e),
            'message' => $this->redactMessage($e->getMessage()),
        ];
    }

    public function usage(string $stage, array $usage): void
    {
        $bucket = $this->usage[$stage] ?? ['input' => 0, 'output' => 0, 'total' => 0, 'calls' => 0];
        $bucket['input'] += (int) ($usage['input'] ?? 0);
        $bucket['output'] += (int) ($usage['output'] ?? 0);
        $bucket['total'] += (int) ($usage['total'] ?? 0);
        $bucket['calls']++;
        $this->usage[$stage] = $bucket;

        $model = $usage['model'] ?? null;
        if (is_string($model) && $model !== '' && !in_array($model, $this->models, true)) {
            $this->models[] = $model;
        }
    }

    /**
     * The persisted trace, sanitized to valid JSON and capped at max_bytes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $total = ['input' => 0, 'output' => 0, 'total' => 0, 'calls' => 0];
        foreach ($this->usage as $bucket) {
            foreach ($total as $key => $value) {
                $total[$key] = $value + $bucket[$key];
            }
        }

        $trace = [
            'version' => self::VERSION,
            'total_ms' => round((hrtime(true) - $this->startedAt) / 1e6, 3),
            'stages' => $this->stages,
            'errors' => $this->errors,
            'usage' => [
                'by_stage' => $this->usage,
                'total' => $total,
                'models' => $this->models,
            ],
        ];
        if ($this->capturePrompts) {
            $trace['prompts'] = $this->prompts;
        }

        return $this->fit($this->sanitize($trace));
    }

    /**
     * Shrink the trace under max_bytes: prompts cut → prompts dropped → data
     * samples cut → stage data dropped → error messages cut → skeleton (stage
     * names + ms, usage, errors[0..5]) → shed the skeleton's tail. The result
     * ALWAYS encodes (as Eloquent stores it) to <= max_bytes.
     *
     * @param array<string, mixed> $trace
     * @return array<string, mixed>
     */
    private function fit(array $trace): array
    {
        $applied = [];

        $steps = [
            'prompts' => function (array $t): array {
                foreach ($t['prompts'] ?? [] as $stage => $calls) {
                    foreach ($calls as $i => $messages) {
                        foreach ((array) $messages as $j => $message) {
                            if (is_array($message) && is_string($message['content'] ?? null)
                                && mb_strlen($message['content']) > self::PROMPT_CONTENT_CHARS) {
                                $t['prompts'][$stage][$i][$j]['content'] =
                                    mb_substr($message['content'], 0, self::PROMPT_CONTENT_CHARS) . '…[truncated]';
                            }
                        }
                    }
                }

                return $t;
            },
            'prompts_dropped' => function (array $t): array {
                unset($t['prompts']);

                return $t;
            },
            'data_samples' => function (array $t): array {
                foreach ($t['stages'] as $i => $stage) {
                    if (isset($stage['data'])) {
                        $t['stages'][$i]['data'] = $this->cutSamples($stage['data']);
                    }
                }

                return $t;
            },
            'stage_data_dropped' => function (array $t): array {
                foreach ($t['stages'] as $i => $stage) {
                    unset($t['stages'][$i]['data']);
                }

                return $t;
            },
            'errors' => function (array $t): array {
                foreach ($t['errors'] as $i => $error) {
                    $t['errors'][$i]['message'] = mb_substr((string) $error['message'], 0, 200);
                }
                $t['errors'] = array_slice($t['errors'], 0, 20);

                return $t;
            },
            // Final fallback: the skeleton — stage names + ms, usage, the first errors.
            'skeleton' => function (array $t): array {
                return [
                    'version' => $t['version'] ?? self::VERSION,
                    'total_ms' => $t['total_ms'] ?? null,
                    'stages' => array_map(
                        static fn (array $s): array => ['stage' => $s['stage'] ?? '', 'ms' => $s['ms'] ?? 0],
                        $t['stages'] ?? []
                    ),
                    'errors' => array_map(
                        static fn (array $e): array => [
                            'stage' => $e['stage'] ?? '',
                            'class' => $e['class'] ?? '',
                            'message' => mb_substr((string) ($e['message'] ?? ''), 0, self::SKELETON_MESSAGE_CHARS),
                        ],
                        array_slice($t['errors'] ?? [], 0, self::SKELETON_ERRORS)
                    ),
                    'usage' => $t['usage'] ?? [],
                ];
            },
        ];

        foreach ($steps as $name => $step) {
            if ($this->bytes($trace, $applied) <= $this->maxBytes) {
                break;
            }
            if (str_starts_with($name, 'prompts') && !isset($trace['prompts'])) {
                continue;
            }
            $trace = $step($trace);
            $applied[] = $name === 'prompts_dropped' ? 'prompts' : $name;
        }

        // Hard guarantee: even the skeleton can exceed the cap (hundreds of stages,
        // long unicode errors). Shed detail until it fits — error messages, then the
        // tail of the stage timeline, then usage by stage — never exceed max_bytes.
        if ($this->bytes($trace, $applied) > $this->maxBytes) {
            foreach ($trace['errors'] as $i => $error) {
                $trace['errors'][$i]['message'] = '';
            }
        }
        $totalStages = count($trace['stages'] ?? []);
        while ($this->bytes($trace, $applied) > $this->maxBytes && ($trace['stages'] ?? []) !== []) {
            $trace['stages'] = array_slice($trace['stages'], 0, intdiv(count($trace['stages']), 2));
            $trace['stages_omitted'] = $totalStages - count($trace['stages']);
        }
        if ($this->bytes($trace, $applied) > $this->maxBytes) {
            unset($trace['usage']['by_stage'], $trace['usage']['models']);
            $trace['errors'] = array_slice($trace['errors'] ?? [], 0, 1);
        }
        if ($this->bytes($trace, $applied) > $this->maxBytes) {
            $trace = ['version' => self::VERSION, 'error' => 'trace_too_large'];
        }

        if ($applied !== []) {
            $trace['truncated'] = array_values(array_unique($applied));
        }

        return $trace;
    }

    /**
     * Cut long strings and lists inside a data sample (recursive).
     */
    private function cutSamples(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > self::DATA_STRING_CHARS
                ? mb_substr($value, 0, self::DATA_STRING_CHARS) . '…[truncated]'
                : $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if ($isList && count($value) > self::DATA_LIST_ITEMS) {
            $omitted = count($value) - self::DATA_LIST_ITEMS;
            $value = array_slice($value, 0, self::DATA_LIST_ITEMS);
            $value[] = "…[{$omitted} more]";
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->cutSamples($item);
        }

        return $value;
    }

    /**
     * The stored size: measured EXACTLY as the Eloquent `array` cast encodes
     * (plain json_encode — unicode and slashes escaped, so "é" costs 6 bytes).
     *
     * @param array<int, string> $applied
     */
    private function bytes(array $trace, array $applied): int
    {
        // Account for the 'truncated' marker the result will carry.
        if ($applied !== []) {
            $trace['truncated'] = array_values(array_unique($applied));
        }

        $json = json_encode($trace);
        if ($json === false) {
            $json = (string) json_encode($trace, JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return strlen($json);
    }

    /**
     * Round-trip through JSON so the trace is plain arrays with valid UTF-8 —
     * the AiMessage `array` cast must never fail on a driver error message.
     *
     * @param array<string, mixed> $trace
     * @return array<string, mixed>
     */
    private function sanitize(array $trace): array
    {
        $json = json_encode(
            $trace,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : ['version' => self::VERSION, 'stages' => [], 'errors' => [], 'usage' => []];
    }
}
