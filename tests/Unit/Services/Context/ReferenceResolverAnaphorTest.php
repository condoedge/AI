<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Tests\TestCase;

/**
 * G2/L5 item A — anaphor corruption regression (iteration-11 verdict,
 * docs/eval/2026-07-23-iter11-verdict.md).
 *
 * Observed live + eval, repeatedly: the resolver REWROTE rather than resolved —
 * textual pronoun substitution turned "Ok, but could you add their last login
 * to it?" into "... add their last login to teams?" (the imperative's OBJECT
 * changed from the previous RESULT to the Team entity), and "export it" into
 * "export teams". The enriched question then misrouted QU/generation.
 *
 * CONTRACT PINNED HERE (resolve, don't rewrite): the user's wording is
 * preserved VERBATIM in enriched_question on every path. The resolution
 * rides exclusively in the STRUCTURED fields (resolved_entity / operation /
 * base_query) plus the conversation-context snapshot the pipeline already
 * threads (last_cypher_query, focused_entity) — never in a mutated question.
 */
class ReferenceResolverAnaphorTest extends TestCase
{
    private ReferenceResolver $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ReferenceResolver();
    }

    private function teamContext(): array
    {
        return [
            'focused_entity' => 'Team',
            'mentioned_entities' => ['Team'],
            'last_cypher_query' => 'MATCH (t:Team)-[:HAS_MEMBER]->(u:User) RETURN u.name, u.email',
        ];
    }

    /** @test */
    public function augmentation_follow_up_is_never_textually_rewritten(): void
    {
        $question = 'Ok, but could you add their last login to it?';

        $result = $this->resolver->resolve($question, $this->teamContext());

        // The exact live corruption: "to it" -> "to teams".
        $this->assertStringNotContainsString('to teams', strtolower($result['enriched_question']));
        // Resolve, don't rewrite: the ask survives verbatim.
        $this->assertSame($question, $result['enriched_question']);
        // The resolution is still delivered — structurally.
        $this->assertTrue($result['resolved']);
        $this->assertSame('Team', $result['resolved_entity']);
    }

    /** @test */
    public function export_follow_up_is_never_textually_rewritten(): void
    {
        $question = 'Could you export it?';

        $result = $this->resolver->resolve($question, $this->teamContext());

        // The other live corruption: "export it" -> "export teams".
        $this->assertStringNotContainsString('export teams', strtolower($result['enriched_question']));
        $this->assertSame($question, $result['enriched_question']);
        $this->assertSame('Team', $result['resolved_entity']);
    }

    /** @test */
    public function and_fragments_keep_the_users_words(): void
    {
        $question = 'and those in Sales team?';

        $result = $this->resolver->resolve($question, [
            'focused_entity' => 'Customer',
            'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        $this->assertSame($question, $result['enriched_question']);
        $this->assertSame('Customer', $result['resolved_entity']);
    }

    /** @test */
    public function resolution_without_context_still_returns_the_question_verbatim(): void
    {
        $result = $this->resolver->resolve('show them', []);

        $this->assertFalse($result['resolved']);
        $this->assertSame('show them', $result['enriched_question']);
    }
}
