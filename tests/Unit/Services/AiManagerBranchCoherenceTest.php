<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Branch coherence in the document fan-out (eval p17-B rights t0).
 *
 * The law file titles a division the same way in two chapters. The plain question
 * embeds closest to that leaf title, so its best passage was a passage of the
 * OTHER chapter — and the evidence floor (the question angle's best passage always
 * keeps a slot) pinned it: two of five passages came from the chapter that does
 * not apply, and the answer cited one of its articles as the rule.
 *
 * When the candidate passages of one file sit in different branches that hold a
 * same-titled section, the fan-out keeps only the branch carrying the most vote
 * weight (PassageBranchCoherence), and the evidence floor applies INSIDE it.
 */
class AiManagerBranchCoherenceTest extends TestCase
{
    use FileFanOutHarness;

    private const FILE = 'physical:rules/aircraft.md';

    private const QUESTION = 'What are my main duties as the operator here?';

    private const LEASED = ['AIRCRAFT RULES', 'PART II — LEASED AIRCRAFT', 'SECTION II — DUTIES OF THE OPERATOR'];
    private const OWNED = ['AIRCRAFT RULES', 'PART III — OWNED AIRCRAFT', 'SECTION IV — DUTIES OF THE OPERATOR'];
    private const OWNED_RESERVE = ['AIRCRAFT RULES', 'PART III — OWNED AIRCRAFT', 'SECTION V — MAINTENANCE RESERVE'];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'logging.default' => 'null',
            'ai.file_context.enabled' => true,
            'ai.file_context.min_relevance_score' => 0.4,
            'ai.file_context.max_references' => 5,
            'ai.file_context.max_chunks_per_file' => 5,
            'ai.file_context.context_window' => 2,
            'ai.file_context.recall_floor' => 0,
        ]);
        $this->searched = [];
        $this->trace = [];
        // Chunks 8, 11, 14: the leased part. 58, 61, 64: the same-titled section of
        // the owned part. 74, 82: another section of the owned part.
        $this->headings = [
            8 => self::LEASED, 11 => self::LEASED, 14 => self::LEASED,
            58 => self::OWNED, 61 => self::OWNED, 64 => self::OWNED,
            74 => self::OWNED_RESERVE, 82 => self::OWNED_RESERVE,
        ];
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function qu(string $clarified = 'What are the duties of the operator of an owned aircraft?'): QueryUnderstandingResult
    {
        return new QueryUnderstandingResult(
            searchQuery: 'operator duties',
            questionType: 'knowledge_query',
            clarifiedQuestion: $clarified,
            searchTags: ['operator', 'duties'],
            language: 'en',
        );
    }

    /** The p17 shape: the plain wordings prefer the other part's same-titled section. */
    private function p17Rankings(): \Closure
    {
        return fn (string $query): array => [self::FILE => match ($query) {
            'operator duties' => [8 => 0.65, 58 => 0.63, 61 => 0.59, 14 => 0.58, 74 => 0.50],
            self::QUESTION => [8 => 0.62, 11 => 0.61, 58 => 0.60, 61 => 0.59, 64 => 0.55],
            default => [61 => 0.62, 58 => 0.61, 64 => 0.60, 74 => 0.58, 82 => 0.56, 11 => 0.55],
        }];
    }

    /** @test */
    public function the_minority_branch_with_a_same_titled_section_is_dropped(): void
    {
        $this->rankings = $this->p17Rankings();

        $files = $this->fanOut(self::QUESTION, $this->qu());

        $indexes = array_column($files, 'chunk_index');
        $this->assertSame([], array_values(array_intersect($indexes, [8, 11, 14])), 'no passage of the leased part');
        $this->assertContains(58, $indexes);
        $this->assertContains(61, $indexes);
        $this->assertCount(5, $indexes, 'the freed slots go to passages of the kept branch');
        $this->assertContains(74, $indexes);
    }

    /** @test */
    public function the_evidence_floor_does_not_re_admit_a_passage_of_the_dropped_branch(): void
    {
        // Budget 3. The question's own best passage is 8 (leased); the search query
        // and the clarified question are about the owned part. The floor must then
        // hold the question's best passage of the KEPT branch (58), never 8.
        config(['ai.file_context.max_chunks_per_file' => 3]);
        $this->rankings = fn (string $query): array => [self::FILE => match ($query) {
            'operator duties' => [58 => 0.65, 8 => 0.63, 61 => 0.59],
            self::QUESTION => [8 => 0.62, 11 => 0.61, 58 => 0.60],
            default => [61 => 0.62, 64 => 0.61, 74 => 0.60],
        }];

        $files = $this->fanOut(self::QUESTION, $this->qu());

        $this->assertSame([58, 61, 64], array_column($files, 'chunk_index'));
        $this->assertSame([self::FILE => 58], $this->trace['question_passages']);
    }

    /** @test */
    public function the_branch_the_angles_lead_with_is_kept_even_when_the_other_one_has_more_passages(): void
    {
        // A question about the LEASED part: every angle leads with it and fills its
        // remaining places with the larger owned part (measured live on the
        // mirror-image question: counting candidates kept the wrong part).
        $this->rankings = fn (string $query): array => [self::FILE => $query === self::QUESTION
            ? [11 => 0.70, 8 => 0.69, 58 => 0.66, 64 => 0.63, 82 => 0.62]
            : [8 => 0.70, 11 => 0.69, 58 => 0.66, 61 => 0.63, 74 => 0.62]];

        $files = $this->fanOut(self::QUESTION, $this->qu('What are the duties of the operator of a leased aircraft?'));

        $this->assertEqualsCanonicalizing([8, 11], array_column($files, 'chunk_index'));
        $this->assertSame('PART II — LEASED AIRCRAFT', $this->trace['branch_coherence'][self::FILE]['kept']);
    }

    /** @test */
    public function the_trace_names_the_kept_and_the_dropped_branch(): void
    {
        $this->rankings = $this->p17Rankings();

        $this->fanOut(self::QUESTION, $this->qu());

        $verdict = $this->trace['branch_coherence'][self::FILE];
        $this->assertSame('PART III — OWNED AIRCRAFT', $verdict['kept']);
        $this->assertSame(['PART II — LEASED AIRCRAFT'], $verdict['dropped_branches']);
        $this->assertEqualsCanonicalizing([8, 11, 14], $verdict['dropped']);
        $this->assertGreaterThan($verdict['weights']['PART II — LEASED AIRCRAFT'], $verdict['weights']['PART III — OWNED AIRCRAFT']);
    }

    /** @test */
    public function branches_without_a_same_titled_section_are_both_kept(): void
    {
        // The question spans two parts whose sections are titled differently.
        $this->headings[8] = $this->headings[11] = $this->headings[14] = ['AIRCRAFT RULES', 'PART II — LEASED AIRCRAFT', 'SECTION IV — END OF THE LEASE'];
        $this->rankings = $this->p17Rankings();

        $files = $this->fanOut(self::QUESTION, $this->qu());

        $this->assertContains(8, array_column($files, 'chunk_index'));
        $this->assertArrayNotHasKey('branch_coherence', $this->trace);
    }

    /** @test */
    public function a_file_without_heading_paths_is_untouched(): void
    {
        $this->headings = [];
        $this->rankings = $this->p17Rankings();

        $files = $this->fanOut(self::QUESTION, $this->qu());

        $this->assertContains(8, array_column($files, 'chunk_index'));
        $this->assertSame([self::FILE => 8], $this->trace['question_passages']);
        $this->assertArrayNotHasKey('branch_coherence', $this->trace);
    }

    /** @test */
    public function a_split_vote_keeps_both_branches(): void
    {
        // Every angle ranks one passage of each part, alternating which leads.
        $this->rankings = fn (string $query): array => [self::FILE => $query === self::QUESTION
            ? [8 => 0.62, 58 => 0.60]
            : [58 => 0.62, 8 => 0.60]];

        $files = $this->fanOut(self::QUESTION, new QueryUnderstandingResult(searchQuery: 'operator duties', questionType: 'knowledge_query', language: 'en'));

        $this->assertEqualsCanonicalizing([8, 58], array_column($files, 'chunk_index'));
        $this->assertArrayNotHasKey('branch_coherence', $this->trace);
    }

    /** @test */
    public function the_required_share_of_the_vote_is_configurable_and_zero_switches_the_rule_off(): void
    {
        config(['ai.file_context.branch_coherence_share' => 0]);
        $this->rankings = $this->p17Rankings();

        $files = $this->fanOut(self::QUESTION, $this->qu());

        $this->assertContains(8, array_column($files, 'chunk_index'));
        $this->assertArrayNotHasKey('branch_coherence', $this->trace);
    }

    /** @test */
    public function an_article_the_user_named_is_never_dropped_with_its_branch(): void
    {
        // "art. 8" names the passage by number (the harness serves chunk 8 for it):
        // a structural match the user asked for, whatever branch it sits in.
        $question = 'What does art. 8 add to my duties as the operator?';
        $this->rankings = fn (string $query): array => [self::FILE => $query === $question
            ? [8 => 0.62, 11 => 0.61, 58 => 0.60, 61 => 0.59, 64 => 0.55]
            : [61 => 0.62, 58 => 0.61, 64 => 0.60, 74 => 0.58, 82 => 0.56, 11 => 0.55]];

        $files = $this->fanOut($question, $this->qu());

        $indexes = array_column($files, 'chunk_index');
        $this->assertSame(8, $indexes[0], 'the named article leads');
        $this->assertNotContains(11, $indexes, 'the rest of its branch still goes');
    }

    /** @test */
    public function the_heading_path_served_the_merge_and_does_not_reach_the_prompt_context(): void
    {
        $this->rankings = $this->p17Rankings();

        $files = $this->fanOut(self::QUESTION, $this->qu());

        $this->assertArrayNotHasKey('heading_path', $files[0]);
        $this->assertStringContainsString('PART III — OWNED AIRCRAFT', $files[0]['heading'], 'the header text stays');
    }

    /** @test */
    public function the_default_share_is_shipped_in_the_package_config(): void
    {
        $defaults = require __DIR__ . '/../../../config/ai.php';

        $this->assertEqualsWithDelta(0.55, (float) $defaults['file_context']['branch_coherence_share'], 1e-9);
    }
}
