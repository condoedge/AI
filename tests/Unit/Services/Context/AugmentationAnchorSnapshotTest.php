<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * G2/L5 item B — the augmentation-anchor SNAPSHOT contract.
 *
 * A column-add follow-up ("could you add their last login to it?") must be able
 * to deterministically carry the PREVIOUS turn's entity types and recorded
 * cypher into query generation. The snapshot side of that mechanism:
 * recordResponse persists last_entity_types (EVERY entity of the executed
 * cypher, not just the first) alongside last_cypher_query, only on turns that
 * produced rows (preserve-on-empty), and buildPromptContext exposes it.
 */
class AugmentationAnchorSnapshotTest extends TestCase
{
    use RefreshDatabase;

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

    /** @test */
    public function record_response_persists_every_entity_type_of_the_executed_cypher(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse(
            $conversation,
            'Here are the team members.',
            'MATCH (t:Team)-[:HAS_MEMBER]->(u:User) RETURN u.name, u.email',
            ['data' => [['name' => 'Ana', 'email' => 'a@x.io'], ['name' => 'Bo', 'email' => 'b@x.io']]]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        $this->assertEqualsCanonicalizing(
            ['Team', 'User'],
            $snapshot['last_entity_types'] ?? [],
            'last_entity_types must carry EVERY entity of the executed cypher'
        );
    }

    /** @test */
    public function a_rowless_turn_preserves_the_previous_anchor_entity_types(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        // Turn 1: a data turn establishes the anchor.
        $this->manager->recordResponse(
            $conversation,
            'Here are the team members.',
            'MATCH (t:Team)-[:HAS_MEMBER]->(u:User) RETURN u.name',
            ['data' => [['name' => 'Ana']]]
        );

        // Turn 2: a docs-only turn (no cypher, no rows) must NOT erase it —
        // same preserve-on-empty rule as last_cypher_query.
        $conversation->refresh();
        $this->manager->recordResponse($conversation, 'Per the bylaws...', '', ['data' => []]);

        $conversation->refresh();
        $this->assertEqualsCanonicalizing(
            ['Team', 'User'],
            $conversation->context_snapshot['last_entity_types'] ?? [],
            'a rowless turn must preserve the previous data anchor'
        );
    }

    /** @test */
    public function build_prompt_context_exposes_the_anchor_entity_types(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse(
            $conversation,
            'Here are the team members.',
            'MATCH (t:Team)-[:HAS_MEMBER]->(u:User) RETURN u.name',
            ['data' => [['name' => 'Ana']]]
        );

        $conversation->refresh();
        $context = $this->manager->buildPromptContext($conversation);

        $this->assertEqualsCanonicalizing(['Team', 'User'], $context['last_entity_types'] ?? []);
        $this->assertNotEmpty($context['last_cypher_query'] ?? null);
    }
}
