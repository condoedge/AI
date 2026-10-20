<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Verifies P0-1 role separation for the QUERY-generation prompt:
 *  - complete() now receives a non-null system arg (was always null)
 *  - identity + static Cypher rules ride in the SYSTEM message
 *  - the model-agnostic persona does NOT leak into the USER message
 *  - the persona no longer hardcodes "Neo4j Cypher query expert" / "data analyst"
 *  - the untrusted question is spotlighted in the USER message
 *
 * Constructs a real PipelineContext (not an array) per the section contract.
 */
class QueryGeneratorRoleSeparationTest extends TestCase
{
    private LlmProviderInterface $llm;
    private QueryGenerator $generator;

    /** @var array{0:string,1:?string,2:array}|null */
    private ?array $captured = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // AiLogger requires a non-null channel.
        $app['config']->set('logging.default', 'null');
        $app['config']->set('ai.log_channel', 'null');

        $app['config']->set('ai.identity.name', 'Acme Assistant');
        $app['config']->set('ai.project.name', 'Acme');
        $app['config']->set('ai.identity.persona', 'You are {name}, the assistant for {project}.');
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->llm = Mockery::mock(LlmProviderInterface::class);
        // The generation call is chatJson (Task 5.6); capture its [user, system, options].
        $this->llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages, array $options = []) {
                $roles = array_column($messages, 'content', 'role');
                $this->captured = [$roles['user'] ?? null, $roles['system'] ?? null, $options];
                return (object) [
                    'needs_query' => true,
                    'cypher' => 'MATCH (n:Invoice) WHERE n.status = "overdue" RETURN n LIMIT 100',
                    'reason' => '',
                ];
            });

        $graph = Mockery::mock(GraphStoreInterface::class);

        $this->generator = new QueryGenerator(
            $this->llm,
            $graph,
            [
                'max_retries' => 1,
            ],
            new SemanticPromptBuilder()
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function runGenerate(): void
    {
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Invoice'], 'relationships' => []];

        $this->generator->generate('Which residents have overdue invoices this month?', $context);
    }

    /** @test */
    public function it_passes_a_non_null_system_prompt_to_complete(): void
    {
        $this->runGenerate();

        $this->assertNotNull($this->captured, 'complete() was not called');
        [$user, $system] = $this->captured;

        $this->assertIsString($system);
        $this->assertNotSame('', $system, 'system prompt must not be empty (was always null before P0-1)');
        $this->assertIsString($user);
    }

    /** @test */
    public function the_system_prompt_carries_identity_and_static_query_rules(): void
    {
        $this->runGenerate();
        [, $system] = $this->captured;

        $this->assertStringContainsString('Acme Assistant', $system);
        // QueryRulesSection is role=system: its static rules belong in the system framing.
        $this->assertStringContainsString('QUERY GENERATION RULES', $system);
    }

    /** @test */
    public function the_persona_is_model_and_stack_agnostic(): void
    {
        $this->runGenerate();
        [, $system] = $this->captured;

        $intro = strtolower(explode('===', $system)[0]); // text before the first section header

        // The old hardcoded "Neo4j Cypher query expert" / "data analyst" persona must be gone
        // from the identity intro (Cypher specifics live in QueryRulesSection, not the persona).
        $this->assertStringNotContainsString('neo4j cypher query expert', $intro);
        $this->assertStringNotContainsString('data analyst', $intro);
    }

    /** @test */
    public function the_user_prompt_spotlights_the_question_and_excludes_the_persona(): void
    {
        $this->runGenerate();
        [$user] = $this->captured;

        $this->assertStringContainsString('<user_question>', $user);
        $this->assertStringContainsString('Which residents have overdue invoices this month?', $user);
        $this->assertStringContainsString('</user_question>', $user);

        $this->assertStringNotContainsString('Acme Assistant', $user);
    }
}
