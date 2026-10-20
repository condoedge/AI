<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * Eval p12-1001-A team-roster-then-excel: the roster turn's FanOutCollapse
 * rewrite (`WITH u, collect(DISTINCT tr.role)… AS role`, `t`/`tr` out of scope)
 * was recorded as last_cypher_query — and shown to the generator as the
 * "Previous Query" to EXTEND. The generator-facing query is the PRE-collapse
 * generated cypher (last_generated_cypher); the collapsed one stays
 * last_cypher_query, the query that re-executes for an export of what was shown.
 */
class GeneratedCypherSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const GENERATED = 'MATCH (t:Team) WHERE t.id = 3 AND t.id IN $ai_team_ids '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
        . 'OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id '
        . 'RETURN u.name AS member_name, tr.role AS role LIMIT 100';

    private const COLLAPSED = 'MATCH (t:Team) WHERE t.id = 3 AND t.id IN $ai_team_ids '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
        . 'OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id '
        . 'WITH u, collect(DISTINCT tr.role)[0..11] AS role WHERE u IS NOT NULL '
        . 'RETURN u.name AS member_name, role LIMIT 100';

    private ConversationContextManager $manager;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->manager = new ConversationContextManager(new EntityExtractor());
    }

    private function recordCollapsedRoster(AiConversation $conversation): void
    {
        $this->manager->recordResponse($conversation, 'Here are your 523 members.', self::COLLAPSED, [
            'data' => [['member_name' => 'Julie Rousseau', 'role' => 'lead'], ['member_name' => 'Karen Douglas', 'role' => 'contact']],
            'metadata' => [
                'query' => ['fan_out_collapsed' => true, 'pre_collapse_cypher' => self::GENERATED],
                'execution' => ['anchor_label' => 'User', 'anchor_total' => 523],
            ],
        ]);
    }

    /** @test */
    public function a_collapsed_turn_records_the_generated_query_beside_the_executed_one(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $this->recordCollapsedRoster($conversation);

        $snapshot = $conversation->refresh()->context_snapshot;
        $this->assertSame(self::COLLAPSED, $snapshot['last_cypher_query'], 're-execution / export keeps the collapsed query');
        $this->assertSame(self::GENERATED, $snapshot['last_generated_cypher'], 'the generator extends the pre-collapse query');

        $context = $this->manager->buildPromptContext($conversation);
        $this->assertSame(self::GENERATED, $context['last_generated_cypher']);
        $this->assertSame(self::COLLAPSED, $context['last_cypher_query']);
    }

    /** @test */
    public function the_active_filter_comes_from_the_generated_query_and_stops_at_the_next_clause(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $this->recordCollapsedRoster($conversation);

        $filter = $conversation->refresh()->context_snapshot['focused_entity_filter'];
        // Live: "t.id = 3 AND t.id IN $ai_team_ids OPTIONAL MATCH … WITH u, collect(...) … WHERE u IS NOT NULL".
        $this->assertSame('t.id = 3 AND t.id IN $ai_team_ids', $filter);
    }

    /** @test */
    public function a_turn_without_a_collapse_records_its_executed_query_as_the_generated_one(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $this->manager->recordResponse($conversation, 'Two units.', 'MATCH (u:Unit) RETURN u.name AS unit LIMIT 100', [
            'data' => [['unit' => '101'], ['unit' => '102']],
        ]);

        $snapshot = $conversation->refresh()->context_snapshot;
        $this->assertSame('MATCH (u:Unit) RETURN u.name AS unit LIMIT 100', $snapshot['last_generated_cypher']);
    }

    /** @test */
    public function a_rowless_turn_preserves_both_recorded_queries(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $this->recordCollapsedRoster($conversation);

        // The failed Excel extension: no rows.
        $this->manager->recordResponse($conversation, 'I prepared the Excel file.', 'MATCH (t:Team) RETURN t.contact_phone', ['data' => []]);

        $snapshot = $conversation->refresh()->context_snapshot;
        $this->assertSame(self::COLLAPSED, $snapshot['last_cypher_query']);
        $this->assertSame(self::GENERATED, $snapshot['last_generated_cypher']);
    }

    /** @test */
    public function the_recorded_rows_anchor_label_is_kept_with_them(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $this->recordCollapsedRoster($conversation);

        $this->assertSame('User', $this->manager->buildPromptContext($conversation->refresh())['last_anchor_label']);

        // A later row-producing turn without an anchor label clears it (it described the old rows).
        $this->manager->recordResponse($conversation, 'Two units.', 'MATCH (u:Unit) RETURN u.name AS unit', ['data' => [['unit' => '101']]]);
        $this->assertNull($this->manager->buildPromptContext($conversation->refresh())['last_anchor_label']);
    }
}
