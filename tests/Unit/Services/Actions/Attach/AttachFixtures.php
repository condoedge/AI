<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\MutatingSpyAction;
use Illuminate\Support\Facades\Route;

/**
 * Shared fixtures for the code-attached actions (actions plan B1–B5): a small host
 * catalog (Unit / Contact / Reservation / Meeting / Bill entity actions, list / create /
 * report generic actions with `entity` + `kind`), a manager and a resident principal,
 * and context builders. Gates read `$user->role` only.
 */
trait AttachFixtures
{
    protected function configureCatalog(): void
    {
        $manager = fn ($user, array $input = []): bool => is_object($user) && ($user->role ?? null) === 'manager';

        config([
            'ai.attached_actions' => [
                'enabled' => true,
                'max_record_chips' => 5,
                'max_next_steps' => 3,
                'identity' => [
                    'profile' => ['route' => 'profile.show'],
                    'team' => ['action' => 'team_settings'],
                ],
            ],
            'ai.response_generation.identity_labels' => ['user' => 'User', 'team' => 'Team'],
            'ai.response_generation.table_max_rows' => 25,
            'ai.query_execution.default_limit' => 100,
            'entities' => [
                'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['display_name' => 'unit']],
                'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['money_properties' => ['due_amount']]],
                'App\\Models\\Bill' => ['graph' => ['label' => 'Bill'], 'metadata' => ['money_properties' => ['due_amount']]],
            ],
            'ai.entity_actions' => [
                'Unit' => [
                    'view' => ['action' => fn ($id, $text = 'Open unit') => "open-unit:{$id}:{$text}", 'label' => 'Open unit'],
                    'statement' => ['action' => fn ($id, $text = 'Account statement') => "statement:{$id}", 'label' => 'Account statement'],
                    'add_access' => ['action' => fn ($id, $text = 'Add access') => "access:{$id}", 'label' => 'Add access', 'authorize' => $manager],
                ],
                'Contact' => [
                    'view' => ['action' => fn ($id, $text = 'Open contact') => "contact:{$id}", 'label' => 'Open contact', 'authorize' => $manager],
                ],
                'Meeting' => [
                    'view' => ['action' => fn ($id, $text = 'Open meeting') => "meeting:{$id}", 'label' => 'Open meeting'],
                ],
                'Reservation' => [
                    'approve_reservation' => new MutatingSpyAction('approve_reservation', true, fn () => 'approved'),
                ],
                'Invoice' => [
                    'edit' => ['action' => fn ($id, $text = 'Open invoice') => "invoice:{$id}", 'label' => 'Open invoice', 'authorize' => $manager],
                    'pdf' => ['action' => fn ($id, $text = 'Invoice PDF') => "invoice-pdf:{$id}", 'label' => 'Invoice PDF', 'primary' => true, 'authorize' => $manager],
                ],
            ],
            'ai.generic_actions' => [
                'units' => ['action' => fn ($text = 'Units') => 'units', 'label' => 'Units', 'entity' => 'Unit', 'kind' => 'list'],
                'meetings' => ['action' => fn ($text = 'Meetings') => 'meetings', 'label' => 'Meetings', 'entity' => 'Meeting', 'kind' => 'list'],
                'new_meeting' => ['action' => fn ($text = 'New meeting') => 'new-meeting', 'label' => 'New meeting', 'entity' => 'Meeting', 'kind' => 'create', 'authorize' => $manager],
                'report_receivables' => ['action' => fn ($text = 'Receivables report') => 'receivables', 'label' => 'Receivables report', 'entity' => ['Invoice', 'Unit'], 'kind' => 'report', 'authorize' => $manager],
                'bills_table' => ['action' => fn ($text = 'Payables') => 'payables', 'label' => 'Payables', 'entity' => 'Bill', 'kind' => 'list', 'authorize' => $manager],
                'team_settings' => ['action' => fn ($text = 'Team settings') => 'team', 'label' => 'Team settings', 'authorize' => $manager],
                'support' => ['action' => fn ($text = 'Contact support') => 'support', 'label' => 'Contact support'],
            ],
        ]);

        Route::get('/user/profile', fn () => 'profile')->name('profile.show');
        app('router')->getRoutes()->refreshNameLookups();
    }

    protected function manager(): object
    {
        return (object) ['id' => 13, 'role' => 'manager', 'email' => 'lead@example.com', 'name' => 'Lea Lead', 'current_team_id' => 3];
    }

    protected function resident(): object
    {
        return (object) ['id' => 40, 'role' => 'resident', 'email' => 'res@example.com', 'name' => 'Remi Resident', 'current_team_id' => 3];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $qu QueryUnderstandingResult named args
     */
    protected function turn(array $rows, ?string $cypher, array $qu = [], array $metadata = []): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = 'q';
        $ctx->qu = new QueryUnderstandingResult(...($qu + ['questionType' => 'data_query', 'language' => 'en']));
        $ctx->queryResult = new QueryGenerationResult(cypher: $cypher);
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $rows, stats: $metadata, metadata: $metadata);

        return $ctx;
    }

    /** Three different units named "310" in three unions (live 2026-09-30). */
    protected function homonymRows(): array
    {
        return [
            ['unit_id' => 159, 'unit_name' => '310', 'union_name' => 'Carré Soho Phase 3', 'balance' => 120.5],
            ['unit_id' => 412, 'unit_name' => '310', 'union_name' => 'Les Terrasses', 'balance' => 0.0],
            ['unit_id' => 733, 'unit_name' => '310', 'union_name' => 'Bruxelles', 'balance' => 87.25],
        ];
    }

    protected const HOMONYM_CYPHER = 'MATCH (u:Unit)-[:UNION]->(un:Union) WHERE u.name = \'310\' RETURN u.id AS unit_id, u.name AS unit_name, un.name AS union_name, u.balance AS balance';

    /** @return list<array<string, mixed>> */
    protected function unitRoster(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['unit_id' => 1000 + $i, 'unit_name' => 'Lot ' . $i, 'union_name' => 'Union A'];
        }

        return $rows;
    }

    protected const ROSTER_CYPHER = 'MATCH (u:Unit)-[:UNION]->(un:Union) RETURN u.id AS unit_id, u.name AS unit_name, un.name AS union_name';

    /**
     * @param list<array<string, mixed>> $invocations
     * @return list<string> "key|entity_type|entity_id|text"
     */
    protected function chipLines(array $invocations): array
    {
        return array_map(
            static fn (array $i) => implode('|', [$i['key'], $i['entity_type'] ?? '', (string) ($i['entity_id'] ?? ''), $i['text']]),
            $invocations
        );
    }
}
