<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionKind;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportPdfAction;
use Condoedge\Ai\Services\Actions\BuiltIn\RecordedResultExport;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\RecordedQueryRerun;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;
use Condoedge\Ai\Tests\TestCase;

/**
 * export_excel / export_pdf `mode=rerun` (eval p18-A roster: 25 of 523 shown, no export chip).
 *
 * The chip of a CAPPED list cannot write the message's stored rows (the executor's first
 * 100): it re-runs the query recorded with the message. Pinned here:
 *  - the file holds what the re-run returns NOW — never the stored rows, with or without
 *    a recorded query (no stored-row shortcut);
 *  - the re-run goes through the scoped executor as the user who CLICKS: their identity is
 *    what scopes it, read-only, one execution; another user gets their own scope;
 *  - a refusal, a failure or an empty result is a friendly error, never a file;
 *  - coded values and headers are written as the document export writes them.
 */
class RecordedResultExportTest extends TestCase
{
    private const ROSTER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS contact_info, tr.role AS role LIMIT 100';

    /** @var list<array{cypher: ?string, parameters: array, options: array}> */
    private array $executions = [];

    /** @var list<array{rows: array, title: string, headers: array}> */
    private array $written = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.query_execution.default_limit' => 100,
            'ai.export.document_row_limit' => 2000,
            'ai.export.pdf_document_row_limit' => null,
            'ai.export.redact_rows' => true,
            'ai.export.redact_columns' => [],
            'entities' => [
                'App\\Models\\User' => ['graph' => ['label' => 'User'], 'metadata' => ['common_properties' => ['name' => 'Name', 'email' => 'Email']]],
                'App\\Models\\TeamRole' => ['graph' => ['label' => 'TeamRole'], 'metadata' => ['common_properties' => [
                    'role' => 'Role key of the member in the team (lead=Lead union manager, manager=Union manager, contact=Unit Owner or Renter)',
                ]]],
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function members(int $count, string $role = 'contact'): array
    {
        return array_map(
            static fn (int $i): array => ['name' => "Member {$i}", 'contact_info' => "m{$i}@example.com", 'role' => $role, 'user_id' => $i],
            range(1, $count)
        );
    }

    private function user(int $id = 13): object
    {
        return (object) ['id' => $id, 'current_team_id' => 3];
    }

    /**
     * A scoped executor double: answers by the ACTING user (options['user']) and records
     * every execution.
     *
     * @param callable(object): array<int, array<string, mixed>>|\Throwable $rowsFor
     */
    private function executor(callable $rowsFor): QueryExecutorInterface
    {
        $test = $this;

        return new class($rowsFor, $test) implements QueryExecutorInterface {
            public function __construct(private $rowsFor, private RecordedResultExportTest $test)
            {
            }

            public function execute(?string $cypherQuery, array $parameters = [], array $options = []): QueryExecutionResult
            {
                $this->test->recordExecution($cypherQuery, $parameters, $options);
                $rows = ($this->rowsFor)($options['user']);
                if ($rows instanceof \Throwable) {
                    throw $rows;
                }

                return new QueryExecutionResult(success: true, data: $rows, stats: [], metadata: ['security_filter_applied' => true]);
            }

            public function executeCount(?string $cypherQuery, array $parameters = [], array $options = []): int
            {
                throw new \LogicException('not used');
            }
        };
    }

    public function recordExecution(?string $cypher, array $parameters, array $options): void
    {
        $this->executions[] = ['cypher' => $cypher, 'parameters' => $parameters, 'options' => $options];
    }

    /** An exporter that records what it is asked to write instead of needing the xlsx / pdf libraries. */
    private function exporter(): ResultSetExporter
    {
        $written = &$this->written;

        return new class($written) extends ResultSetExporter {
            public function __construct(private array &$written)
            {
            }

            public function documentRowsToExcel(array $rows, string $title = 'document', array $headers = []): AiActionResult
            {
                $this->written[] = ['rows' => $rows, 'title' => $title, 'headers' => $headers];

                return AiActionResult::export('xlsx-bytes', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'file.xlsx', 'ok');
            }

            public function documentRowsToPdf(array $rows, string $title = 'document', array $headers = []): AiActionResult
            {
                $this->written[] = ['rows' => $rows, 'title' => $title, 'headers' => $headers];

                return AiActionResult::export('pdf-bytes', 'application/pdf', 'file.pdf', 'ok');
            }

            public function toExcel(array $rows, string $title = 'export'): AiActionResult
            {
                throw new \LogicException('the stored-row export must not be used for a re-run');
            }
        };
    }

    private function excel(callable $rowsFor): ExportExcelAction
    {
        $exporter = $this->exporter();

        return new ExportExcelAction($exporter, new RecordedResultExport(new RecordedQueryRerun($this->executor($rowsFor)), $exporter));
    }

    /** The click-time context of a capped roster message: 25 stored rows, the recorded query. */
    private function clickContext(?object $user, ?array $recorded = ['cypher' => self::ROSTER, 'parameters' => []]): ActionContext
    {
        return ActionContext::fromArray([
            'user' => $user,
            'result_set' => array_slice($this->members(25, 'stored'), 0, 25),
            'recorded_query' => $recorded,
        ]);
    }

    private const INPUT = ['title' => 'Team members', 'mode' => 'rerun', 'language' => 'en'];

    /** @test */
    public function the_file_holds_every_row_of_the_rerun_not_the_stored_rows(): void
    {
        $user = $this->user();
        $result = $this->excel(fn () => $this->members(523))->handle(self::INPUT, $this->clickContext($user));

        $this->assertFalse($result->isError);
        $this->assertSame(ActionKind::Export, $result->kind);
        $this->assertCount(1, $this->executions, 'one execution: one rate-limit charge');
        $this->assertCount(523, $this->written[0]['rows']);
        $this->assertSame('Team members', $this->written[0]['title']);
        $this->assertNotContains('stored', array_column($this->written[0]['rows'], 'role'), 'no stored row is written');
        $this->assertTrue($result->payload['complete']);
        $this->assertSame(523, $result->payload['rows']);
    }

    /** @test */
    public function the_rerun_is_scoped_to_the_clicking_user_through_the_executor(): void
    {
        $user = $this->user(13);
        $this->excel(fn () => $this->members(3))->handle(self::INPUT, $this->clickContext($user, [
            'cypher' => self::ROSTER,
            'parameters' => ['pii_0123456789ab' => 'ana@example.com', 'ai_team_ids' => [1, 2, 3]],
            'conversation_id' => 77,
        ]));

        $execution = $this->executions[0];
        $this->assertSame($user, $execution['options']['user'], 'the clicking user is the principal the executor scopes for');
        $this->assertTrue($execution['options']['read_only']);
        $this->assertSame(2001, $execution['options']['limit']);
        $this->assertSame(77, $execution['options']['conversation_id']);
        $this->assertArrayNotHasKey('rate_limit_batch', $execution['options']);
        $this->assertStringEndsWith('tr.role AS role LIMIT 2001', $execution['cypher'], 'display cap widened to the document limit (+1 probe row)');
        $this->assertSame(['pii_0123456789ab' => 'ana@example.com'], $execution['parameters'], 'a stored team-id list never rides into the re-run');
    }

    /** @test */
    public function another_user_clicking_the_same_chip_gets_their_own_scope(): void
    {
        // The executor's security layer answers per principal: user 13 sees 523 members,
        // user 40 (another tenant by now) sees 2.
        $action = $this->excel(fn (object $user) => $this->members($user->id === 13 ? 523 : 2));

        $action->handle(self::INPUT, $this->clickContext($this->user(13)));
        $action->handle(self::INPUT, $this->clickContext($this->user(40)));

        $this->assertSame(13, $this->executions[0]['options']['user']->id);
        $this->assertSame(40, $this->executions[1]['options']['user']->id);
        $this->assertCount(523, $this->written[0]['rows']);
        $this->assertCount(2, $this->written[1]['rows']);
    }

    /** @test */
    public function the_dispatcher_threads_the_clicking_user_and_reauthorizes(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric($this->excel(fn (object $user) => $this->members(4)));
        $dispatcher = new ActionDispatcher($registry);
        $clicker = $this->user(40);

        $result = $dispatcher->dispatch($registry->getGeneric('export_excel'), self::INPUT + ['limit' => 999999, 'user' => 13], [
            'user' => $clicker,
            'recorded_query' => ['cypher' => self::ROSTER, 'parameters' => []],
        ]);

        $this->assertFalse($result->isError);
        $this->assertSame($clicker, $this->executions[0]['options']['user']);
        $this->assertSame(2001, $this->executions[0]['options']['limit'], 'a link parameter never reaches the execution options');
    }

    /** @test */
    public function without_a_recorded_query_there_is_no_file_and_no_stored_row_shortcut(): void
    {
        $action = $this->excel(fn () => $this->members(523));

        foreach ([null, [], ['cypher' => '   '], ['cypher' => null], 'MATCH (n) RETURN n'] as $recorded) {
            $context = ActionContext::fromArray([
                'user' => $this->user(),
                'result_set' => $this->members(25, 'stored'),
                'recorded_query' => $recorded,
            ]);
            $result = $action->handle(self::INPUT, $context);

            $this->assertTrue($result->isError);
            $this->assertSame('No data available to export to Excel.', $result->summaryForLlm);
        }
        $this->assertSame([], $this->executions);
        $this->assertSame([], $this->written);
    }

    /** @test */
    public function without_a_clicking_user_nothing_runs(): void
    {
        $result = $this->excel(fn () => $this->members(523))->handle(self::INPUT, $this->clickContext(null));

        $this->assertTrue($result->isError);
        $this->assertSame([], $this->executions);
        $this->assertSame([], $this->written);
    }

    /** @test */
    public function a_scope_that_now_returns_nothing_is_a_friendly_error_not_an_empty_file(): void
    {
        // The security layer safe-stopped, or the user's tenants no longer hold these records.
        $result = $this->excel(fn () => [])->handle(self::INPUT, $this->clickContext($this->user()));

        $this->assertTrue($result->isError);
        $this->assertSame('No data available to export to Excel.', $result->summaryForLlm);
        $this->assertSame([], $this->written);
    }

    /** @test */
    public function a_rejected_or_failed_execution_is_a_friendly_error_that_leaks_no_reason(): void
    {
        $failures = [
            new QueryExecutionException('Rate limit exceeded for query execution. Please wait before trying again.'),
            new \RuntimeException('SecurityException: tenant scope could not be proven for (u:User)'),
        ];

        foreach ($failures as $failure) {
            $result = $this->excel(fn () => $failure)->handle(self::INPUT, $this->clickContext($this->user()));

            $this->assertTrue($result->isError);
            $this->assertNotSame('', $result->summaryForLlm);
            $this->assertStringNotContainsString('tenant scope', $result->summaryForLlm);
            $this->assertStringNotContainsString('Rate limit', $result->summaryForLlm);
            $this->assertStringNotContainsString('ai.chat.', $result->summaryForLlm, 'never a raw translation key');
        }
        $this->assertSame([], $this->written);
    }

    /** @test */
    public function coded_values_and_headers_are_written_as_the_document_export_writes_them(): void
    {
        $rows = [
            ['name' => 'Julie Rousseau', 'contact_info' => 'julie@example.com', 'role' => 'lead', 'user_id' => 13, 'note' => null],
            ['name' => 'Antoine Paquin', 'contact_info' => 'antoine@example.com', 'role' => 'contact', 'user_id' => 14, 'note' => ''],
        ];

        $this->excel(fn () => $rows)->handle(self::INPUT, $this->clickContext($this->user()));

        $this->assertSame([
            ['name' => 'Julie Rousseau', 'contact_info' => 'julie@example.com', 'role' => 'Lead union manager'],
            ['name' => 'Antoine Paquin', 'contact_info' => 'antoine@example.com', 'role' => 'Unit Owner or Renter'],
        ], $this->written[0]['rows'], 'legend labels; the internal id and the all-empty column are not written');
        $this->assertSame(
            ['name' => 'Name', 'contact_info' => 'Email', 'role' => 'Role'],
            $this->written[0]['headers'],
            'a column is headed by the property it holds when its alias says something else'
        );
    }

    // ------------------------------------------------- a split name is ONE column

    private const BOARD = 'MATCH (b:BoardMember)-[:BELONGS_TO_CONTACT]->(c:Contact) WHERE b.team_id IN $ai_team_ids '
        . 'RETURN c.first_name AS first_name, c.last_name AS last_name, b.role AS role LIMIT 100';

    /** The host's Contact: a name split over two properties (metadata.name_properties). */
    private function contactsWithSplitNames(): void
    {
        config([
            'entities.App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => ['name_properties' => ['first_name', 'last_name']]],
            'entities.App\\Models\\BoardMember' => ['graph' => ['label' => 'BoardMember']],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function board(): array
    {
        return [
            ['first_name' => 'Julie', 'last_name' => 'Rousseau', 'role' => 'president', 'contact_id' => 7],
            ['first_name' => 'Antoine', 'last_name' => null, 'role' => 'treasurer', 'contact_id' => 8],
        ];
    }

    /**
     * @test The file of the chip shows the columns the SCREEN showed (eval p19-A board): the
     * chat table merges an entity's declared name parts into one "Name" column
     * (TableRenderer::nameMerges) — the re-run file follows the same merge, header included.
     */
    public function a_split_name_is_one_column_in_the_rerun_file_as_on_screen(): void
    {
        $this->contactsWithSplitNames();
        $rows = $this->board();

        $this->excel(fn () => $rows)->handle(self::INPUT, $this->clickContext($this->user(), ['cypher' => self::BOARD, 'parameters' => []]));

        // The on-screen table of the same rows: its columns are the file's columns.
        $renderer = new \Condoedge\Ai\Services\Response\TableRenderer();
        $screen = $renderer->mergeColumns(
            (new ResultSetExporter())->redact($rows),
            \Condoedge\Ai\Services\Response\TableRenderer::nameMerges(self::BOARD, $rows)
        );
        $this->assertSame(array_keys($screen[0]), array_keys($this->written[0]['rows'][0]), 'file columns == on-screen columns');
        $this->assertSame(['Julie Rousseau', 'president'], array_values($this->written[0]['rows'][0]));
        $this->assertSame(['Antoine', 'treasurer'], array_values($this->written[0]['rows'][1]), 'a missing part leaves the other');
        $this->assertSame(['Name', 'Role'], array_values($this->written[0]['headers']));

        // A French answer's chip: the headers are said in French where the host ships the line.
        app('translator')->addLines(['*.Name' => 'Nom', '*.Role' => 'Rôle'], 'fr');
        $this->excel(fn () => $rows)->handle(['language' => 'fr'] + self::INPUT, $this->clickContext($this->user(), ['cypher' => self::BOARD, 'parameters' => []]));
        $this->assertSame(['Nom', 'Rôle'], array_values($this->written[1]['headers']));
    }

    /** @test Only one part of the name is projected: nothing to merge, the file is as before. */
    public function a_single_projected_name_part_is_left_as_it_is(): void
    {
        $this->contactsWithSplitNames();
        $cypher = 'MATCH (b:BoardMember)-[:BELONGS_TO_CONTACT]->(c:Contact) RETURN c.last_name AS last_name, b.role AS role LIMIT 100';

        $this->excel(fn () => [['last_name' => 'Rousseau', 'role' => 'president']])
            ->handle(self::INPUT, $this->clickContext($this->user(), ['cypher' => $cypher, 'parameters' => []]));

        $this->assertSame([['last_name' => 'Rousseau', 'role' => 'president']], $this->written[0]['rows']);
        $this->assertSame(['last_name' => 'Last Name', 'role' => 'Role'], $this->written[0]['headers']);
    }

    /**
     * @test The chip of a COMPLETE list (mode=table) writes the message's stored rows: with
     * the query those rows came from in the render context (`result_cypher` — its text only,
     * for display; nothing is executed), a split name is one column there too. Excel and PDF.
     */
    public function a_split_name_is_one_column_in_the_stored_row_file_too(): void
    {
        $this->contactsWithSplitNames();
        $stored = [];
        $exporter = new class($stored) extends ResultSetExporter {
            public function __construct(public array &$stored)
            {
            }

            public function toExcel(array $rows, string $title = 'export'): AiActionResult
            {
                $this->stored[] = $this->redact($rows);

                return AiActionResult::export('xlsx-bytes', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'file.xlsx', 'ok');
            }

            public function toPdf(array $rows, string $title = 'export'): AiActionResult
            {
                $this->stored[] = $this->redact($rows);

                return AiActionResult::export('pdf-bytes', 'application/pdf', 'file.pdf', 'ok');
            }
        };
        $context = ['user' => $this->user(), 'result_set' => $this->board(), 'result_cypher' => self::BOARD];

        (new ExportExcelAction($exporter))->handle(['mode' => 'table'], ActionContext::fromArray($context));
        (new ExportPdfAction($exporter))->handle(['mode' => 'table'], ActionContext::fromArray($context));
        // The query is not known (a message stored without one): the rows as they are.
        (new ExportExcelAction($exporter))->handle(['mode' => 'table'], ActionContext::fromArray(['result_cypher' => null] + $context));

        foreach ([0, 1] as $file) {
            $this->assertSame(['Julie Rousseau', 'president'], array_values($stored[$file][0]));
            $this->assertCount(2, $stored[$file][0], 'ONE name column');
            $this->assertSame(
                ['Name', 'Role'],
                (new \Condoedge\Ai\Services\Response\TableRenderer())->matrix($stored[$file], ['redact' => false])[0],
                'headed as on screen'
            );
        }
        $this->assertSame(['first_name', 'last_name', 'role'], array_keys($stored[2][0]));
        $this->assertSame([], $this->executions, 'display only: nothing is executed for a stored-row file');
    }

    /** @test */
    public function a_rerun_cut_at_the_document_limit_says_so_on_the_result(): void
    {
        config(['ai.export.document_row_limit' => 250]);

        $result = $this->excel(fn () => $this->members(251))->handle(self::INPUT, $this->clickContext($this->user()));

        $this->assertFalse($result->isError);
        $this->assertCount(250, $this->written[0]['rows']);
        $this->assertFalse($result->payload['complete']);
        $this->assertSame(250, $result->payload['rows']);
        $this->assertSame('xlsx-bytes', $result->payload['content']);
    }

    /** @test */
    public function the_pdf_action_reruns_the_same_way_under_its_own_row_limit(): void
    {
        config(['ai.export.pdf_document_row_limit' => 600]);
        $exporter = $this->exporter();
        $action = new ExportPdfAction($exporter, new RecordedResultExport(new RecordedQueryRerun($this->executor(fn () => $this->members(523))), $exporter));

        $result = $action->handle(self::INPUT, $this->clickContext($this->user()));

        $this->assertFalse($result->isError);
        $this->assertStringEndsWith('LIMIT 601', $this->executions[0]['cypher']);
        $this->assertCount(523, $this->written[0]['rows']);
        $this->assertSame(['name' => 'Name', 'contact_info' => 'Email', 'role' => 'Role'], $this->written[0]['headers']);
    }

    /** @test */
    public function the_stored_row_modes_are_unchanged(): void
    {
        $action = new ExportExcelAction();

        $this->assertTrue($action->handle(['mode' => 'table'], ActionContext::fromArray(['result_set' => []]))->isError);
        // The re-run is never advertised to the model: the user-facing params are title + mode.
        $this->assertSame(['title', 'mode'], array_keys($action->declaredParams()));
        $this->assertArrayHasKey('language', $action->inputSchema());
        $this->assertSame(['title', 'mode'], array_keys((new ExportPdfAction())->declaredParams()));
    }
}
