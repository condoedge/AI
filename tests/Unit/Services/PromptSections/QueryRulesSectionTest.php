<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\QueryRulesSection;
use PHPUnit\Framework\TestCase;

/**
 * E1: QueryRulesSection rides in the SYSTEM message while the schema/entities/scopes it
 * refers to are emitted in the later USER message. Its rules must therefore name the
 * blocks they depend on ("the GRAPH SCHEMA block") instead of using position words like
 * "above" / "the ... section", which are wrong once the role split moves the data away.
 *
 * Pure string behavior — no container needed.
 */
class QueryRulesSectionTest extends TestCase
{
    private function format(bool $allowWrite = false): string
    {
        return (new QueryRulesSection())->format('q', new PipelineContext(), ['allowWrite' => $allowWrite]);
    }

    /** @test Actions plan B1: record rows carry their id so code can attach the record's link. */
    public function it_asks_record_queries_to_project_the_entity_id(): void
    {
        $output = $this->format();

        $this->assertStringContainsString("ALSO return each record's id", $output);
        $this->assertStringContainsString('u.id AS unit_id', $output);
        $this->assertStringContainsString('including per-record aggregates', $output, 'a grouped sum per unit is still one row per record');
        $this->assertSame(1, substr_count($output, "ALSO return each record's id"), 'stated once');
    }

    /** @test */
    public function it_is_system_role(): void
    {
        $this->assertSame('system', (new QueryRulesSection())->role());
    }

    /** @test */
    public function it_does_not_use_position_words_to_reference_other_blocks(): void
    {
        $output = $this->format();

        // No "above" / "the ... section" references that break under the role split.
        $this->assertStringNotContainsString(' above', $output);
        $this->assertStringNotContainsString('DETECTED ENTITIES section', $output);
    }

    /** @test */
    public function it_names_the_blocks_it_depends_on(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('GRAPH SCHEMA block', $output);
        // Task 1.7: the EXAMPLE ENTITIES block no longer exists (cross-tenant rows),
        // so the rules must not point the model at it.
        $this->assertStringNotContainsString('EXAMPLE ENTITIES', $output);
        $this->assertStringContainsString('DETECTED BUSINESS CONCEPTS block', $output);
        // Task 5.2: the schema cards replaced the DETECTED ENTITIES and ENTITY
        // RELATIONSHIPS blocks — the rules must not point at blocks that no longer exist.
        $this->assertStringNotContainsString('DETECTED ENTITIES', $output);
        $this->assertStringNotContainsString('ENTITY RELATIONSHIPS', $output);
        $this->assertStringContainsString('enum legend on the GRAPH SCHEMA card', $output);
        $this->assertStringContainsString('out/in edges', $output);
    }

    /**
     * @test Task 5.8: the live CondoEdge graph stores dates as ISO-8601 STRINGS
     * ('2021-07-05T04:00:00.000000Z', valueType STRING NOT NULL on every Invoice.due_at);
     * `i.due_at < datetime()` matched 0 of 6006 invoices, `< toString(date())` all 6006.
     */
    public function the_date_rule_matches_the_stored_string_dates(): void
    {
        $output = $this->format();

        // Review M-2: conditional on the card type, not a blanket claim.
        $this->assertStringContainsString('When the card shows String(ISO date)', $output);
        $this->assertStringContainsString('toString(date())', $output);
        $this->assertStringContainsString('never against date()/datetime()', $output);
        $this->assertStringContainsString('A Date/DateTime-typed property compares with date()/datetime()', $output);
        $this->assertStringContainsString('substring(', $output);
        // The day boundary of `< toString(date())` is stated.
        $this->assertStringContainsString('due today counts as NOT yet overdue', $output);
        // Digit-string codes (String(numeric) on the card) are compared quoted.
        $this->assertStringContainsString('String(numeric)', $output);
    }

    /**
     * @test Review M-3: the UNION column-alias rule is generic Cypher correctness —
     * it must exist even with tenant scoping off, so it lives here (exactly once).
     */
    public function it_carries_the_generic_union_column_rule(): void
    {
        $output = $this->format();

        $this->assertSame(1, substr_count($output, 'UNION'), 'one generic UNION rule');
        $this->assertStringContainsString('same column names/aliases', $output);
    }

    /** @test */
    public function it_still_emits_the_core_rule_categories(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('SCHEMA COMPLIANCE', $output);
        $this->assertStringContainsString('OUTPUT FORMAT', $output);
    }

    /** @test */
    public function it_forbids_inventing_edges_from_foreign_keys(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('Do NOT infer relationship traversals from *_id properties', $output);
        $this->assertStringContainsString('current_team_id', $output);
        $this->assertStringContainsString('filter on that property with WHERE', $output);
    }

    /** @test */
    public function it_emits_the_read_only_constraint_when_writes_are_disallowed(): void
    {
        $this->assertStringContainsString('READ-ONLY CONSTRAINT', $this->format(allowWrite: false));
        $this->assertStringNotContainsString('READ-ONLY CONSTRAINT', $this->format(allowWrite: true));
    }

    /** @test */
    public function it_forces_requested_attributes_into_the_return_clause(): void
    {
        $output = $this->format();

        // ws-a-query-1: requested attributes must be projected, not just filtered.
        $this->assertStringContainsString('RETURN', $output);
        $this->assertStringContainsString('REQUESTED ATTRIBUTES block', $output);
        $this->assertStringContainsString('output column', $output);
        $this->assertStringContainsString('projection requirement', $output);
    }

    /** @test */
    public function requested_attributes_take_precedence_over_entity_description_column_lists(): void
    {
        // Lever L2-B(ii): entity descriptions may suggest default columns; the
        // user's requested attributes must always win over those suggestions.
        $output = $this->format();

        $this->assertStringContainsString('REQUESTED ATTRIBUTES OVERRIDE any column list', $output);
        $this->assertStringContainsString('requested attributes always win', $output);
        // Eval p12-1001-A: precedence never licenses borrowing another entity's field.
        $this->assertStringContainsString("never taken from another entity's field", $output);
    }

    /**
     * @test Eval p9 (2026-10-01) roster exports: an OPTIONAL MATCH to a 1:N
     * related entity (User -> Contact -> ContactDirectory) returned one row
     * per related match — 1352 / 530 rows for 523 members, each person
     * duplicated. General rule: rows keep the grain of the entity being
     * listed; a 1:N related entity is aggregated per record.
     */
    public function it_keeps_one_row_per_listed_record_by_aggregating_one_to_many_joins(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('ROW GRAIN', $output);
        $this->assertStringContainsString('ONE row per record of the entity being listed', $output);
        $this->assertStringContainsString('collect(DISTINCT', $output);
        $this->assertStringContainsString('head(collect(', $output);
    }

    /**
     * @test Eval p9-B digest t1 (2026-10-01): "When was our last meeting?"
     * projected only `m.start_date AS date, m.id AS meeting_id` — the answer
     * (and the record chip, "Meeting 2023-10-24 16:00:00") could not name the
     * meeting. An id identifies nothing to the user: a record row carries its
     * human-readable name column even when only a date/amount was asked.
     */
    public function record_rows_carry_a_human_readable_name_not_only_an_id(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('human-readable identifying column', $output);
        $this->assertStringContainsString('an id alone identifies nothing to the user', $output);
        $this->assertStringContainsString('even when the question asks only for its date or amount', $output);
    }

    /**
     * @test Eval p9 cold-roster: "with contact info" projected EVERY scalar
     * property of the joined Contact (company, language, an accessibility
     * flag...). A joined entity contributes the asked-for columns only.
     */
    public function it_forbids_dumping_every_property_of_a_joined_entity(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('never every property of a joined entity', $output);
    }
}
