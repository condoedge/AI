<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Eval;

use Condoedge\Ai\Services\Eval\EvalSuiteLoader;
use PHPUnit\Framework\TestCase;

/**
 * Suite parsing: the loader must understand BOTH schemas —
 *
 *   1. {"conversations":[{"id":..., "turns":[{"ask":...,"expect":...,"standard":...}]}]}
 *   2. legacy flat {"turns":[{"question":...}]}  → ONE conversation of those turns
 *
 * — accept "question" as an alias of "ask", and FAIL FAST on unknown expect keys
 * (a typo'd gate silently passing would be worse than no gate at all).
 */
class EvalSuiteLoaderTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function suiteFile(array $data): string
    {
        $path = sys_get_temp_dir() . '/eval-suite-' . uniqid() . '.json';
        file_put_contents($path, json_encode($data));
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @test */
    public function it_parses_the_conversations_schema_with_expect_and_standard(): void
    {
        $path = $this->suiteFile([
            'conversations' => [
                [
                    'id' => 'team-roster-export',
                    'role' => 'manager',
                    'turns' => [
                        [
                            'ask' => 'Give me all my team members.',
                            'expect' => ['min_rows' => 2, 'columns_include' => ['name', 'email']],
                            'standard' => 'Lists the CURRENT team members.',
                        ],
                        [
                            'ask' => 'Now give me that as an Excel.',
                            'expect' => ['produces_file' => 'excel', 'previous_result_available' => true],
                            'standard' => 'Produces an Excel of the SAME members.',
                        ],
                    ],
                ],
            ],
        ]);

        $conversations = (new EvalSuiteLoader())->load($path);

        $this->assertCount(1, $conversations);
        $this->assertSame('team-roster-export', $conversations[0]['id']);
        $this->assertSame('manager', $conversations[0]['role']);
        $this->assertCount(2, $conversations[0]['turns']);
        $this->assertSame('Give me all my team members.', $conversations[0]['turns'][0]['ask']);
        $this->assertSame(['min_rows' => 2, 'columns_include' => ['name', 'email']], $conversations[0]['turns'][0]['expect']);
        $this->assertSame('Lists the CURRENT team members.', $conversations[0]['turns'][0]['standard']);
        $this->assertSame('excel', $conversations[0]['turns'][1]['expect']['produces_file']);
    }

    /** @test */
    public function it_treats_a_legacy_flat_turns_file_as_one_conversation(): void
    {
        $path = $this->suiteFile([
            'turns' => [
                ['question' => 'How many units are there?', 'tags' => ['unit']],
                ['question' => 'And how many are rented?'],
            ],
        ]);

        $conversations = (new EvalSuiteLoader())->load($path);

        $this->assertCount(1, $conversations);
        $this->assertCount(2, $conversations[0]['turns']);
        // Turns of a legacy file thread IN ORDER as one conversation.
        $this->assertSame('How many units are there?', $conversations[0]['turns'][0]['ask']);
        $this->assertSame('And how many are rented?', $conversations[0]['turns'][1]['ask']);
        $this->assertSame(['unit'], $conversations[0]['turns'][0]['tags']);
    }

    /** @test */
    public function it_accepts_question_as_an_alias_of_ask_in_the_conversations_schema(): void
    {
        $path = $this->suiteFile([
            'conversations' => [
                ['id' => 'c1', 'turns' => [['question' => 'Who owns unit 42?']]],
            ],
        ]);

        $conversations = (new EvalSuiteLoader())->load($path);

        $this->assertSame('Who owns unit 42?', $conversations[0]['turns'][0]['ask']);
    }

    /** @test */
    public function it_fails_fast_on_an_unknown_expect_key(): void
    {
        $path = $this->suiteFile([
            'conversations' => [
                ['id' => 'c1', 'turns' => [[
                    'ask' => 'Give me my team.',
                    'expect' => ['minimum_rows' => 2],
                ]]],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/minimum_rows/');

        (new EvalSuiteLoader())->load($path);
    }

    /** @test */
    public function it_accepts_the_disambiguation_column_present_gate_and_validates_its_type(): void
    {
        $path = $this->suiteFile([
            'conversations' => [
                ['id' => 'c1', 'turns' => [[
                    'ask' => 'How much does unit 310 owe right now?',
                    'expect' => ['min_rows' => 1, 'disambiguation_column_present' => true],
                ]]],
            ],
        ]);

        $conversations = (new EvalSuiteLoader())->load($path);
        $this->assertTrue($conversations[0]['turns'][0]['expect']['disambiguation_column_present']);

        // Non-boolean value fails fast at load time.
        $bad = $this->suiteFile([
            'conversations' => [
                ['id' => 'c1', 'turns' => [[
                    'ask' => 'How much does unit 310 owe right now?',
                    'expect' => ['disambiguation_column_present' => 'yes'],
                ]]],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/disambiguation_column_present/');
        (new EvalSuiteLoader())->load($bad);
    }

    /** @test */
    public function it_passes_a_turn_page_context_through_and_normalizes_absence_to_null(): void
    {
        $path = $this->suiteFile([
            'conversations' => [
                ['id' => 'unit-page-probe', 'turns' => [
                    [
                        'ask' => "Show this unit's payment history.",
                        'page_context' => ['entity_type' => 'Unit', 'entity_id' => 111, 'route' => 'units.show'],
                    ],
                    ['ask' => 'And who owns it?'],
                ]],
            ],
        ]);

        $conversations = (new EvalSuiteLoader())->load($path);

        $this->assertSame(
            ['entity_type' => 'Unit', 'entity_id' => 111, 'route' => 'units.show'],
            $conversations[0]['turns'][0]['page_context']
        );
        // Absent (and empty-object) page_context both normalize to null — an
        // ordinary page-less turn.
        $this->assertNull($conversations[0]['turns'][1]['page_context']);
    }

    /** @test */
    public function it_fails_fast_on_a_list_page_context(): void
    {
        $path = $this->suiteFile([
            'conversations' => [
                ['id' => 'c1', 'turns' => [[
                    'ask' => 'Show this unit.',
                    'page_context' => ['Unit', 111],
                ]]],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/page_context/');

        (new EvalSuiteLoader())->load($path);
    }

    /** @test */
    public function it_fails_fast_on_an_invalid_produces_file_format(): void
    {
        $path = $this->suiteFile([
            'conversations' => [
                ['id' => 'c1', 'turns' => [[
                    'ask' => 'Export it.',
                    'expect' => ['produces_file' => 'docx'],
                ]]],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/produces_file/');

        (new EvalSuiteLoader())->load($path);
    }

    /** @test */
    public function it_fails_when_the_file_is_missing_or_has_neither_schema(): void
    {
        try {
            (new EvalSuiteLoader())->load(sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.json');
            $this->fail('Expected a RuntimeException for a missing file.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        $path = $this->suiteFile(['nothing' => true]);

        $this->expectException(\RuntimeException::class);
        (new EvalSuiteLoader())->load($path);
    }
}
