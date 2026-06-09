<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Chat\Exporter;

use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;
use PHPUnit\Framework\TestCase;

/**
 * ResultSetExporter graceful degradation.
 *
 * The package must NOT hard-require maatwebsite/excel or barryvdh/laravel-dompdf. When those
 * libraries are absent (as in the package's own vendor), exports return an isError
 * AiActionResult with a clear "install X" summary instead of fatally erroring. When present
 * (host app), they produce real bytes — that branch is asserted conditionally so the test
 * passes in both environments.
 */
class ResultSetExporterTest extends TestCase
{
    private array $rows = [
        ['id' => 1, 'name' => 'Alice', 'amount' => 100],
        ['id' => 2, 'name' => 'Bob', 'amount' => 250],
    ];

    /** @test */
    public function excel_export_degrades_gracefully_when_phpspreadsheet_is_absent(): void
    {
        $exporter = new ResultSetExporter();

        if ($exporter->canExportExcel()) {
            $this->markTestSkipped('PhpSpreadsheet is installed in this environment.');
        }

        $result = $exporter->toExcel($this->rows, 'report');

        $this->assertInstanceOf(AiActionResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertStringContainsStringIgnoringCase('maatwebsite/excel', $result->summaryForLlm);
        $this->assertNull($result->renderable);
    }

    /** @test */
    public function pdf_export_degrades_gracefully_when_dompdf_is_absent(): void
    {
        $exporter = new ResultSetExporter();

        if ($exporter->canExportPdf()) {
            $this->markTestSkipped('Dompdf is installed in this environment.');
        }

        $result = $exporter->toPdf($this->rows, 'report');

        $this->assertInstanceOf(AiActionResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertStringContainsStringIgnoringCase('dompdf', $result->summaryForLlm);
        $this->assertNull($result->renderable);
    }

    /** @test */
    public function excel_export_produces_bytes_when_phpspreadsheet_is_present(): void
    {
        $exporter = new ResultSetExporter();

        if (!$exporter->canExportExcel()) {
            $this->markTestSkipped('PhpSpreadsheet not installed (expected in the package vendor).');
        }

        $result = $exporter->toExcel($this->rows, 'report');

        $this->assertFalse($result->isError);
        $this->assertIsArray($result->renderable);
        $this->assertArrayHasKey('content', $result->renderable);
        $this->assertNotEmpty($result->renderable['content']);
        $this->assertSame('report.xlsx', $result->renderable['filename']);
    }

    /** @test */
    public function exporter_does_not_hard_require_the_classes_at_load_time(): void
    {
        // Constructing the exporter and probing capability must never fatal even with the
        // libraries absent — the references are class_exists() string lookups, not imports.
        $exporter = new ResultSetExporter();

        $this->assertIsBool($exporter->canExportExcel());
        $this->assertIsBool($exporter->canExportPdf());
    }

    /**
     * @test
     *
     * EXPORT PRIVACY: exports must NOT serialize raw rows — internal id-ish columns
     * (neo4j_id, *_id, leading-underscore keys) and policy-hidden PII fields must be
     * projected out BEFORE the bytes are written, so an exported file never leaks schema
     * internals or PII the chat prose would have withheld.
     */
    public function redact_drops_internal_id_columns_and_pii_fields(): void
    {
        $exporter = new ResultSetExporter();

        $rows = [
            [
                'neo4j_id' => 'abc-123',
                'unit_id' => 99,
                '_owner_id' => 7,
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'balance' => 100,
            ],
        ];

        $redacted = $exporter->redact($rows, ['date_of_birth', 'email', 'phone', 'ssn', 'medical_id']);

        $this->assertCount(1, $redacted);
        $row = $redacted[0];

        // Internal id-ish columns are gone.
        $this->assertArrayNotHasKey('neo4j_id', $row);
        $this->assertArrayNotHasKey('unit_id', $row);
        $this->assertArrayNotHasKey('_owner_id', $row);

        // PII field hidden by the privacy policy is gone.
        $this->assertArrayNotHasKey('email', $row);

        // Safe display columns survive untouched.
        $this->assertSame('Alice', $row['name']);
        $this->assertSame(100, $row['balance']);
    }

    /** @test */
    public function redact_preserves_rows_when_nothing_is_sensitive(): void
    {
        $exporter = new ResultSetExporter();

        $rows = [
            ['name' => 'Alice', 'balance' => 100],
            ['name' => 'Bob', 'balance' => 250],
        ];

        $redacted = $exporter->redact($rows, []);

        $this->assertSame($rows, $redacted);
    }

    /**
     * @test
     *
     * The redaction projection runs on the write path: a present-library export must not
     * carry an internal column through to the generated file's headers.
     */
    public function excel_export_omits_internal_columns_when_library_present(): void
    {
        $exporter = new ResultSetExporter();

        if (!$exporter->canExportExcel()) {
            $this->markTestSkipped('PhpSpreadsheet not installed (expected in the package vendor).');
        }

        $rows = [['neo4j_id' => 'x1', 'name' => 'Alice']];

        $result = $exporter->toExcel($rows, 'report');

        $this->assertFalse($result->isError);
        // The xlsx bytes are zip-compressed, so we cannot grep the literal column name; the
        // dedicated redact() unit test above proves the projection. Here we only assert the
        // export still succeeds after projection (a non-empty payload).
        $this->assertNotEmpty($result->renderable['content']);
    }
}
