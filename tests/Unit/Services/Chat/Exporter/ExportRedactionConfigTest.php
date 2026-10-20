<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Chat\Exporter;

use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;
use Condoedge\Ai\Tests\TestCase;

/**
 * Export redaction is driven by its OWN config list (ai.export.redact_columns), not the
 * LLM PII-minimization list (ai.access_control.identifying_fields).
 *
 * The result set an export serializes has already passed tenant scoping and the host ACL and
 * is what the user sees on screen, so reusing the LLM-minimization list to strip authorized,
 * explicitly-requested columns (email, phone) from the generated file is a bug — it makes
 * "export the residents' emails" silently produce a file with no emails. Internal id-ish
 * columns are still always stripped (schema-leak protection, independent of PII policy).
 */
class ExportRedactionConfigTest extends TestCase
{
    /** @test */
    public function export_redaction_does_not_reuse_the_llm_minimization_list(): void
    {
        // Default config ships ai.access_control.identifying_fields with email/phone. Exports
        // must NOT inherit that list, so an authorized, explicitly-requested column survives.
        $rows = [['name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '555-1000']];

        $redacted = (new ResultSetExporter())->redact($rows);

        $this->assertSame('alice@example.com', $redacted[0]['email']);
        $this->assertSame('555-1000', $redacted[0]['phone']);
    }

    /** @test */
    public function export_redaction_honors_the_dedicated_redact_columns_config(): void
    {
        config(['ai.export.redact_columns' => ['email']]);

        $rows = [['name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '555-1000']];

        $redacted = (new ResultSetExporter())->redact($rows);

        $this->assertArrayNotHasKey('email', $redacted[0]);
        // A column the host did NOT list stays, even though it is in identifying_fields.
        $this->assertSame('555-1000', $redacted[0]['phone']);
        $this->assertSame('Alice', $redacted[0]['name']);
    }

    /**
     * Task 1.27 follow-up: entity-action enrichment keys and the `_label` marker
     * are UI decorations, never data — the xlsx/pdf matrix must not carry them,
     * even when a host disables redaction (ai.export.redact_rows = false).
     *
     * @test
     * @dataProvider redactionToggle
     */
    public function enrichment_keys_and_label_never_reach_the_export_matrix(bool $redactRows): void
    {
        config(['ai.export.redact_rows' => $redactRows]);
        $rows = [[
            '_label' => 'Team',
            'name' => 'Board',
            'has_actions' => true,
            'available_actions' => [['action_key' => 'view']],
            'entity_type' => 'Team',
            'action_links' => ['[View: Board](entity://Team/3/view)'],
        ]];

        $exporter = new ResultSetExporter();
        // Exactly the toExcel()/toPdf() write path (Phase 8 batch A): THE TableRenderer's
        // matrix over redact($rows) — the exporter's private toMatrix() is gone.
        [$headers] = (new \Condoedge\Ai\Services\Response\TableRenderer($exporter))
            ->matrix($exporter->redact($rows), ['redact' => false]);

        $this->assertSame(['Name'], $headers);
    }

    public static function redactionToggle(): array
    {
        return ['redaction on' => [true], 'redaction off' => [false]];
    }

    /** @test */
    public function internal_id_columns_are_always_stripped_regardless_of_redact_columns(): void
    {
        $rows = [['neo4j_id' => 'x1', 'unit_id' => 5, '_meta' => 'm', 'name' => 'Alice']];

        $redacted = (new ResultSetExporter())->redact($rows);

        $this->assertArrayNotHasKey('neo4j_id', $redacted[0]);
        $this->assertArrayNotHasKey('unit_id', $redacted[0]);
        $this->assertArrayNotHasKey('_meta', $redacted[0]);
        $this->assertSame('Alice', $redacted[0]['name']);
    }
}
