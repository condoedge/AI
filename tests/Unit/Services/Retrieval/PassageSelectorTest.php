<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\Retrieval\PassageSelector;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * PassageSelector: ONE small call that reads the question, the passages the
 * search selected and the candidates it ranked too low, and reports which
 * candidates a correct answer still needs. The model reports; the caller decides.
 *
 * Measured (eval p16–p21 tear-down, 14 stored turns): the article that states the
 * general rule the question falls under never scored over the relevance floor on
 * any phrasing (cosine 0.28 for the question itself) — an inference gap, not a
 * vocabulary one — and the small model named it in 12 of 14.
 */
class PassageSelectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return list<array{source: string, heading: string, text: string}> */
    private function candidates(int $count = 3): array
    {
        $candidates = [];
        for ($n = 0; $n < $count; $n++) {
            $number = 1040 + $n;
            $candidates[] = ['source' => 'code.md', 'heading' => "PART {$n}", 'text' => "**{$number}.** Rule number {$n} of the document."];
        }

        return $candidates;
    }

    /** @param array<string, mixed> $reply */
    private function selector(array $reply, array $config = [], ?array &$seen = null): PassageSelector
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(function (array $messages, array $options = []) use ($reply, &$seen) {
            $seen = ['messages' => $messages, 'options' => $options];

            return $reply;
        });

        return new PassageSelector($llm, $config);
    }

    /** @test */
    public function it_returns_the_positions_of_the_candidates_the_model_named(): void
    {
        $result = $this->selector(['needed' => ['AC', 'AA']])->select('Can I do this?', '', [], $this->candidates(), 2);

        $this->assertSame([2, 0], $result['picked'], 'in the order reported');
        $this->assertSame(0, $result['unknown']);
    }

    /** @test */
    public function candidates_are_shown_under_opaque_ids_never_under_a_number_of_their_own(): void
    {
        $this->selector(['needed' => []], [], $seen)->select('Can I do this?', '', [], $this->candidates(), 2);

        $user = $seen['messages'][1]['content'];
        $this->assertStringContainsString('[AA] code.md — PART 0', $user);
        $this->assertStringContainsString('[AC] code.md — PART 2', $user);
        $this->assertDoesNotMatchRegularExpression('/\[\d+\]/', $user, 'no numeric id a number inside a text could be mistaken for');
        $this->assertSame(0.0, $seen['options']['temperature']);
    }

    /** @test */
    public function an_id_that_is_not_in_the_pool_is_dropped_and_counted(): void
    {
        // The model answered with a number it read inside a candidate's text.
        $result = $this->selector(['needed' => ['1041', 'AB', 'ZZ', 7]])->select('Can I do this?', '', [], $this->candidates(), 3);

        $this->assertSame([1], $result['picked']);
        $this->assertSame(3, $result['unknown']);
    }

    /** @test */
    public function a_candidate_named_twice_counts_once_and_the_limit_is_enforced(): void
    {
        $result = $this->selector(['needed' => ['AB', 'AB', 'AA', 'AC']])->select('Can I do this?', '', [], $this->candidates(), 2);

        $this->assertSame([1, 0], $result['picked']);
    }

    /** @test */
    public function the_model_may_report_that_nothing_is_missing(): void
    {
        $result = $this->selector(['needed' => []])->select('Can I do this?', '', [], $this->candidates(), 2);

        $this->assertSame([], $result['picked']);
        $this->assertSame(0, $result['unknown']);
    }

    /** @test */
    public function a_reply_without_the_list_is_a_failure_not_an_empty_selection(): void
    {
        $this->assertNull($this->selector(['ids' => ['AA']])->select('Can I do this?', '', [], $this->candidates(), 2));
        $this->assertNull($this->selector(['needed' => 'AA'])->select('Can I do this?', '', [], $this->candidates(), 2));
    }

    /** @test */
    public function a_provider_failure_is_a_failure_and_never_throws(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->andThrow(new \RuntimeException('429'));

        $this->assertNull((new PassageSelector($llm))->select('Can I do this?', '', [], $this->candidates(), 2));
    }

    /** @test */
    public function no_call_is_made_without_a_question_candidates_or_a_limit(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldNotReceive('chatJson');
        $selector = new PassageSelector($llm);

        $this->assertNull($selector->select('  ', '', [], $this->candidates(), 2));
        $this->assertNull($selector->select('Can I do this?', '', [], [], 2));
        $this->assertNull($selector->select('Can I do this?', '', [], $this->candidates(), 0));
    }

    /** @test */
    public function a_candidate_text_is_bounded_and_the_selected_passages_are_summarised(): void
    {
        $long = [['source' => 'code.md', 'heading' => 'PART 9', 'text' => str_repeat('word ', 400)]];
        $selected = [['heading' => 'PART 1 › Section 2', 'parts' => [str_repeat('first ', 300), str_repeat('second ', 300)]]];

        $this->selector(['needed' => []], ['excerpt_chars' => 120, 'selected_chars' => 80], $seen)
            ->select('Can I do this?', 'Can I do this in my own unit?', $selected, $long, 1);

        $user = $seen['messages'][1]['content'];
        $this->assertStringContainsString('Can I do this in my own unit?', $user, 'the conversation-resolved form goes in too');
        $this->assertStringContainsString('PART 1 › Section 2', $user);
        $this->assertStringContainsString('first first', $user);
        $this->assertStringContainsString('second second', $user, 'the start of every chunk of a selected passage is shown');
        $this->assertLessThan(120 + 2 * 80 + 400, mb_strlen($user));
    }

    /** @test */
    public function the_prompt_carries_only_the_hosts_own_description_of_its_domain(): void
    {
        $this->selector(['needed' => []], [], $seen)->select('Can I do this?', '', [], $this->candidates(), 2);
        $bare = $seen['messages'][0]['content'];

        $this->selector(
            ['needed' => []],
            ['application' => 'An aircraft maintenance tracker.', 'corpus_register' => 'aviation regulation terminology (airworthiness directive)'],
            $seen
        )->select('Can I do this?', '', [], $this->candidates(), 2);
        $described = $seen['messages'][0]['content'];

        $this->assertStringContainsString('An aircraft maintenance tracker.', $described);
        $this->assertStringContainsString('airworthiness directive', $described);
        $this->assertStringNotContainsString('aircraft', $bare);
        $this->assertStringNotContainsStringIgnoringCase('co-owner', $bare, 'the package ships no domain vocabulary');
    }

    /** @test */
    public function it_reports_the_token_usage_of_its_call(): void
    {
        $selector = new PassageSelector(new ScriptedUsageLlm(json: ['needed' => ['AA']]));

        $result = $selector->select('Can I do this?', '', [], $this->candidates(), 2);

        $this->assertSame([0], $result['picked']);
        $this->assertArrayHasKey('usage', $result);
    }
}
