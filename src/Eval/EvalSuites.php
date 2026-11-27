<?php

namespace Condoedge\Ai\Eval;

use Illuminate\Support\Str;

/**
 * The suites (config ai.eval.suites_path, resources/ai-eval by default), two kinds side by side in each folder:
 *  - a PHP file returning ['repeat', 'conversations'], read with $fixture in scope, whose expected
 *    values may be closures computed from the application's own data at grading time;
 *  - a JSON file of conversations as the conversation transcript's "Copy as eval case" writes them
 *    ({"conversations":[{"id","tags","turns":[{"ask","expect","standard"}]}]}): real conversations
 *    kept as tests. A conversation without a 'user' is asked as the fixture's 'user'.
 * Each folder's fixture.php gives the defaults (ids of this environment's data); a run stores its
 * ids, and every job re-reads the suite with them.
 *
 * A run may also carry its own conversations (pasted on the test page, or --ask): they are read
 * the same way as a JSON suite (casesOf).
 */
class EvalSuites
{
    /** ai-eval's names for what a turn must show, as the panel's grade keys. */
    protected const EXPECT_KEYS = ['retrieves_doc' => 'reads'];

    public static function path(string $suite = ''): string
    {
        if (!$suite) {
            return EvalPanel::suitesPath();
        }

        $json = EvalPanel::suitesPath($suite.'.json');

        return file_exists($json) ? $json : EvalPanel::suitesPath($suite.'.php');
    }

    public static function all(): array
    {
        return collect(array_merge(glob(static::path().'/*/*.php'), glob(static::path().'/*/*.json')))
            ->reject(fn ($file) => basename($file) == 'fixture.php')
            ->map(fn ($file) => Str::after(preg_replace('/\.(php|json)$/', '', str_replace('\\', '/', $file)), str_replace('\\', '/', static::path()).'/'))
            ->unique()->sort()->values()->all();
    }

    public static function exists(string $suite): bool
    {
        return in_array($suite, static::all(), true);
    }

    public static function isJson(string $suite): bool
    {
        return str_ends_with(static::path($suite), '.json');
    }

    public static function defaultFixture(string $suite): array
    {
        $file = dirname(static::path($suite)).'/fixture.php';

        return file_exists($file) ? require $file : [];
    }

    public static function fixtureKeys(string $suite): array
    {
        if (static::isJson($suite)) {
            return static::needsUser(static::load($suite, [])) ? ['user'] : [];
        }

        $file = file_get_contents(static::path($suite));
        $keys = array_keys(static::defaultFixture($suite));

        // A suite that reads $fixture[$role] names no key in its text: it may use any of them.
        if (str_contains($file, '$fixture[$')) {
            return $keys;
        }

        preg_match_all('/\$fixture\[\'([a-z_]+)\'\]/', $file, $matches);

        return array_values(array_intersect($keys, $matches[1]));
    }

    public static function fixture(string $suite, array $overrides = []): array
    {
        return array_merge(static::defaultFixture($suite), array_filter($overrides, fn ($value) => $value !== null && $value !== ''));
    }

    public static function load(string $suite, array $fixture): array
    {
        abort_unless(static::exists($suite), 404);

        if (static::isJson($suite)) {
            return static::fromText(file_get_contents(static::path($suite)));
        }

        return (static function (string $__file, array $fixture) {
            return require $__file;
        })(static::path($suite), $fixture);
    }

    /**
     * Every conversation to run, repeated as the suite asks, optionally only those with a tag.
     *
     * @return array<int, array{case: array, repetition: int}>
     */
    public static function cases(string $suite, array $fixture, ?string $tag = null, ?int $repeat = null): array
    {
        return static::casesOf(static::load($suite, $fixture), $fixture, $tag, $repeat, $suite);
    }

    /** The same, for conversations given as they are: a JSON suite, a pasted conversation. */
    public static function casesOf(array $definition, array $fixture, ?string $tag = null, ?int $repeat = null, string $where = ''): array
    {
        $conversations = static::normalize($definition['conversations'] ?? [], $fixture);

        foreach ($conversations as $case) {
            foreach ($case['turns'] as $index => $turn) {
                AnswerGrader::assertKnown($turn['grade'] ?? [], ltrim($where.' › '.$case['id'].' › '.__('ai::eval.turn-number', ['turn' => $index + 1]), ' ›'));
            }
        }

        return collect($conversations)
            ->filter(fn ($case) => !$tag || in_array($tag, $case['tags'] ?? [], true))
            ->flatMap(fn ($case) => collect(range(1, max(1, (int) ($repeat ?? $case['repeat'] ?? $definition['repeat'] ?? 1))))
                ->map(fn ($repetition) => ['case' => $case, 'repetition' => $repetition]))
            ->values()->all();
    }

    /**
     * Conversations from text: the JSON of "Copy as eval case" (or one conversation's JSON), or
     * plain questions, one per line, asked in turn in one conversation.
     *
     * @throws \InvalidArgumentException when the text holds no question
     */
    public static function fromText(string $text): array
    {
        $text = trim($text);

        if (str_starts_with($text, '{') || str_starts_with($text, '[')) {
            $json = json_decode($text, true);

            if (!is_array($json)) {
                throw new \InvalidArgumentException(__('ai::eval.conversation-bad-json', ['error' => json_last_error_msg()]));
            }

            $definition = isset($json['conversations']) ? $json : ['conversations' => array_is_list($json) ? $json : [$json]];
        } else {
            $questions = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text))));
            $definition = ['conversations' => $questions ? [['id' => 'conversation', 'turns' => array_map(fn ($ask) => ['ask' => $ask], $questions)]] : []];
        }

        if (!collect($definition['conversations'])->contains(fn ($case) => !empty($case['turns']))) {
            throw new \InvalidArgumentException(__('ai::eval.conversation-empty'));
        }

        return $definition;
    }

    public static function needsUser(array $definition): bool
    {
        return collect($definition['conversations'] ?? [])->contains(fn ($case) => empty($case['user']));
    }

    /**
     * One shape for every source: an id, the user who asks, and turns of 'ask', 'grade' (with
     * ai-eval's 'expect' read as grade keys), 'standard' and 'page_context'.
     */
    protected static function normalize(array $conversations, array $fixture): array
    {
        return collect(array_values($conversations))->map(function ($case, $index) use ($fixture) {
            $case['id'] = (string) ($case['id'] ?? 'conversation-'.($index + 1));
            $case['user'] = $case['user'] ?? $fixture['user'] ?? null;
            $case['turns'] = collect(array_values($case['turns'] ?? []))->map(function ($turn) {
                $turn['ask'] = (string) ($turn['ask'] ?? $turn['question'] ?? '');
                $turn['grade'] = ($turn['grade'] ?? []) + static::expectAsGrade((array) ($turn['expect'] ?? []));
                unset($turn['expect'], $turn['question']);

                return $turn;
            })->filter(fn ($turn) => $turn['ask'] !== '')->values()->all();

            return $case;
        })->filter(fn ($case) => $case['turns'])->values()->all();
    }

    protected static function expectAsGrade(array $expect): array
    {
        $grade = [];

        foreach ($expect as $key => $value) {
            $key = static::EXPECT_KEYS[$key] ?? $key;
            // A document is named by its file id here; a name from elsewhere is not known here, so not checked.
            $grade[$key] = $key == 'reads' ? array_values(array_filter(array_map(fn ($id) => is_numeric($id) ? (int) $id : 0, (array) $value))) : $value;
        }

        return $grade;
    }

    /**
     * Who among the askers of these cases the assistant refuses, by the host (EvalHost::closedTo):
     * every answer of a run would be the same refusal, so it is not started.
     *
     * @return array<string>
     */
    public static function closedTo(array $cases): array
    {
        return EvalPanel::host()->closedTo(collect($cases)->pluck('case.user')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all());
    }
}
