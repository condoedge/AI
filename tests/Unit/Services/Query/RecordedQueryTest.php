<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Services\Query\RecordedQuery;
use PHPUnit\Framework\TestCase;

class RecordedQueryTest extends TestCase
{
    private const CYPHER = 'MATCH (b:Bill) RETURN b.total_amount AS total';

    /** @test */
    public function a_conversation_without_a_query_has_none(): void
    {
        $this->assertNull(RecordedQuery::fromConversation([]));
        $this->assertNull(RecordedQuery::fromConversation(['last_cypher_query' => '  ']));
    }

    /** @test */
    public function the_same_question_is_recognised_whatever_its_case_and_punctuation(): void
    {
        $recorded = RecordedQuery::fromConversation(['last_cypher_query' => self::CYPHER, 'last_cypher_question' => 'Combien coûtent les frais de Bell ?']);

        $this->assertTrue($recorded->answers('combien coûtent les frais de bell'));
        $this->assertFalse($recorded->answers('Combien coûtent les frais de Telus ?'));
    }

    /** @test */
    public function a_snapshot_from_before_the_question_was_recorded_is_never_a_repeat(): void
    {
        $this->assertFalse(RecordedQuery::fromConversation(['last_cypher_query' => self::CYPHER])->answers('Anything'));
    }

    /** @test */
    public function only_pii_tokens_ride_along_never_a_forged_scope_parameter(): void
    {
        $recorded = RecordedQuery::fromConversation([
            'last_cypher_query' => self::CYPHER,
            'last_cypher_parameters' => ['pii_0123456789ab' => 'a@b.c', 'ai_team_ids' => [99], 'other' => 'x'],
        ]);

        $this->assertSame(['pii_0123456789ab' => 'a@b.c'], $recorded->asQueryResult(RecordedQuery::REASON_GENERATOR)->parameters);
    }

    /** @test */
    public function as_a_query_result_it_says_where_it_came_from(): void
    {
        $result = RecordedQuery::fromConversation(['last_cypher_query' => self::CYPHER])->asQueryResult(RecordedQuery::REASON_SAME_QUESTION);

        $this->assertSame(self::CYPHER, $result->cypher);
        $this->assertSame(RecordedQuery::SOURCE, $result->metadata['source']);
        $this->assertSame(RecordedQuery::REASON_SAME_QUESTION, $result->metadata['recorded_query_reason']);
    }
}
