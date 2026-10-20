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
    public function the_composer_does_no_export_intent_or_language_detection(): void
    {
        // Phase 2.B: export intent is QU exportFormat, language is QU language.
        $this->assertFalse(method_exists($this->composer, 'detectExportIntent'));
        $this->assertFalse(method_exists($this->composer, 'questionLanguage'));
    }

    /** @test */
    public function strings_follow_the_qu_language_not_the_question_wording(): void
    {
        // A French-worded question but QU says 'en' → English copy (the old EN/FR
        // word list would have matched "votre"/"equipe" and answered in French).
        $en = $this->composer->generateExportDocumentResponse(
            'Pouvez-vous exporter votre equipe ?',
            ['format' => 'excel'],
            [['name' => 'Alice']],
            'current_result',
            ['language' => 'en', 'complete' => false, 'total_rows' => 1]
        );
        $this->assertStringContainsString('I prepared the Excel file', $en['answer']);
        $this->assertStringContainsString('Fields included: Name.', $en['answer']);
        $this->assertStringContainsString('[Download Excel]', $en['answer']);
        $this->assertStringContainsString('I prepared the Excel file with the first 1 rows — more may exist.', $en['answer']);
        $this->assertStringContainsString('As of: ', $en['metadata']['export_document_markdown']);

        // An English-worded question but QU says 'fr' → French copy, from lang JSON.
        $fr = $this->composer->generateExportDocumentResponse(
            'Give me that as a PDF',
            ['format' => 'pdf'],
            array_fill(0, 3, ['name' => 'Alice']),
            'previous_result',
            ['language' => 'fr', 'complete' => false, 'total_rows' => 9]
        );
        $this->assertStringContainsString("J'ai préparé le PDF", $fr['answer']);
        $this->assertStringContainsString('Champs inclus : Name.', $fr['answer']);
        $this->assertStringContainsString("J'ai préparé le PDF avec les 3 premières lignes sur 9.", $fr['answer']);
        $this->assertStringContainsString('[Télécharger le PDF]', $fr['answer']);
        $this->assertStringContainsString('# Informations demandées', $fr['metadata']['export_document_markdown']);
        $this->assertStringContainsString('Affiche 3 lignes sur 9.', $fr['metadata']['export_document_markdown']);
    }

    /** @test */
    public function without_a_qu_language_the_app_locale_applies_never_a_word_list(): void
    {
        app()->setLocale('fr');
        try {
            $response = $this->composer->generateExportClarificationResponse(
                'Can you create a pdf of my team?',
                ['format' => 'pdf']
            );
        } finally {
            app()->setLocale('en');
        }

        $this->assertStringContainsString('Je peux créer un PDF', $response['answer']);
    }

    /** @test */
    public function every_export_lang_key_exists_in_en_and_fr(): void
    {
        $en = json_decode((string) file_get_contents(__DIR__ . '/../../../../resources/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);
        $fr = json_decode((string) file_get_contents(__DIR__ . '/../../../../resources/lang/fr.json'), true, 512, JSON_THROW_ON_ERROR);

        $keys = array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'ai.export.'));
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $fr, "fr.json misses {$key}");
        }
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

    /**
     * @test Eval p12-B: a distilled subject is the whole title. The entity prefix is a raw
     * schema label (English, "User") — prepended to a localized subject it produced a
     * mixed-language heading ("User — Rôles des membres de l'équipe").
     */
    public function a_distilled_subject_titles_the_export_without_the_entity_prefix(): void
    {
        $response = $this->composer->generateExportDocumentResponse(
            'Peux-tu me donner un Excel des membres avec leurs rôles ?',
            ['format' => 'excel'],
            [['member_name' => 'Alice Martin', 'role' => 'lead']],
            'current_result',
            ['subject' => "état des rôles de l'équipe", 'entity' => 'User', 'language' => 'fr', 'complete' => true, 'total_rows' => 1]
        );

        $document = $response['metadata']['export_document_markdown'];
        $this->assertStringContainsString("# État des rôles de l'équipe", $document, 'multibyte-safe capitalization too');
        $this->assertStringNotContainsString('User —', $document);
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

        $this->assertStringContainsString('I prepared the Excel file with the first 10 of 100 rows.', $response['answer']);
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

        $this->assertStringContainsString('I prepared the Excel file with the first 5 of 8 rows.', $response['answer']);
        $this->assertStringContainsString('Showing 5 of 8 rows.', $response['metadata']['export_document_markdown']);
    }

    /**
     * @test Eval p12-1001-A: a 523-member roster shipped "250 of 523". The PDF
     * renderer (dompdf, in memory) is the heavy path — measured ~10.6 s / 104 MB
     * at 523 rows, ~28 s / 194 MB at 1000 — the .xlsx writer ~1 s / 24 MB at
     * 2000. A PDF document has its own cap (ai.export.pdf_document_row_limit,
     * default = the document limit); an Excel document keeps the document limit.
     */
    public function a_pdf_document_has_its_own_row_cap(): void
    {
        config()->set('ai.export.document_row_limit', 8);
        config()->set('ai.export.pdf_document_row_limit', 5);
        $rows = array_map(static fn (int $i): array => ['name' => "Member {$i}"], range(1, 8));

        $pdf = $this->composer->generateExportDocumentResponse('roster as pdf', ['format' => 'pdf'], $rows, 'current_result', ['complete' => true, 'total_rows' => 8]);
        $excel = $this->composer->generateExportDocumentResponse('roster as excel', ['format' => 'excel'], $rows, 'current_result', ['complete' => true, 'total_rows' => 8]);

        $this->assertStringContainsString('I prepared the PDF with the first 5 of 8 rows.', $pdf['answer']);
        $this->assertCount(8, $excel['metadata']['export_document_rows']);
        $this->assertStringNotContainsString('of 8 rows', $excel['answer']);

        $this->assertSame(5, ExportResponseComposer::documentRowLimit('pdf'));
        $this->assertSame(8, ExportResponseComposer::documentRowLimit('excel'));
        config()->set('ai.export.pdf_document_row_limit', null);
        $this->assertSame(8, ExportResponseComposer::documentRowLimit('pdf'), 'unset: the document limit');
        // Never above the document limit (the re-execution fetches at most that many rows).
        config()->set('ai.export.pdf_document_row_limit', 50);
        $this->assertSame(8, ExportResponseComposer::documentRowLimit('pdf'));
    }

    /** @test */
    public function qu_detected_language_drives_the_copy(): void
    {
        // QU says the question is French → French copy.
        $response = $this->composer->generateExportDocumentResponse(
            'Donnez-moi le tableau',
            ['format' => 'excel'],
            [['name' => 'Alice']],
            'previous_result',
            ['language' => 'fr']
        );

        $this->assertStringContainsString("J'ai préparé le fichier Excel", $response['answer']);
        $this->assertStringContainsString('En date du', $response['metadata']['export_document_markdown']);
    }

    // =========================================================================
    // The export sentence states the ROW COUNT and never hedges (eval p20: A roster-excel /
    // unit-310 PDF, B resilience t3 / cold-roster / french t1)
    // =========================================================================

    private const ROSTER_CYPHER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role, u.id AS user_id LIMIT 100';

    /** @return array<int, array<string, mixed>> $count members, one distinct user each */
    private function roster(int $count): array
    {
        return array_map(
            static fn (int $i): array => ['name' => "Member {$i}", 'email' => "m{$i}@x.test", 'role' => 'contact', 'user_id' => 100 + $i],
            range(1, $count)
        );
    }

    private function declareMembers(): void
    {
        config([
            'ai.response_generation.property_header_language' => 'en',
            'entities' => [
                'App\\Models\\User' => ['graph' => ['label' => 'User'], 'metadata' => ['display_name' => 'member', 'display_name_plural' => 'members']],
                'App\\Models\\Team' => ['graph' => ['label' => 'Team'], 'metadata' => []],
                'App\\Models\\TeamRole' => ['graph' => ['label' => 'TeamRole'], 'metadata' => []],
            ],
        ]);
    }

    /**
     * @test eval p20-A roster-excel ("I prepared the Excel file with the available information." above
     * a complete 523-row file): a COMPLETE file says how many rows it holds, and the hedge is gone.
     */
    public function a_complete_export_states_its_row_count_and_does_not_hedge(): void
    {
        $rows = array_map(static fn (int $i): array => ['name' => "Member {$i}"], range(1, 12));
        $meta = ['complete' => true, 'total_rows' => 12];

        $en = $this->composer->generateExportDocumentResponse('roster as excel', ['format' => 'excel'], $rows, 'current_result', $meta + ['language' => 'en']);
        $this->assertStringStartsWith("I prepared the Excel file with all 12 rows.\n\nFields included: Name.", $en['answer']);
        $this->assertStringNotContainsString('available information', $en['answer']);
        $this->assertTrue($en['metadata']['export_source_complete']);

        $pdf = $this->composer->generateExportDocumentResponse('roster as pdf', ['format' => 'pdf'], $rows, 'previous_result', $meta + ['language' => 'en']);
        $this->assertStringStartsWith('I prepared the PDF with all 12 rows.', $pdf['answer']);

        $fr = $this->composer->generateExportDocumentResponse('liste en excel', ['format' => 'excel'], $rows, 'current_result', $meta + ['language' => 'fr']);
        $this->assertStringStartsWith("J'ai préparé le fichier Excel avec les 12 lignes.\n\nChamps inclus : Name.", $fr['answer']);
        $this->assertStringNotContainsString('informations disponibles', $fr['answer']);

        $frPdf = $this->composer->generateExportDocumentResponse('liste en pdf', ['format' => 'pdf'], $rows, 'current_result', $meta + ['language' => 'fr']);
        $this->assertStringStartsWith("J'ai préparé le PDF avec les 12 lignes.", $frPdf['answer']);
    }

    /**
     * @test eval p20-B resilience t3 / french t1 (523 members, "no row count"): the count is said
     * in the HOST's noun when the rows are PROVEN to be that many records of one kind — every row
     * one distinct record of the same entity (the ids the source query projects), the entity's
     * declared plural, in the answer's language.
     */
    public function the_row_count_uses_the_hosts_noun_when_the_rows_are_that_many_records(): void
    {
        $this->declareMembers();
        config()->set('ai.export.document_row_limit', 1000); // the host's (CondoEdge exports its 523 members whole)
        app('translator')->get('ai.export.prepared_excel', [], 'fr');
        app('translator')->addLines(['*.members' => 'membres'], 'fr');
        $meta = ['complete' => true, 'total_rows' => 523, 'cypher' => self::ROSTER_CYPHER, 'entity' => 'User'];

        $en = $this->composer->generateExportDocumentResponse('Ok, give me the excel', ['format' => 'excel'], $this->roster(523), 'previous_result', $meta + ['language' => 'en']);
        $this->assertStringStartsWith('I prepared the Excel file with all 523 members.', $en['answer']);
        $this->assertCount(523, $en['metadata']['export_document_rows']);

        $fr = $this->composer->generateExportDocumentResponse('Donne-moi le fichier Excel', ['format' => 'excel'], $this->roster(523), 'previous_result', $meta + ['language' => 'fr']);
        $this->assertStringStartsWith("J'ai préparé le fichier Excel avec les 523 membres.", $fr['answer']);

        $pdf = $this->composer->generateExportDocumentResponse('Create a PDF of this', ['format' => 'pdf'], $this->roster(3), 'previous_result', ['total_rows' => 3] + $meta + ['language' => 'en']);
        $this->assertStringStartsWith('I prepared the PDF with all 3 members.', $pdf['answer']);
    }

    /**
     * @test Nothing is guessed: rows without record ids, rows that are not one record each (a
     * fan-out: the same member on two rows), a kind the host did not name or cannot say in the
     * answer's language — the file is counted in rows.
     */
    public function without_a_proven_and_named_kind_the_file_is_counted_in_rows(): void
    {
        $this->declareMembers();
        $meta = ['complete' => true, 'total_rows' => 4, 'cypher' => self::ROSTER_CYPHER, 'language' => 'en'];
        $rows = $this->roster(4);

        $noIds = array_map(static fn (array $row): array => array_diff_key($row, ['user_id' => true]), $rows);
        $this->assertStringStartsWith('I prepared the Excel file with all 4 rows.', $this->composer->generateExportDocumentResponse('x', ['format' => 'excel'], $noIds, 'current_result', $meta)['answer']);

        $fanOut = $rows;
        $fanOut[3]['user_id'] = $fanOut[2]['user_id'];
        $this->assertStringStartsWith('I prepared the Excel file with all 4 rows.', $this->composer->generateExportDocumentResponse('x', ['format' => 'excel'], $fanOut, 'current_result', $meta)['answer']);

        // No French line for "members": "avec les 4 members" would mix languages.
        $this->assertStringStartsWith(
            "J'ai préparé le fichier Excel avec les 4 lignes.",
            $this->composer->generateExportDocumentResponse('x', ['format' => 'excel'], $rows, 'current_result', ['language' => 'fr'] + $meta)['answer']
        );

        config(['entities' => ['App\\Models\\User' => ['graph' => ['label' => 'User'], 'metadata' => []]]]);
        $this->assertStringStartsWith('I prepared the Excel file with all 4 rows.', $this->composer->generateExportDocumentResponse('x', ['format' => 'excel'], $rows, 'current_result', $meta)['answer']);
    }

    /**
     * @test A file that does NOT hold the whole result never says "all": ONE sentence states the cap
     * honestly — "the first X of Y rows" when the total is known, "the first X rows — more may
     * exist" when the source hit the executor's cap — in rows, whatever the kind (the host noun
     * counts records of the WHOLE result, which this file is not).
     */
    public function a_truncated_export_states_the_cap_in_one_honest_sentence(): void
    {
        $this->declareMembers();
        config()->set('ai.export.document_row_limit', 250);
        $meta = ['complete' => false, 'total_rows' => 523, 'cypher' => self::ROSTER_CYPHER];

        $en = $this->composer->generateExportDocumentResponse('roster as excel', ['format' => 'excel'], $this->roster(250), 'current_result', $meta + ['language' => 'en']);
        $this->assertStringStartsWith('I prepared the Excel file with the first 250 of 523 rows.', $en['answer']);
        $this->assertStringNotContainsString(' all ', $en['answer']);
        $this->assertStringNotContainsString('available information', $en['answer']);
        $this->assertSame(1, substr_count($en['answer'], '523'), 'the cap is stated once');
        $this->assertFalse($en['metadata']['export_source_complete']);

        $fr = $this->composer->generateExportDocumentResponse('liste en pdf', ['format' => 'pdf'], $this->roster(250), 'current_result', $meta + ['language' => 'fr']);
        $this->assertStringStartsWith("J'ai préparé le PDF avec les 250 premières lignes sur 523.", $fr['answer']);

        // The executor's cap was hit and no true total is known: more may exist.
        $capped = ['complete' => false, 'total_rows' => 100, 'cypher' => self::ROSTER_CYPHER];
        $unknown = $this->composer->generateExportDocumentResponse('roster as excel', ['format' => 'excel'], $this->roster(100), 'current_result', $capped + ['language' => 'en']);
        $this->assertStringStartsWith('I prepared the Excel file with the first 100 rows — more may exist.', $unknown['answer']);
        $unknownFr = $this->composer->generateExportDocumentResponse('liste', ['format' => 'excel'], $this->roster(100), 'current_result', $capped + ['language' => 'fr']);
        $this->assertStringStartsWith("J'ai préparé le fichier Excel avec les 100 premières lignes — il peut en exister davantage.", $unknownFr['answer']);
    }

    /** @test ONE row (a single record, the user's own profile) is no list: the sentence carries no count — and no hedge. */
    public function a_one_row_export_carries_neither_a_count_nor_a_hedge(): void
    {
        $record = $this->composer->generateExportDocumentResponse('Create a PDF of this', ['format' => 'pdf'], [['name' => 'Alice', 'city' => 'Montréal']], 'previous_result', ['complete' => true, 'total_rows' => 1, 'language' => 'en']);
        $this->assertStringStartsWith("I prepared the PDF.\n\nFields included: Name, City.", $record['answer']);

        $identity = $this->composer->generateExportDocumentResponse('Export my info', ['format' => 'excel'], [['User Name' => 'Alice']], 'current_identity', ['complete' => true, 'total_rows' => 1, 'language' => 'fr']);
        $this->assertStringStartsWith("J'ai préparé le fichier Excel.\n\n", $identity['answer']);
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

    /** @test Phase 8 batch A: the Excel document export gets rows, not a markdown round trip. */
    public function the_document_rows_ship_beside_the_markdown(): void
    {
        $response = $this->composer->generateExportDocumentResponse(
            'members',
            ['format' => 'excel'],
            [['unit_id' => 9, 'name' => 'Karen', 'email' => 'karen@x.test']],
            'current_result'
        );

        // Same projection as the document table: internal *_id columns redacted, the
        // user's authorized columns (email) kept — exports carry real values.
        $this->assertSame([['name' => 'Karen', 'email' => 'karen@x.test']], $response['metadata']['export_document_rows']);
        $this->assertStringContainsString('| Name | Email |', $response['metadata']['export_document_markdown']);
        $this->assertStringContainsString('| Karen | karen@x.test |', $response['metadata']['export_document_markdown']);
    }

    /**
     * @test Eval p9 cold-roster gate (2026-10-01): a projected column that is
     * empty (null / blank) in EVERY exported row was promised under "Fields
     * included" and written into the Excel header ("Contact Reduced
     * Mobility" — never populated in the DB). Data-driven: such a column is
     * dropped from the document rows, the table header and the field list,
     * and named once as having no values (never as a "requested field not
     * included").
     */
    public function a_column_empty_in_every_row_is_left_out_of_the_file_and_the_field_list(): void
    {
        $rows = [
            ['user_name' => 'Julie', 'user_email' => 'j@x.test', 'reduced_mobility' => null, 'company' => ''],
            ['user_name' => 'Karen', 'user_email' => 'k@x.test', 'reduced_mobility' => null, 'company' => '  '],
            ['user_name' => 'Sven', 'user_email' => null, 'reduced_mobility' => null, 'company' => null],
        ];

        $response = $this->composer->generateExportDocumentResponse(
            'members with contact info',
            ['format' => 'excel'],
            $rows,
            'current_result',
            [
                'language' => 'en',
                'complete' => true,
                'total_rows' => 3,
                'requested_fields' => ['user_name', 'user_email', 'reduced_mobility', 'company'],
            ]
        );

        $documentRows = $response['metadata']['export_document_rows'];
        $this->assertSame(['user_name', 'user_email'], array_keys($documentRows[0]));
        // A column with SOME values stays, including its null cells.
        $this->assertArrayHasKey('user_email', $documentRows[2]);
        $this->assertNull($documentRows[2]['user_email']);

        $markdown = $response['metadata']['export_document_markdown'];
        $this->assertStringContainsString('| User Name | User Email |', $markdown);
        $this->assertStringNotContainsString('Reduced Mobility', $markdown);
        $this->assertStringNotContainsString('Company', $markdown);

        $this->assertStringContainsString('Fields included: User Name, User Email.', $response['answer']);
        $this->assertStringContainsString('No values found for: Reduced Mobility, Company.', $response['answer']);
        $this->assertStringNotContainsString('Requested fields not included', $response['answer']);
    }

    /** The p17-B cold-roster-excel query: 523 emails under a column keyed "contact_info". */
    private const CONTACT_INFO_CYPHER = 'MATCH (u:User) WHERE u.current_team_id = 3 RETURN u.name AS name, u.email AS contact_info, u.id AS user_id LIMIT 100';

    /**
     * @test Eval p17-B cold-roster-excel#0 ("an excel with all the members … with contact
     * info"): the file's emails sat under a "Contact Info" header — no "Email" column — because
     * the generator aliased `u.email AS contact_info`. The header (document table, the xlsx
     * header row and "Fields included") now names the PROPERTY the column holds; the row keys
     * are untouched.
     */
    public function an_exported_column_aliased_after_the_users_word_is_headed_by_its_property(): void
    {
        $response = $this->composer->generateExportDocumentResponse(
            'Can I get an excel with all the members from my current team? With contact info',
            ['format' => 'excel'],
            [
                ['name' => 'Julie Rousseau', 'contact_info' => 'j.rousseau@novogestion.ca', 'user_id' => 13],
                ['name' => 'Service Comptable', 'contact_info' => 'service_comptable@novogestion.ca', 'user_id' => 14],
            ],
            'current_result',
            ['language' => 'en', 'complete' => true, 'total_rows' => 2, 'cypher' => self::CONTACT_INFO_CYPHER]
        );

        $meta = $response['metadata'];
        $this->assertStringContainsString('| Name | Email |', $meta['export_document_markdown']);
        $this->assertStringNotContainsString('Contact Info', $meta['export_document_markdown']);
        $this->assertStringContainsString('Fields included: Name, Email.', $response['answer']);
        $this->assertStringNotContainsString('Contact Info', $response['answer']);

        $this->assertSame(['name' => 'Name', 'contact_info' => 'Email'], $meta['export_document_headers']);
        $this->assertSame(['name', 'contact_info'], array_keys($meta['export_document_rows'][0]), 'row keys are never rewritten');
    }

    /** @test Without the source's cypher nothing is known about the columns: the humanized keys head them, as before. */
    public function without_a_source_cypher_the_headers_are_the_humanized_keys(): void
    {
        $response = $this->composer->generateExportDocumentResponse(
            'members',
            ['format' => 'excel'],
            [['name' => 'Julie', 'contact_info' => 'j@x.test']],
            'previous_result',
            ['language' => 'en']
        );

        $this->assertSame(['name' => 'Name', 'contact_info' => 'Contact Info'], $response['metadata']['export_document_headers']);
        $this->assertStringContainsString('| Name | Contact Info |', $response['metadata']['export_document_markdown']);
        $this->assertStringContainsString('Fields included: Name, Contact Info.', $response['answer']);
    }

    /**
     * @test eval p16/p17 french-flow#1: a French export keeps the generator's French aliases —
     * a property name is an English word ("Name") and has no place in a French file.
     */
    public function a_localized_alias_heads_its_column_in_an_export_in_another_language(): void
    {
        $response = $this->composer->generateExportDocumentResponse(
            'Parfait — exporte-la en Excel.',
            ['format' => 'excel'],
            [['membre' => 'Julie Rousseau', 'rôle' => 'lead']],
            'previous_result',
            [
                'language' => 'fr',
                'cypher' => 'MATCH (u:User) OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) RETURN u.name AS membre, tr.role AS rôle LIMIT 100',
            ]
        );

        $this->assertStringContainsString('| Membre | Rôle |', $response['metadata']['export_document_markdown']);
        $this->assertStringContainsString('Champs inclus : Membre, Rôle.', $response['answer']);
        $this->assertSame(['membre' => 'Membre', 'rôle' => 'Rôle'], $response['metadata']['export_document_headers']);
    }

    /** @test Two columns holding the same property are told apart by their node label, as in the chat table. */
    public function colliding_export_headers_are_told_apart_by_node_label(): void
    {
        $response = $this->composer->generateExportDocumentResponse(
            'owners with both emails',
            ['format' => 'excel'],
            [['name' => 'Julie', 'contact_info' => 'j@work.test', 'reach' => 'j@home.test']],
            'current_result',
            ['language' => 'en', 'cypher' => 'MATCH (u:User)-[:IS]->(c:Contact) RETURN u.name AS name, u.email AS contact_info, c.email AS reach']
        );

        $this->assertStringContainsString('| Name | User Email | Contact Email |', $response['metadata']['export_document_markdown']);
        $this->assertStringContainsString('Fields included: Name, User Email, Contact Email.', $response['answer']);
    }

    /** @test A column left out for having no values, and a requested field the file lacks, are named by what they hold too. */
    public function omitted_and_missing_fields_are_named_by_their_property(): void
    {
        $empty = $this->composer->generateExportDocumentResponse(
            'members with contact info',
            ['format' => 'excel'],
            [['name' => 'Julie', 'contact_info' => null], ['name' => 'Luc', 'contact_info' => '']],
            'current_result',
            ['language' => 'en', 'cypher' => self::CONTACT_INFO_CYPHER, 'requested_fields' => ['name', 'contact_info']]
        );
        $this->assertStringContainsString('No values found for: Email.', $empty['answer']);

        // The previous result is exported; the fresh query that was to ADD the email failed.
        $missing = $this->composer->generateExportDocumentResponse(
            'give me that as an excel with their contact info',
            ['format' => 'excel'],
            [['name' => 'Julie'], ['name' => 'Luc']],
            'previous_result',
            [
                'language' => 'en',
                'cypher' => 'MATCH (u:User) RETURN u.name AS name LIMIT 100',
                'requested_fields' => ['name', 'contact_info'],
                'requested_cypher' => self::CONTACT_INFO_CYPHER,
            ]
        );
        $this->assertStringContainsString('Requested fields not included: Email.', $missing['answer']);
    }

    // =========================================================================
    // p17 (A roster-excel, B resilience t3 / french t1): THE FILE SHOWS WHAT THE SCREEN SHOWED
    // =========================================================================

    private const ROLE_ROSTER_CYPHER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 100';

    private function declareRoleLegend(): void
    {
        config(['entities' => [
            'App\\Models\\TeamRole' => ['graph' => ['label' => 'TeamRole'], 'metadata' => ['common_properties' => [
                'role' => 'Role key of the member in the team (lead=Lead union manager, manager=Union manager, contact=Unit Owner or Renter)',
            ]]],
        ]]);
    }

    /**
     * @test eval p17-A "give me that as an Excel" (replayed with the trace's first rows): the
     * chat table showed "Lead union manager" / "Unit Owner or Renter" and the file held `lead` /
     * `contact` — the legend mapping ran for the screen only. The document rows, the markdown
     * the PDF renders and the cells the .xlsx writer gets carry the labels.
     */
    public function a_coded_column_is_exported_as_its_labels_like_the_chat_table(): void
    {
        $this->declareRoleLegend();

        $response = $this->composer->generateExportDocumentResponse(
            'Perfect — give me that as an Excel with their contact info.',
            ['format' => 'excel'],
            [
                ['name' => 'Julie Rousseau', 'email' => 'j.rousseau@novogestion.ca', 'role' => 'lead'],
                ['name' => 'Service Comptable', 'email' => 'service_comptable@novogestion.ca', 'role' => 'accountant'],
                ['name' => 'Fernande Martin', 'email' => 'fernandemartin21@gmail.com', 'role' => 'contact'],
            ],
            'previous_result',
            ['language' => 'en', 'complete' => true, 'total_rows' => 3, 'cypher' => self::ROLE_ROSTER_CYPHER]
        );

        $this->assertSame(
            ['Lead union manager', 'accountant', 'Unit Owner or Renter'],
            array_column($response['metadata']['export_document_rows'], 'role'),
            'a code the legend does not name keeps its value'
        );
        $this->assertStringContainsString('| Julie Rousseau | j.rousseau@novogestion.ca | Lead union manager |', $response['metadata']['export_document_markdown']);
        $this->assertStringNotContainsString('| lead |', $response['metadata']['export_document_markdown']);
        $this->assertSame(['name', 'email', 'role'], array_keys($response['metadata']['export_document_rows'][0]), 'the row keys never change');
    }

    /** @test eval p17-B french t1: a French export shows the host's French labels, as the French chat table did. */
    public function exported_labels_follow_the_answer_language(): void
    {
        $this->declareRoleLegend();
        app('translator')->addLines(['*.Lead union manager' => 'Gestionnaire en chef', '*.Unit Owner or Renter' => 'Copropriétaire ou locataire'], 'fr');

        $response = $this->composer->generateExportDocumentResponse(
            'Parfait — exporte-la en Excel.',
            ['format' => 'excel'],
            [['membre' => 'Julie Rousseau', 'rôle' => 'lead'], ['membre' => 'Fernande Martin', 'rôle' => 'contact']],
            'previous_result',
            [
                'language' => 'fr',
                'cypher' => 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
                    . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id RETURN u.name AS membre, tr.role AS rôle LIMIT 100',
            ]
        );

        $this->assertSame(['Gestionnaire en chef', 'Copropriétaire ou locataire'], array_column($response['metadata']['export_document_rows'], 'rôle'));
        $this->assertStringContainsString('| Julie Rousseau | Gestionnaire en chef |', $response['metadata']['export_document_markdown']);
    }

    /**
     * @test The recorded query of a collapsed roster returns the roles COLLECTED per member:
     * the re-executed rows carry "a; b" cells (FanOutCollapse::joinLists) — each code reads as
     * its label in the file.
     */
    public function a_fan_out_collapsed_role_cell_is_exported_as_labels(): void
    {
        $this->declareRoleLegend();
        $collapsed = \Condoedge\Ai\Services\Query\FanOutCollapse::perAnchorQuery(self::ROLE_ROSTER_CYPHER, 'u', 10);

        $response = $this->composer->generateExportDocumentResponse(
            'export that members list to excel',
            ['format' => 'excel'],
            [
                ['name' => 'Alex Mc Nicoll', 'email' => 'a@novo.test', 'role' => 'manager; contact'],
                ['name' => 'Julie Rousseau', 'email' => 'j@novo.test', 'role' => 'lead'],
            ],
            'previous_result',
            ['language' => 'en', 'cypher' => $collapsed]
        );

        $this->assertSame(
            ['Union manager; Unit Owner or Renter', 'Lead union manager'],
            array_column($response['metadata']['export_document_rows'], 'role')
        );
    }

    /** @test Without the source query (a sample-only / identity export) the values are written as they are. */
    public function without_a_source_cypher_coded_values_are_untouched(): void
    {
        $this->declareRoleLegend();

        $response = $this->composer->generateExportDocumentResponse(
            'export it',
            ['format' => 'excel'],
            [['name' => 'Julie Rousseau', 'role' => 'lead']],
            'previous_result',
            ['language' => 'en']
        );

        $this->assertSame('lead', $response['metadata']['export_document_rows'][0]['role']);
    }

    /** @test A falsy-but-real value (0, false, "0") is data, not emptiness. */
    public function a_column_holding_zero_or_false_is_kept(): void
    {
        $response = $this->composer->generateExportDocumentResponse(
            'balances',
            ['format' => 'excel'],
            [['unit' => '310', 'balance' => 0, 'flag' => false], ['unit' => '311', 'balance' => '0', 'flag' => null]],
            'current_result',
            ['language' => 'en']
        );

        $this->assertSame(['unit', 'balance', 'flag'], array_keys($response['metadata']['export_document_rows'][0]));
        $this->assertStringNotContainsString('No values found', $response['answer']);
    }

    // =========================================================================
    // p18-B french t1: THE FILE'S HEADERS ARE SAID IN THE ANSWER'S LANGUAGE
    // =========================================================================

    /** The p18 roster query: English aliases, whatever the question's language. */
    private const ROSTER_CYPHER_EN_ALIASES = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role, u.last_logged_at AS last_login LIMIT 100';

    private function hostFrenchHeaders(): void
    {
        // (Load the package's French lines first: addLines() on a locale not loaded yet
        // would mark it loaded and hide them.)
        app('translator')->get('ai.export.fields_included', [], 'fr');
        app('translator')->addLines(['*.Name' => 'Nom', '*.Email' => 'Courriel', '*.Role' => 'Rôle'], 'fr');
    }

    /**
     * @test Eval p18-B french t1 ("Parfait — exporte-la en Excel."): "Champs inclus : Name, Email,
     * Role." and English headers in the file, because the generator aliased in English. The
     * humanized header goes through the HOST's translator for the answer's locale — document
     * table, the .xlsx header row and "Fields included" alike; a header the host has no line
     * for stays as it is. Row keys never change.
     */
    public function p18_b_french_export_headers_are_said_in_the_answer_language(): void
    {
        $this->hostFrenchHeaders();

        $response = $this->composer->generateExportDocumentResponse(
            'Parfait — exporte-la en Excel.',
            ['format' => 'excel'],
            [
                ['name' => 'Julie Rousseau', 'email' => 'j.rousseau@novogestion.ca', 'role' => 'lead', 'last_login' => '2023-11-24 22:21:37'],
                ['name' => 'Service Comptable', 'email' => 'service_comptable@novogestion.ca', 'role' => 'accountant', 'last_login' => null],
            ],
            'previous_result',
            ['language' => 'fr', 'complete' => true, 'total_rows' => 2, 'cypher' => self::ROSTER_CYPHER_EN_ALIASES]
        );

        $meta = $response['metadata'];
        $this->assertStringContainsString('Champs inclus : Nom, Courriel, Rôle, Last Login.', $response['answer']);
        $this->assertStringContainsString('| Nom | Courriel | Rôle | Last Login |', $meta['export_document_markdown']);
        $this->assertSame(
            ['name' => 'Nom', 'email' => 'Courriel', 'role' => 'Rôle', 'last_login' => 'Last Login'],
            $meta['export_document_headers']
        );
        $this->assertSame(['name', 'email', 'role', 'last_login'], array_keys($meta['export_document_rows'][0]), 'row keys are never rewritten');
    }

    /** @test The fields named as left out (no value on any row / requested but absent) are headers too. */
    public function the_omitted_and_missing_field_names_are_said_in_the_answer_language(): void
    {
        $this->hostFrenchHeaders();

        $response = $this->composer->generateExportDocumentResponse(
            'exporte',
            ['format' => 'excel'],
            [['name' => 'Julie Rousseau', 'role' => null], ['name' => 'Luc Tremblay', 'role' => null]],
            'previous_result',
            ['language' => 'fr', 'requested_fields' => ['name', 'email']]
        );

        $this->assertStringContainsString('Champs inclus : Nom.', $response['answer']);
        $this->assertStringContainsString('Aucune valeur trouvée pour : Rôle.', $response['answer']);
        $this->assertStringContainsString('Champs demandés non inclus : Courriel.', $response['answer']);
    }

    /** @test An English export is headed as before: a header is already English — no UI line renames it. */
    public function an_english_export_keeps_its_headers(): void
    {
        $this->hostFrenchHeaders();
        app('translator')->get('ai.export.fields_included', [], 'en');
        app('translator')->addLines(['*.Email' => 'E-mail address'], 'en');

        $response = $this->composer->generateExportDocumentResponse(
            'export it',
            ['format' => 'excel'],
            [['name' => 'Julie Rousseau', 'email' => 'j@x.test']],
            'previous_result',
            ['language' => 'en']
        );

        $this->assertSame(['name' => 'Name', 'email' => 'Email'], $response['metadata']['export_document_headers']);
    }

    // =========================================================================
    // p18-A unit-310 → "Create a PDF of this": MONEY IN THE DOCUMENT
    // =========================================================================

    private const UNIT_310_CYPHER = "MATCH (u:Unit) WHERE u.name = '310' MATCH (u)-[:UNION]->(un:Union) WHERE un.team_id IN \$ai_team_ids "
        . "OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.status <> '1' AND i.due_amount >= 0.01 "
        . 'RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, count(i) AS open_invoices, '
        . 'round(sum(i.due_amount), 2) AS invoices_due, min(i.due_at) AS oldest_due, u.id AS unit_id';

    /** @return array<int, array<string, mixed>> */
    private function unit310Rows(): array
    {
        return [
            ['union_name' => 'SDC Carré Soho Phase 3', 'unit_name' => '310', 'balance' => 0, 'open_invoices' => 0, 'invoices_due' => 0, 'oldest_due' => null, 'unit_id' => 159],
            ['union_name' => 'SDC Les Terrasses 7250 St-Hubert', 'unit_name' => '310', 'balance' => 1211.4, 'open_invoices' => 2, 'invoices_due' => 1211.4, 'oldest_due' => null, 'unit_id' => 301],
        ];
    }

    private function declareMoney(): void
    {
        config([
            'ai.response_generation.currency_symbol' => '$',
            'entities' => [
                'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['money_properties' => ['outstanding_balance']]],
                'App\\Models\\Union' => ['graph' => ['label' => 'Union'], 'metadata' => []],
                'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['money_properties' => ['due_amount']]],
            ],
        ]);
    }

    /**
     * @test Eval p18-A "Create a PDF of this" after the unit-310 table: the chat table read
     * "$0.00", the PDF's cells a raw `0`. THE FILE SHOWS WHAT THE SCREEN SHOWED: a declared money
     * column (MoneyColumns over the source query) is written as money — currency symbol, two
     * decimals, the answer's locale — in the PDF's document and in its rows. A count stays a count.
     */
    public function p18_a_pdf_money_cells_are_formatted_as_on_screen(): void
    {
        $this->declareMoney();

        $response = $this->composer->generateExportDocumentResponse(
            'Create a PDF of this.',
            ['format' => 'pdf'],
            $this->unit310Rows(),
            'previous_result',
            ['language' => 'en', 'complete' => true, 'total_rows' => 2, 'cypher' => self::UNIT_310_CYPHER]
        );

        $markdown = $response['metadata']['export_document_markdown'];
        $this->assertStringContainsString('| SDC Carré Soho Phase 3 | 310 | $0.00 | 0 | $0.00 |', $markdown);
        $this->assertStringContainsString('| SDC Les Terrasses 7250 St-Hubert | 310 | $1,211.40 | 2 | $1,211.40 |', $markdown);

        $rows = $response['metadata']['export_document_rows'];
        $this->assertSame('$0.00', $rows[0]['balance']);
        $this->assertSame('$1,211.40', $rows[1]['invoices_due']);
        $this->assertSame(0, $rows[0]['open_invoices'], 'a count is not money');
        $this->assertSame(['union_name', 'unit_name', 'balance', 'open_invoices', 'invoices_due'], array_keys($rows[0]));
    }

    /** @test The amount follows the answer's locale, like the chat table ("1 211,40 $" in French). */
    public function pdf_money_follows_the_answer_locale(): void
    {
        $this->declareMoney();

        $response = $this->composer->generateExportDocumentResponse(
            'Fais-en un PDF.',
            ['format' => 'pdf'],
            $this->unit310Rows(),
            'previous_result',
            ['language' => 'fr', 'complete' => true, 'total_rows' => 2, 'cypher' => self::UNIT_310_CYPHER]
        );

        $this->assertMatchesRegularExpression('/^1\X211,40\X\$$/u', $response['metadata']['export_document_rows'][1]['balance']);
    }

    /**
     * @test An EXCEL export keeps its money cells NUMERIC: the .xlsx writer types a numeric cell
     * as a number (ResultSetExporter::writeTypedCell) and has no number-format channel, so a
     * "$1,211.40" string would land as text — unusable in a SUM. Nothing is formatted there.
     */
    public function excel_money_cells_stay_numeric(): void
    {
        $this->declareMoney();

        $response = $this->composer->generateExportDocumentResponse(
            'Give me that as an Excel.',
            ['format' => 'excel'],
            $this->unit310Rows(),
            'previous_result',
            ['language' => 'en', 'complete' => true, 'total_rows' => 2, 'cypher' => self::UNIT_310_CYPHER]
        );

        $rows = $response['metadata']['export_document_rows'];
        $this->assertSame(0, $rows[0]['balance']);
        $this->assertSame(1211.4, $rows[1]['invoices_due']);
        $this->assertStringContainsString('| SDC Les Terrasses 7250 St-Hubert | 310 | 1211.4 | 2 | 1211.4 |', $response['metadata']['export_document_markdown']);
    }

    /** @test Without a source query, or without declared money, nothing is formatted: the values stand. */
    public function without_declared_money_pdf_cells_are_untouched(): void
    {
        $noCypher = $this->composer->generateExportDocumentResponse('pdf', ['format' => 'pdf'], $this->unit310Rows(), 'previous_result', ['language' => 'en']);
        $this->assertSame(1211.4, $noCypher['metadata']['export_document_rows'][1]['balance']);

        config(['ai.response_generation.currency_symbol' => '$', 'entities' => []]);
        $undeclared = $this->composer->generateExportDocumentResponse(
            'pdf',
            ['format' => 'pdf'],
            $this->unit310Rows(),
            'previous_result',
            ['language' => 'en', 'cypher' => self::UNIT_310_CYPHER]
        );
        $this->assertSame(1211.4, $undeclared['metadata']['export_document_rows'][1]['balance']);
    }

    // =========================================================================
    // THE FILE NAMES A PERSON AS THE SCREEN DOES (eval p19-A board: one name column)
    // =========================================================================

    /** The p19-A board query. */
    private const BOARD_CYPHER = 'MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
        . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id ORDER BY un.name';

    /** @return array<int, array<string, mixed>> the first rows of the p19-A board trace */
    private function boardRows(): array
    {
        return [
            ['union_name' => '825 de Bruxelles', 'c.first_name' => 'Amélie', 'c.last_name' => 'Quirion', 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 779],
            ['union_name' => '825 de Bruxelles', 'c.first_name' => 'Mauricio', 'c.last_name' => 'Ruiz', 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 1436],
        ];
    }

    /**
     * @test The chat table shows ONE "Name" column for a contact (`metadata.name_properties`, both
     * parts projected from one variable); the file's rows, its header row, its document table and
     * "Fields included" follow the same merge — and the name parts the question asked for are in
     * the file, so they are not reported as missing.
     */
    public function p19_a_split_name_is_one_column_in_the_file_as_on_screen(): void
    {
        config(['entities' => [
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => ['name_properties' => ['first_name', 'last_name']]],
        ]]);

        foreach (['excel', 'pdf'] as $format) {
            $response = $this->composer->generateExportDocumentResponse(
                'Give me that as a file.',
                ['format' => $format],
                $this->boardRows(),
                'previous_result',
                [
                    'language' => 'en', 'complete' => true, 'total_rows' => 2, 'cypher' => self::BOARD_CYPHER,
                    'requested_fields' => ['union_name', 'c.first_name', 'c.last_name', 'cur.year'],
                    'requested_cypher' => self::BOARD_CYPHER,
                ]
            );
            $meta = $response['metadata'];

            $this->assertSame(
                [
                    ['union_name' => '825 de Bruxelles', 'c.name' => 'Amélie Quirion', 'cur.year' => 2022],
                    ['union_name' => '825 de Bruxelles', 'c.name' => 'Mauricio Ruiz', 'cur.year' => 2022],
                ],
                $meta['export_document_rows'],
                $format
            );
            $this->assertSame(['union_name' => 'Union Name', 'c.name' => 'Name', 'cur.year' => 'Year'], $meta['export_document_headers']);
            $this->assertStringContainsString("| Union Name | Name | Year |\n| --- | --- | --- |\n| 825 de Bruxelles | Amélie Quirion | 2022 |", $meta['export_document_markdown']);
            $this->assertStringContainsString('Fields included: Union Name, Name, Year.', $response['answer']);
            $this->assertStringNotContainsString('First Name', $response['answer']);
            $this->assertStringNotContainsString('Requested fields not included', $response['answer']);
        }
    }

    /** @test Without the declaration, or with one part only, the file keeps the query's columns. */
    public function without_a_whole_declared_name_the_file_keeps_its_columns(): void
    {
        config(['entities' => []]);
        $undeclared = $this->composer->generateExportDocumentResponse(
            'file',
            ['format' => 'excel'],
            $this->boardRows(),
            'previous_result',
            ['language' => 'en', 'cypher' => self::BOARD_CYPHER]
        );

        $this->assertSame(['union_name', 'c.first_name', 'c.last_name', 'cur.year'], array_keys($undeclared['metadata']['export_document_rows'][0]));
    }
}
