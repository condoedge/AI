<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Response\ExportResponseComposer;
use Condoedge\Ai\Tests\TestCase;

/**
 * Unit seam for the export composition extracted out of AiManager.
 *
 * AiManager now delegates export/document response composition to this
 * collaborator; these tests pin its behavior directly so the seam is covered
 * independently of the full pipeline characterization in AiManagerTest.
 */
class ExportResponseComposerTest extends TestCase
{
    private ExportResponseComposer $composer;

    public function setUp(): void
    {
        parent::setUp();

        $this->composer = new ExportResponseComposer();
    }

    /** @test */
    public function detects_pdf_export_intent_from_an_action_request(): void
    {
        $intent = $this->composer->detectExportIntent('Can you create a pdf of my information?');

        $this->assertSame(['format' => 'pdf'], $intent);
    }

    /** @test */
    public function detects_excel_export_intent(): void
    {
        $intent = $this->composer->detectExportIntent('export that to Excel');

        $this->assertSame(['format' => 'excel'], $intent);
    }

    /** @test */
    public function returns_null_when_no_document_format_is_mentioned(): void
    {
        $this->assertNull($this->composer->detectExportIntent('How many residents are there?'));
    }

    /** @test */
    public function document_response_renders_markdown_table_and_strips_internal_ids(): void
    {
        $rows = [[
            'name' => 'Alice Martin',
            'role' => 'Owner',
            'user_id' => 123,
            'neo4j_id' => 'abc',
        ]];

        $response = $this->composer->generateExportDocumentResponse(
            'Can you create a pdf of my information?',
            ['format' => 'pdf'],
            $rows,
            'previous_result'
        );

        // The chat answer carries the field summary and the action link, not the table.
        $this->assertStringContainsString('I prepared the PDF', $response['answer']);
        $this->assertStringContainsString('Fields included: Name, Role.', $response['answer']);
        $this->assertStringContainsString('action://export_pdf?mode=document', $response['answer']);
        $this->assertStringNotContainsString('| Name | Role |', $response['answer']);

        // The document markdown carries the table; internal id-ish columns are dropped.
        $document = $response['metadata']['export_document_markdown'];
        // A previous-result export with NO recorded subject must NEVER echo the
        // request wording as the title (live bug: "Ok, give me the excel" became
        // User_Ok_give_me_the_excel.xlsx) — it falls to the generic title.
        $this->assertStringContainsString('# Requested Information', $document);
        $this->assertStringNotContainsString('Can you create a pdf', $document);
        $this->assertStringContainsString('As of: ', $document);
        $this->assertStringContainsString('| Name | Role |', $document);
        $this->assertStringContainsString('Alice Martin', $document);
        $this->assertStringNotContainsString('neo4j', strtolower($document));
        $this->assertStringNotContainsString('User Id', $document);

        $this->assertTrue($response['metadata']['export_intent']);
        $this->assertSame('previous_result', $response['metadata']['export_source']);
        $this->assertSame('pdf', $response['metadata']['export_format']);
    }

    /** @test */
    public function document_title_carries_the_focused_entity_subject_and_an_iso_as_of_date(): void
    {
        $response = $this->composer->generateExportDocumentResponse(
            'Show the outstanding amounts',
            ['format' => 'pdf'],
            [['unit_name' => '310', 'balance' => 125.5]],
            'previous_result',
            ['entity' => 'Unit', 'complete' => true, 'total_rows' => 1]
        );

        $document = $response['metadata']['export_document_markdown'];
        // Previous-result export without a recorded subject: titled by the
        // entity alone — the request wording is banned as a title fallback.
        $this->assertStringContainsString('# Unit', $document);
        $this->assertStringNotContainsString('Show the outstanding amounts', $document);
        $this->assertMatchesRegularExpression('/As of: \d{4}-\d{2}-\d{2}/', $document);
        // Columns stay labeled — no bare unlabeled numbers.
        $this->assertStringContainsString('| Unit Name | Balance |', $document);
    }

    /** @test */
    public function previous_result_export_titles_by_the_recorded_subject(): void
    {
        $response = $this->composer->generateExportDocumentResponse(
            'Ok, give me the excel',
            ['format' => 'excel'],
            [['member_name' => 'Alice Martin', 'member_email' => 'a@x.com']],
            'previous_result',
            ['subject' => 'team members details', 'complete' => true, 'total_rows' => 1]
        );

        $document = $response['metadata']['export_document_markdown'];
        // The PREVIOUS turn's subject titles the export — what the data IS.
        $this->assertStringContainsString('# Team members details', $document);
        $this->assertStringNotContainsString('give me the excel', $document);
    }

    /** @test */
    public function sample_fallback_discloses_showing_x_of_n_in_answer_and_payload(): void
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ['name' => "Member {$i}", 'email' => "m{$i}@x.com"];
        }

        $response = $this->composer->generateExportDocumentResponse(
            'Give me that as an Excel',
            ['format' => 'excel'],
            $rows,
            'previous_result',
            ['complete' => false, 'total_rows' => 100]
        );

        $this->assertStringContainsString('The document shows 10 of 100 rows.', $response['answer']);
        $this->assertStringContainsString('Showing 10 of 100 rows.', $response['metadata']['export_document_markdown']);
        $this->assertFalse($response['metadata']['export_source_complete']);
        $this->assertSame(100, $response['metadata']['result_total']);
    }

    /** @test */
    public function full_result_export_carries_every_row_up_to_the_document_limit(): void
    {
        config()->set('ai.export.document_row_limit', 250);

        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['name' => "Member {$i}"];
        }

        $response = $this->composer->generateExportDocumentResponse(
            'Full roster please, as an excel',
            ['format' => 'excel'],
            $rows,
            'current_result',
            ['complete' => true, 'total_rows' => 100]
        );

        $document = $response['metadata']['export_document_markdown'];
        $this->assertStringContainsString('Member 1', $document);
        $this->assertStringContainsString('Member 100', $document);
        $this->assertSame(100, $response['metadata']['result_count']);
        // Complete set shown in full → no misleading "showing X of Y" line.
        $this->assertStringNotContainsString('Showing', $document);
    }

    /** @test */
    public function trimming_to_the_document_row_limit_discloses_in_both_answer_and_payload(): void
    {
        config()->set('ai.export.document_row_limit', 5);

        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = ['name' => "Member {$i}"];
        }

        $response = $this->composer->generateExportDocumentResponse(
            'roster as excel',
            ['format' => 'excel'],
            $rows,
            'current_result',
            ['complete' => true, 'total_rows' => 8]
        );

        $this->assertStringContainsString('The document shows 5 of 8 rows.', $response['answer']);
        $this->assertStringContainsString('Showing 5 of 8 rows.', $response['metadata']['export_document_markdown']);
    }

    /** @test */
    public function qu_detected_language_overrides_the_internal_language_heuristic(): void
    {
        // An English question, but QU says the question is French → French copy.
        $response = $this->composer->generateExportDocumentResponse(
            'Donnez-moi le tableau',
            ['format' => 'excel'],
            [['name' => 'Alice']],
            'previous_result',
            ['language' => 'fr']
        );

        $this->assertStringContainsString("J'ai prepare le fichier Excel", $response['answer']);
        $this->assertStringContainsString('En date du', $response['metadata']['export_document_markdown']);
    }

    /** @test */
    public function clarification_response_is_emitted_when_there_is_nothing_to_export(): void
    {
        $response = $this->composer->generateExportClarificationResponse(
            'Can you create a pdf of my team?',
            ['format' => 'pdf']
        );

        $this->assertStringContainsString('I can create a PDF', $response['answer']);
        $this->assertStringContainsString('which information to include', $response['answer']);
        $this->assertTrue($response['metadata']['export_intent']);
        $this->assertTrue($response['metadata']['needs_export_source']);
    }

    /** @test */
    public function identity_rows_project_configured_fields_for_a_possessive_request(): void
    {
        config()->set('ai.export.identity_fields', [
            ['label' => 'Name', 'path' => 'user.name'],
            ['label' => 'Email', 'path' => 'user.email'],
        ]);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(isPossessive: true);

        $rows = $this->composer->identityExportRows($ctx, [
            'user' => (object) [
                'name' => 'Julie Rousseau',
                'email' => 'j.rousseau@novogestion.ca',
                'password' => 'secret',
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Julie Rousseau', $rows[0]['Name']);
        $this->assertSame('j.rousseau@novogestion.ca', $rows[0]['Email']);
        $this->assertArrayNotHasKey('password', $rows[0]);
    }

    /** @test */
    public function identity_rows_are_empty_for_a_non_possessive_request(): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(isPossessive: false);

        $this->assertSame([], $this->composer->identityExportRows($ctx, [
            'user' => (object) ['name' => 'Julie Rousseau'],
        ]));
    }
}
