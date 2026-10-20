<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Services\Response\PiiMasker;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * P-1 (Phase 8-A review): the query generator sees the previous turn MASKED, so a
 * follow-up "only that email" makes it write the token ([email#1]) into the cypher.
 * Before validation the token is rebound as a query PARAMETER carrying the real
 * value — never an inlined literal (it could change the query's structure).
 */
class QueryGeneratorPiiParameterTest extends TestCase
{
    private LlmProviderInterface $llm;
    private QueryGenerator $generator;
    private array $prompts = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.security.enabled' => false,
            'ai.access_control.mask_identifying_fields' => true,
            'ai.access_control.identifying_fields' => ['*' => ['email']],
        ]);

        $this->llm = Mockery::mock(LlmProviderInterface::class);
        $this->generator = new QueryGenerator($this->llm, Mockery::mock(GraphStoreInterface::class), [
            'temperature' => 0.1,
            'max_retries' => 1,
            'allow_write_operations' => false,
            'default_limit' => 100,
            'max_complexity' => 100,
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function context(): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->graphSchema = ['labels' => ['User'], 'relationships' => []];
        $ctx->conversationContext = [
            'last_cypher_query' => 'MATCH (u:User) RETURN u.name AS name, u.email AS email LIMIT 100',
            'last_result_sample' => [['name' => 'Karen', 'email' => "karen.o'neil@x.test"]],
        ];

        return $ctx;
    }

    /** @test */
    public function a_token_in_the_draft_is_executed_as_a_parameter_carrying_the_real_value(): void
    {
        $this->llm->shouldReceive('chatJson')->once()->andReturnUsing(function (array $messages) {
            $this->prompts[] = json_encode($messages);
            return (object) [
                'needs_query' => true,
                'cypher' => "MATCH (u:User) WHERE u.email = '[email#1]' RETURN u.name AS name LIMIT 100",
                'reason' => '',
            ];
        });

        $ctx = $this->context();
        $result = $this->generator->generate('only that email', $ctx, ['explain' => false]);

        // The generation prompt carried the token, never the address.
        $this->assertStringNotContainsString('karen.o', $this->prompts[0]);
        $this->assertStringContainsString('[email#1]', $this->prompts[0]);

        $this->assertStringNotContainsString('[email#1]', (string) $result->cypher);
        $this->assertStringNotContainsString('karen', (string) $result->cypher, 'the literal is never inlined');
        $this->assertMatchesRegularExpression('/u\.email = \$pii_[a-f0-9]{12}/', (string) $result->cypher);
        $this->assertSame(["karen.o'neil@x.test"], array_values($result->parameters));
        $this->assertSame(PiiMasker::for($ctx), $ctx->piiMasker, 'one map per turn');
        // Never persisted in metadata (traces): parameters ride their own field.
        $this->assertStringNotContainsString('karen', json_encode($result->metadata));
    }
}
