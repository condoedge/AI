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
}
