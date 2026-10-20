<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Services\Files\RecordAncestry;
use Condoedge\Ai\Services\Retrieval\DocumentScope;
use Condoedge\Ai\Services\Retrieval\DocumentScopeResolver;
use Condoedge\Ai\Tests\TestCase;

/**
 * Which record a question is about — from STRUCTURAL signals only, in this
 * order: the record of the page the chat was opened on; the record the
 * conversation is about (the previous answer was one record); the records the
 * files cited by the previous answer are filed on; the user's current scope as
 * the host declares it (a default, weaker than the three before). A record
 * merely NAMED in the question text is not resolved here.
 */
class DocumentScopeResolverTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['ai.response_generation.identity_labels' => ['user' => 'User', 'team' => 'Team']]);
        config(['ai.file_context.scope.current' => []]);
        config(['entities' => [
            'Tests\\Fixtures\\ScopedUnit' => ['graph' => [
                'label' => 'Unit',
                'properties' => ['id', 'name', 'union_id', 'team_id'],
                'relationships' => [
                    ['type' => 'BELONGS_TO_UNION', 'target_label' => 'Union', 'foreign_key' => 'union_id', 'direction' => 'outgoing'],
                    ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id', 'direction' => 'outgoing'],
                ],
            ]],
            'Tests\\Fixtures\\ScopedUnion' => ['graph' => ['label' => 'Union', 'properties' => ['id', 'name', 'team_id'], 'relationships' => []]],
            'Tests\\Fixtures\\ScopedTeam' => ['graph' => ['label' => 'Team', 'properties' => ['id', 'name'], 'relationships' => []]],
        ]]);
    }

    private function resolver(): DocumentScopeResolver
    {
        $rows = [
            'Unit' => [309 => ['id' => 309, 'union_id' => 14], 310 => ['id' => 310, 'union_id' => 14]],
            'Union' => [14 => ['id' => 14], 66 => ['id' => 66]],
        ];

        return new DocumentScopeResolver(new RecordAncestry(fn (string $label, int|string $id): ?array => $rows[$label][$id] ?? null));
    }

    private function user(?int $currentUnion = null): object
    {
        return (object) ['id' => 1, 'current_union_id' => $currentUnion];
    }

    /** @test */
    public function the_page_record_is_the_focus_and_its_owners_are_its_ancestors(): void
    {
        $scope = $this->resolver()->resolve(['entity_type' => 'Unit', 'entity_id' => 310, 'label' => '310'], [], $this->user());

        $this->assertTrue($scope->isKnown());
        $this->assertSame('unit:310', $scope->focus);
        $this->assertSame(['union:14'], $scope->ancestors);
        $this->assertSame(DocumentScope::PAGE, $scope->source);
        $this->assertSame(['focus' => 'unit:310', 'ancestors' => ['union:14']], $scope->filter());
    }

    /** @test */
    public function a_page_that_is_not_a_record_of_a_configured_entity_gives_no_scope(): void
    {
        $resolver = $this->resolver();

        $this->assertFalse($resolver->resolve(['route' => 'dashboard'], [], $this->user())->isKnown());
        $this->assertFalse($resolver->resolve(['entity_type' => 'Budget', 'entity_id' => 3], [], $this->user())->isKnown());
        $this->assertFalse($resolver->resolve(['entity_type' => 'Unit'], [], $this->user())->isKnown());
        $this->assertNull($resolver->resolve([], [], null)->filter());
    }

    /** @test */
    public function the_record_the_conversation_is_about_is_the_focus_when_no_page_record_is(): void
    {
        $conversation = [
            'last_result_count' => 1,
            'last_result_sample' => [['unit_id' => 309, 'unit_name' => 'PH']],
            'last_cypher_query' => 'MATCH (u:Unit) WHERE u.name = "PH" RETURN u.id AS unit_id, u.name AS unit_name',
        ];

        $scope = $this->resolver()->resolve([], $conversation, $this->user());

        $this->assertSame(['unit:309', ['union:14'], DocumentScope::CONVERSATION], [$scope->focus, $scope->ancestors, $scope->source]);
    }

    /** @test */
    public function a_list_of_records_is_not_a_record_the_conversation_is_about(): void
    {
        $conversation = [
            'last_result_count' => 2,
            'last_result_sample' => [['unit_id' => 309], ['unit_id' => 310]],
            'last_cypher_query' => 'MATCH (u:Unit) RETURN u.id AS unit_id',
        ];

        $this->assertFalse($this->resolver()->resolve([], $conversation, $this->user())->isKnown());
    }

    /** @test */
    public function the_files_the_previous_answer_cited_give_the_scope(): void
    {
        $conversation = ['last_referenced_files' => [
            ['id' => 'physical:laws/code.md', 'source' => 'physical'],
            ['id' => 20, 'owner' => 'unit:309', 'ancestors' => ['union:14']],
            ['id' => 21, 'owner' => 'unit:309', 'ancestors' => ['union:14']],
        ]];

        $scope = $this->resolver()->resolve([], $conversation, $this->user());

        $this->assertSame(['unit:309', ['union:14'], DocumentScope::CITED_FILES], [$scope->focus, $scope->ancestors, $scope->source]);
    }

    /** @test */
    public function cited_files_of_several_records_of_one_building_give_the_building(): void
    {
        $conversation = ['last_referenced_files' => [
            ['id' => 20, 'owner' => 'unit:309', 'ancestors' => ['union:14']],
            ['id' => 30, 'owner' => 'unit:310', 'ancestors' => ['union:14']],
        ]];

        $scope = $this->resolver()->resolve([], $conversation, $this->user());

        $this->assertSame(['union:14', []], [$scope->focus, $scope->ancestors]);
    }

    /** @test */
    public function cited_files_of_two_buildings_give_no_scope(): void
    {
        $conversation = ['last_referenced_files' => [
            ['id' => 20, 'owner' => 'unit:309', 'ancestors' => ['union:14']],
            ['id' => 40, 'owner' => 'union:66', 'ancestors' => []],
        ]];

        $this->assertFalse($this->resolver()->resolve([], $conversation, $this->user())->isKnown());
    }

    /** @test */
    public function the_hosts_current_scope_is_the_default_when_nothing_else_tells(): void
    {
        config(['ai.file_context.scope.current' => ['union' => 'current_union_id']]);

        $scope = $this->resolver()->resolve([], [], $this->user(66));

        $this->assertSame(['union:66', [], DocumentScope::CURRENT], [$scope->focus, $scope->ancestors, $scope->source]);
        $this->assertFalse($this->resolver()->resolve([], [], $this->user(null))->isKnown(), 'no current record: unknown');
        $this->assertFalse($this->resolver()->resolve([], [], null)->isKnown(), 'no user: unknown');
    }

    /** @test */
    public function the_precedence_is_page_then_conversation_then_cited_files_then_current(): void
    {
        config(['ai.file_context.scope.current' => ['union' => 'current_union_id']]);
        $record = [
            'last_result_count' => 1,
            'last_result_sample' => [['unit_id' => 309]],
            'last_cypher_query' => 'MATCH (u:Unit) RETURN u.id AS unit_id',
        ];
        $cited = ['last_referenced_files' => [['id' => 40, 'owner' => 'union:66', 'ancestors' => []]]];
        $resolver = $this->resolver();

        $this->assertSame('unit:310', $resolver->resolve(['entity_type' => 'Unit', 'entity_id' => 310], $record + $cited, $this->user(66))->focus);
        $this->assertSame('unit:309', $resolver->resolve([], $record + $cited, $this->user(66))->focus);
        $this->assertSame(DocumentScope::CITED_FILES, $resolver->resolve([], $cited, $this->user(14))->source);
        $this->assertSame('union:14', $resolver->resolve([], [], $this->user(14))->focus);
    }

    /** @test */
    public function a_focus_outside_every_current_kind_record_sits_inside_the_users_current_one(): void
    {
        // A supplier belongs to no building: on its page the documents of the
        // user's CURRENT building still apply (as its owner), never another one's.
        $entities = config('entities');
        $entities['Tests\\Fixtures\\ScopedSupplier'] = ['graph' => ['label' => 'Supplier', 'properties' => ['id', 'name', 'team_id'], 'relationships' => []]];
        config(['entities' => $entities, 'ai.file_context.scope.current' => ['union' => 'current_union_id']]);

        $supplier = $this->resolver()->resolve(['entity_type' => 'Supplier', 'entity_id' => 3], [], $this->user(66));
        $this->assertSame(['supplier:3', ['union:66'], DocumentScope::PAGE], [$supplier->focus, $supplier->ancestors, $supplier->source]);

        // A unit already sits in ITS building: the current one is not added.
        $unit = $this->resolver()->resolve(['entity_type' => 'Unit', 'entity_id' => 310], [], $this->user(66));
        $this->assertSame(['union:14'], $unit->ancestors);

        // … and a page that IS a building is not put inside another.
        $union = $this->resolver()->resolve(['entity_type' => 'Union', 'entity_id' => 14], [], $this->user(66));
        $this->assertSame([], $union->ancestors);
    }

    /** @test */
    public function the_trace_form_says_where_the_scope_came_from(): void
    {
        $this->assertSame(['state' => 'unknown'], DocumentScope::unknown()->toArray());
        $this->assertSame(
            ['state' => 'known', 'focus' => 'unit:310', 'ancestors' => ['union:14'], 'source' => 'page'],
            $this->resolver()->resolve(['entity_type' => 'Unit', 'entity_id' => 310], [], null)->toArray()
        );
    }
}
