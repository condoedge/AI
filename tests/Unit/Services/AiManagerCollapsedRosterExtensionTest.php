<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Eval p12-1001-A team-roster-then-excel #1 ("…as an Excel with their contact
 * info" after a 523-member roster).
 *
 * The roster turn was collapsed per member (525 rows → 523 members). The
 * snapshot now keeps BOTH queries: last_cypher_query = the collapsed rewrite
 * (re-executed for an export of what was shown) and last_generated_cypher = the
 * generator's own query (the one a follow-up extends).
 *
 *  1. The extension of the generator's query fans out again (one row per
 *     member × role), is collapsed again at execution, and is exported as the
 *     CURRENT result — with the new column.
 *  2. When the extension fails (p12: `t.contact_phone` after the WITH →
 *     "Variable `t` not defined", twice), the previous result is exported and
 *     the fields the failed extension asked for are disclosed as not included —
 *     p12 said only "Fields included: Member Name, Role".
 */
class AiManagerCollapsedRosterExtensionTest extends TestCase
{
    private const HEAD = 'MATCH (t:Team) WHERE t.id = 3 AND t.id IN $ai_team_ids '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
        . 'OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id ';

    private const GENERATED = self::HEAD . 'RETURN u.name AS member_name, tr.role AS role LIMIT 100';

    private const COLLAPSED = self::HEAD
        . 'WITH u, collect(DISTINCT tr.role)[0..11] AS role WHERE u IS NOT NULL RETURN u.name AS member_name, role LIMIT 100';

    private const EXTENSION = self::HEAD . 'RETURN u.name AS member_name, tr.role AS role, u.email AS email LIMIT 100';

    /** The live p12 draft (both attempts): `t` used after a WITH that drops it. */
    private const BROKEN = self::HEAD
        . 'WITH u, collect(DISTINCT tr.role)[0..11] AS role WHERE u IS NOT NULL '
        . 'RETURN u.name AS member_name, role, u.email AS email, t.contact_phone AS phone LIMIT 100';

    private AiManager $manager;
    private ContextRetrieverInterface $context;
    private QueryGeneratorInterface $generator;
    private QueryExecutorInterface $executor;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.query_execution.default_limit', 100);
        config()->set('ai.export.document_row_limit', 250);
        config()->set('ai.attached_actions.project_record_ids', false);

        $this->context = Mockery::mock(ContextRetrieverInterface::class);
        $this->generator = Mockery::mock(QueryGeneratorInterface::class);
        $this->executor = Mockery::mock(QueryExecutorInterface::class);
        $responder = Mockery::mock(ResponseGeneratorInterface::class);
        $responder->shouldReceive('generate')->never();
        $responder->shouldReceive('generateEmptyResponse')->never();

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->context,
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            $this->generator,
            $this->executor,
            $responder,
            Mockery::mock(VectorStoreInterface::class)
        );

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(
            entityTypes: ['Team', 'TeamRole', 'User', 'ContactDirectory'],
            propertyConcepts: ['contact', 'email', 'phone'],
            searchQuery: 'team members contact information',
            intent: 'file_listing',
            questionType: 'data_query',
            exportFormat: 'excel',
            language: 'en',
            followUpReference: true,
        );
        $this->context->shouldReceive('retrieveContext')->once()->andReturn($ctx);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function conversation(): array
    {
        return [
            'user' => (object) ['id' => 13],
            'store_examples' => false,
            'conversation_context' => [
                'last_cypher_query' => self::COLLAPSED,
                'last_generated_cypher' => self::GENERATED,
                'last_result_count' => 100,
                'last_result_sample' => [['member_name' => 'Member 1', 'role' => 'owner; manager']],
            ],
        ];
    }

    /** Role values of member $i: the first two members hold two roles (525 rows for 523). */
    private static function roles(int $i): array
    {
        return $i <= 2 ? ['owner', 'manager'] : ['owner'];
    }

    /**
     * The live graph: 523 members, 525 member×role rows. A per-member (collapsed)
     * query returns list cells; the joined query one row per member × role.
     */
    private function liveGraph(array &$seen): void
    {
        $this->executor->shouldReceive('execute')->andReturnUsing(function ($query, $params, $options) use (&$seen) {
            $query = (string) $query;
            if ($query === '') {
                return QueryExecutionResult::empty();
            }
            $seen[] = ['query' => $query, 'limit' => $options['limit'] ?? null];
            if (str_contains($query, 't.contact_phone')) {
                throw new QueryExecutionException(
                    'Query execution failed: Failed to execute Neo4j query: Neo4j errors detected. First one with code '
                    . '"Neo.ClientError.Statement.SyntaxError" and message "Variable `t` not defined (line 1, column 418 (offset: 417))"'
                );
            }
            if (preg_match('/count\(DISTINCT\s+u\)\s+AS\s+total$/', $query) === 1) {
                return new QueryExecutionResult(success: true, data: [['total' => 523]]);
            }
            $limit = preg_match('/LIMIT\s+(\d+)\s*$/', $query, $m) === 1 ? (int) $m[1] : (int) ($options['limit'] ?? 100);
            $withEmail = str_contains($query, 'u.email');
            $rows = [];
            if (str_contains($query, 'WITH u, collect(DISTINCT')) {
                for ($i = 1; $i <= min($limit, 523); $i++) {
                    $rows[] = ['member_name' => "Member {$i}", 'role' => self::roles($i)] + ($withEmail ? ['email' => "m{$i}@x.test"] : []);
                }
            } else {
                for ($i = 1; count($rows) < min($limit, 525); $i++) {
                    foreach (self::roles($i) as $role) {
                        $rows[] = ['member_name' => "Member {$i}", 'role' => $role] + ($withEmail ? ['email' => "m{$i}@x.test"] : []);
                    }
                }
                $rows = array_slice($rows, 0, min($limit, 525));
            }

            return new QueryExecutionResult(success: true, data: $rows);
        });
        $this->executor->shouldReceive('executeCount')->andReturn(525);
    }

    /** @test */
    public function the_extension_of_the_generated_query_is_collapsed_again_and_exported_with_the_new_column(): void
    {
        $this->generator->shouldReceive('generate')->once()
            ->andReturn(new QueryGenerationResult(cypher: self::EXTENSION, explanation: '', confidence: 0.9));
        $seen = [];
        $this->liveGraph($seen);

        $result = $this->manager->answerQuestion('Perfect — give me that as an Excel with their contact info.', $this->conversation());

        // Collapsed per member at execution: the turn's cypher is the rewrite,
        // the generator's own query rides metadata for the next snapshot.
        $this->assertStringContainsString('WITH u, collect(DISTINCT tr.role)', (string) $result['cypher']);
        $this->assertStringContainsString('u.email AS email', (string) $result['cypher']);
        $this->assertSame(self::EXTENSION, $result['metadata']['query']['pre_collapse_cypher']);

        $response = $result['metadata']['response'];
        $this->assertSame('current_result', $response['export_source']);
        $rows = $response['export_document_rows'];
        $this->assertSame(['member_name' => 'Member 1', 'role' => 'owner; manager', 'email' => 'm1@x.test'], $rows[0]);
        $this->assertCount(250, $rows);
        $this->assertSame(523, $response['result_total']);
        $this->assertStringContainsString('Fields included: Member Name, Role, Email.', $result['answer']);
        $this->assertStringNotContainsString('not included', $result['answer']);
        $this->assertStringContainsString('250 of 523', $result['answer']);
    }

    /** @test */
    public function a_failed_extension_discloses_the_requested_fields_the_previous_result_lacks(): void
    {
        $this->generator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: self::BROKEN, explanation: '', confidence: 0.9));
        $seen = [];
        $this->liveGraph($seen);

        $result = $this->manager->answerQuestion('Perfect — give me that as an Excel with their contact info.', $this->conversation());

        $response = $result['metadata']['response'];
        $this->assertSame('previous_result', $response['export_source']);
        // The recorded (collapsed) roster re-executes: one row per member.
        $this->assertSame(['member_name' => 'Member 1', 'role' => 'owner; manager'], $response['export_document_rows'][0]);
        $this->assertStringContainsString('Fields included: Member Name, Role.', $result['answer']);
        $this->assertStringContainsString('Requested fields not included: Email, Phone.', $result['answer']);
    }

    /** @test */
    public function a_failed_query_that_is_not_an_extension_of_the_previous_result_discloses_nothing(): void
    {
        // A re-anchored failed query (it does not keep the previous columns) says
        // nothing about what the user asked to ADD to the previous result.
        $this->generator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: 'MATCH (t:Team) WITH t.name AS team RETURN team, t.contact_phone AS phone', explanation: '', confidence: 0.9));
        $seen = [];
        $this->liveGraph($seen);

        $result = $this->manager->answerQuestion('Perfect — give me that as an Excel with their contact info.', $this->conversation());

        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
        $this->assertStringNotContainsString('not included', $result['answer']);
    }
}
