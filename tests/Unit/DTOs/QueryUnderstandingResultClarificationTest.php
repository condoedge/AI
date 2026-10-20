<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\DTOs;

use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use PHPUnit\Framework\TestCase;

/**
 * The clarify judgment is made AFTER Query Understanding (by the focused
 * ClarityChecker call, only on a first message that names no subject). Its
 * verdict rides the three clarify fields the pipeline's clarify branch already
 * reads, on a COPY of the readonly result.
 *
 * The copy carries the guard the QU parse used to enforce: a follow-up on a
 * previous answer or a broad digest ask can never come out needing clarification.
 */
class QueryUnderstandingResultClarificationTest extends TestCase
{
    /** @test */
    public function the_copy_needs_clarification_and_carries_the_readings_and_the_question(): void
    {
        $result = (new QueryUnderstandingResult())->withClarification(
            ['the amount one unit owes', 'the balance of an account'],
            'Which balance do you mean: what a unit owes, or the balance of an account?'
        );

        $this->assertTrue($result->needsClarification);
        $this->assertSame(['the amount one unit owes', 'the balance of an account'], $result->candidateReadings);
        $this->assertSame('Which balance do you mean: what a unit owes, or the balance of an account?', $result->clarifyingQuestion);
    }

    /** @test */
    public function every_other_field_is_carried_over_unchanged(): void
    {
        $original = new QueryUnderstandingResult(
            entityTypes: ['Unit'],
            personNames: ['Ana'],
            propertyConcepts: ['balance'],
            searchQuery: 'unit balance',
            isPossessive: true,
            intent: 'lookup',
            questionType: 'data_query',
            clarifiedQuestion: 'clarified',
            searchTags: ['a', 'b'],
            corpusTranslations: ['fr' => 'sonde'],
            registerExpansions: ['probe'],
            exportFormat: 'pdf',
            language: 'fr',
            failed: true,
            navigationTarget: 'own_team',
            subjectLabel: 'Solde',
        );

        $copy = $original->withClarification(['x', 'y'], 'which?');

        $this->assertNotSame($original, $copy);
        $this->assertFalse($original->needsClarification, 'the original is untouched');

        $expected = get_object_vars($original);
        $expected['needsClarification'] = true;
        $expected['candidateReadings'] = ['x', 'y'];
        $expected['clarifyingQuestion'] = 'which?';
        $this->assertSame($expected, get_object_vars($copy));
    }

    /** @test */
    public function readings_are_trimmed_de_blanked_and_capped_at_four(): void
    {
        $result = (new QueryUnderstandingResult())->withClarification(
            ['  one ', '', 42, ['nested'], 'two', 'three', 'four', 'five'],
            "  which\n one?  "
        );

        $this->assertSame(['one', 'two', 'three', 'four'], $result->candidateReadings);
        $this->assertSame('which one?', $result->clarifyingQuestion);
    }

    /** @test */
    public function a_follow_up_or_a_digest_ask_can_never_come_out_needing_clarification(): void
    {
        foreach ([
            new QueryUnderstandingResult(followUpReference: true),
            new QueryUnderstandingResult(digestRequest: true),
        ] as $result) {
            $copy = $result->withClarification(['x', 'y'], 'which?');

            $this->assertSame($result, $copy);
            $this->assertFalse($copy->needsClarification);
        }
    }

    /** @test */
    public function nothing_to_ask_leaves_the_result_as_it_was(): void
    {
        $result = new QueryUnderstandingResult();

        $this->assertSame($result, $result->withClarification([], '   '));
        $this->assertSame($result, $result->withClarification(['  ', 7], ''));
    }
}
