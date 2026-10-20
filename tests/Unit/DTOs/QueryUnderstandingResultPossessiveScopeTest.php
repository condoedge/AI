<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\DTOs;

use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use PHPUnit\Framework\TestCase;

/**
 * "My unit" and "our meetings" are both possessive, but only the first asks about
 * records linked to the asker in person. The result carries the verified words and
 * the scope next to the derived boolean, and every copy keeps them.
 */
class QueryUnderstandingResultPossessiveScopeTest extends TestCase
{
    /** @test */
    public function the_neutral_result_states_no_owner(): void
    {
        foreach ([new QueryUnderstandingResult(), QueryUnderstandingResult::empty('What is my balance?'), QueryUnderstandingResult::failed()] as $result) {
            $this->assertFalse($result->isPossessive);
            $this->assertSame([], $result->possessiveWords);
            $this->assertNull($result->possessiveScope);
            $this->assertFalse($result->isPersonalPossessive());
            $this->assertFalse($result->isCollectivePossessive());
        }
    }

    /** @test */
    public function personal_and_collective_possession_are_told_apart(): void
    {
        $personal = new QueryUnderstandingResult(isPossessive: true, possessiveWords: ['my'], possessiveScope: QueryUnderstandingResult::SCOPE_PERSONAL);
        $this->assertTrue($personal->isPersonalPossessive());
        $this->assertFalse($personal->isCollectivePossessive());

        $collective = new QueryUnderstandingResult(isPossessive: true, possessiveWords: ['our'], possessiveScope: QueryUnderstandingResult::SCOPE_COLLECTIVE);
        $this->assertFalse($collective->isPersonalPossessive());
        $this->assertTrue($collective->isCollectivePossessive());
    }

    /** @test A possessive question whose scope is unknown keeps the behaviour it always had: the asker's own records. */
    public function an_unknown_scope_reads_as_personal(): void
    {
        $unknown = new QueryUnderstandingResult(isPossessive: true);

        $this->assertTrue($unknown->isPersonalPossessive());
        $this->assertFalse($unknown->isCollectivePossessive());
    }

    /** @test A scope without the verified boolean is nothing. */
    public function a_scope_alone_is_not_possession(): void
    {
        $stray = new QueryUnderstandingResult(isPossessive: false, possessiveScope: QueryUnderstandingResult::SCOPE_COLLECTIVE);

        $this->assertFalse($stray->isPersonalPossessive());
        $this->assertFalse($stray->isCollectivePossessive());
    }

    /** @test */
    public function every_copy_carries_the_fields(): void
    {
        $original = new QueryUnderstandingResult(
            entityTypes: ['Meeting'],
            isPossessive: true,
            possessiveWords: ['our'],
            possessiveScope: QueryUnderstandingResult::SCOPE_COLLECTIVE,
        );

        foreach ([
            'asFollowUpOnPreviousResult' => $original->asFollowUpOnPreviousResult(),
            'withDocumentProbes' => $original->withDocumentProbes(['en' => ['probe']]),
            'withClarification' => $original->withClarification(['x', 'y'], 'which?'),
        ] as $method => $copy) {
            $this->assertNotSame($original, $copy, $method);
            $this->assertTrue($copy->isPossessive, $method);
            $this->assertSame(['our'], $copy->possessiveWords, $method);
            $this->assertSame('collective', $copy->possessiveScope, $method);
            $this->assertTrue($copy->isCollectivePossessive(), $method);
        }
    }

    /** @test */
    public function the_snapshot_reports_them(): void
    {
        $snapshot = (new QueryUnderstandingResult(isPossessive: true, possessiveWords: ['mon'], possessiveScope: 'personal'))->toArray();

        $this->assertTrue($snapshot['is_possessive']);
        $this->assertSame(['mon'], $snapshot['possessive_words']);
        $this->assertSame('personal', $snapshot['possessive_scope']);

        $neutral = (new QueryUnderstandingResult())->toArray();
        $this->assertFalse($neutral['is_possessive']);
        $this->assertSame([], $neutral['possessive_words']);
        $this->assertNull($neutral['possessive_scope']);
    }
}
