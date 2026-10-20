<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\Retrieval\DocumentProbeWriter;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The focused document-search probe call (eval p15 decision): ONE small LLM call,
 * made only when document search runs, that rewrites the question into probes in
 * each corpus language and in the documents' own register. It replaces the two
 * probe keys the overloaded Query-Understanding prompt used to carry.
 *
 * White-label: the register TEXT is host configuration; the package prompt holds
 * no domain vocabulary of its own (a deliberately non-host register is used here).
 */
class DocumentProbeWriterTest extends TestCase
{
    private const REGISTER = 'aviation maintenance regulation terminology '
        . '(airworthiness directive, minimum equipment list, line-replaceable unit)';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('logging.default', 'null');
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function writer(LlmProviderInterface $llm, array $config = []): DocumentProbeWriter
    {
        return new DocumentProbeWriter($llm, $config + [
            'corpus_languages' => ['fr', 'en'],
            'corpus_register' => self::REGISTER,
        ]);
    }

    /** @test */
    public function it_returns_the_probes_of_each_corpus_language(): void
    {
        $llm = new ScriptedUsageLlm(json: [
            // The reply's scratch field (everyday expression => documents' term) is not a probe.
            'terms' => ['swap out' => 'replacement', 'broken part' => 'line-replaceable unit'],
            'fr_1' => 'remplacement d\'une unité remplaçable en escale',
            'fr_2' => 'conditions de remise en service',
            'en_1' => 'replacement of a line-replaceable unit',
            'en_2' => 'conditions for return to service',
        ]);

        $probes = $this->writer($llm)->write('Can I just swap out this broken part myself?');

        $this->assertSame([
            'fr' => ['remplacement d\'une unité remplaçable en escale', 'conditions de remise en service'],
            'en' => ['replacement of a line-replaceable unit', 'conditions for return to service'],
        ], $probes);
        $this->assertCount(1, $llm->calls, 'exactly ONE LLM call');
    }

    /** @test */
    public function it_keeps_at_most_two_distinct_non_blank_probes_per_language(): void
    {
        $llm = new ScriptedUsageLlm(json: [
            'fr_1' => '  premier  ', 'fr_2' => 'premier', 'fr_3' => 'troisième',
            'en_1' => 42, 'en_2' => 'second', 'en_3' => ['nested'],
            'es_1' => '',
        ]);

        $probes = $this->writer($llm, ['corpus_languages' => ['fr', 'en', 'es']])->write('some question');

        // fr: the duplicate is dropped and nothing beyond the 2 requested keys is read;
        // en: the non-string is skipped; es: blank, so the language is absent.
        $this->assertSame(['fr' => ['premier'], 'en' => ['second']], $probes);
    }

    /** @test */
    public function it_ignores_languages_that_were_not_requested_and_tolerates_a_grouped_reply(): void
    {
        // A model that groups a language's probes under its code is still understood.
        $llm = new ScriptedUsageLlm(json: [
            'de_1' => 'unverlangt',
            'de' => ['unverlangt'],
            'en' => ['grouped one', 'grouped two', 'grouped three'],
            'fr' => 'a single probe given as a string',
        ]);

        $probes = $this->writer($llm)->write('some question');

        $this->assertSame(
            ['fr' => ['a single probe given as a string'], 'en' => ['grouped one', 'grouped two']],
            $probes
        );
    }

    /** @test */
    public function a_probe_that_only_copies_the_question_is_dropped(): void
    {
        // Eval p15: the same-language rendering came back as a verbatim copy — an
        // angle that costs a search and adds nothing over the question itself.
        $llm = new ScriptedUsageLlm(json: [
            'en_1' => '  can I just swap out this broken part myself ',
            'en_2' => 'replacement of a line-replaceable unit',
            'fr_1' => 'Standalone restatement of the question.',
        ]);

        $probes = $this->writer($llm)->write(
            'Can I just swap out this broken part myself?',
            'standalone restatement of the question'
        );

        $this->assertSame(['en' => ['replacement of a line-replaceable unit']], $probes);
    }

    /** @test */
    public function it_makes_no_call_when_neither_languages_nor_a_register_are_configured(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldNotReceive('chatJson');

        $writer = new DocumentProbeWriter($llm, ['corpus_languages' => [], 'corpus_register' => '   ']);

        $this->assertFalse($writer->isConfigured());
        $this->assertSame([], $writer->write('some question'));
    }

    /** @test */
    public function it_makes_no_call_for_a_blank_question(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldNotReceive('chatJson');

        $this->assertSame([], $this->writer($llm)->write('   '));
    }

    /** @test */
    public function a_failing_call_yields_no_probes_and_is_reported_not_thrown(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andThrow(new \RuntimeException('provider timeout'));
        $observer = new RecordingPipelineObserver();

        $probes = $this->writer($llm)->write('some question', '', '', $observer);

        $this->assertSame([], $probes);
        $this->assertSame(
            [['stage' => 'search_probes', 'class' => \RuntimeException::class, 'message' => 'provider timeout']],
            $observer->errors()
        );
    }

    /** @test */
    public function a_malformed_reply_yields_no_probes(): void
    {
        foreach ([[], ['fr_1' => 42, 'en_1' => null], ['fr' => 42], ['probes' => ['fr_1' => 'nested, not read']], ['other' => []]] as $malformed) {
            $probes = $this->writer(new ScriptedUsageLlm(json: $malformed))->write('some question');

            $this->assertSame([], $probes, 'malformed value must degrade to no probes');
        }
    }

    /** @test */
    public function it_reports_its_verbatim_messages_and_usage_under_its_own_stage(): void
    {
        $llm = new ScriptedUsageLlm(json: ['en_1' => 'probe']);
        $observer = new RecordingPipelineObserver();

        $this->writer($llm)->write('some question', '', 'en', $observer);

        $this->assertSame([$llm->calls[0]], $observer->prompts('search_probes'));
        $this->assertSame([['stage' => 'search_probes', 'usage' => ScriptedUsageLlm::USAGE]], $observer->usages());
    }

    /** @test */
    public function the_prompt_carries_the_languages_the_host_register_and_both_forms_of_the_question(): void
    {
        $llm = new ScriptedUsageLlm(json: ['en_1' => 'probe']);

        $this->writer($llm)->write(
            'tell me more about that duty',
            'What does the operator have to do before dispatch with an inoperative component?',
            'en'
        );

        [$system, $user] = [$llm->calls[0][0]['content'], $llm->calls[0][1]['content']];
        $this->assertSame('system', $llm->calls[0][0]['role']);
        $this->assertSame('user', $llm->calls[0][1]['role']);
        $this->assertStringContainsString('["fr","en"]', $system);
        // Every requested key is spelled out, in the reply form AND in the request itself:
        // asked for "each listed language", the small model answered in one language only.
        foreach (['fr_1', 'fr_2', 'en_1', 'en_2'] as $key) {
            $this->assertStringContainsString('"' . $key . '"', $system);
            $this->assertStringContainsString($key, $user);
        }
        $this->assertStringContainsString('(written in "en")', $user);
        // The register description is the HOST's text, passed through verbatim.
        $this->assertStringContainsString(self::REGISTER, $system);
        $this->assertStringContainsString('tell me more about that duty', $user);
        $this->assertStringContainsString('What does the operator have to do before dispatch', $user);
    }

    /** @test */
    public function the_package_prompt_has_no_domain_vocabulary_of_its_own(): void
    {
        $llm = new ScriptedUsageLlm(json: ['en_1' => 'probe']);

        $this->writer($llm)->write('some question', '', 'en');

        // Everything but the host register and the question is package text: it must
        // describe the task structurally (white-label rule).
        $package = str_replace(self::REGISTER, '', $llm->calls[0][0]['content']);
        foreach (['co-owner', 'condo', 'syndicate', 'Quebec', 'civil', 'legal', 'airworthiness', 'tenant', 'lease'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $package);
        }
    }

    /** @test */
    public function a_probe_must_never_be_the_name_of_a_document(): void
    {
        // Phase 7.1b: a corpus/document NAME as a probe matches the title chunk every
        // passage shares instead of the passage that answers. Structural instruction.
        $llm = new ScriptedUsageLlm(json: ['en_1' => 'probe']);

        $this->writer($llm)->write('some question');

        $this->assertStringContainsString(
            'never name a law, code, regulation, document or title',
            $llm->calls[0][0]['content']
        );
    }

    /** @test */
    public function the_call_is_small_deterministic_json(): void
    {
        $options = null;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages, $opts) use (&$options) {
            $options = $opts;

            return ['en_1' => 'probe'];
        });

        $this->writer($llm)->write('some question');

        $this->assertSame(0.0, $options['temperature']);
        $this->assertLessThanOrEqual(400, $options['max_tokens']);
    }

    /** @test */
    public function with_only_a_register_the_probes_are_written_in_the_question_language(): void
    {
        $llm = new ScriptedUsageLlm(json: ['es_1' => 'sustitución de una unidad reemplazable en línea']);
        $writer = new DocumentProbeWriter($llm, ['corpus_languages' => [], 'corpus_register' => self::REGISTER]);

        $this->assertTrue($writer->isConfigured());
        $probes = $writer->write('¿Puedo cambiar esta pieza yo mismo?', '', 'es');

        $this->assertSame(['es' => ['sustitución de una unidad reemplazable en línea']], $probes);
        $this->assertStringContainsString('"es_1"', $llm->calls[0][0]['content']);
    }

    /** @test */
    public function with_only_languages_the_prompt_has_no_register_line(): void
    {
        $llm = new ScriptedUsageLlm(json: ['fr_1' => 'une sonde']);
        $writer = new DocumentProbeWriter($llm, ['corpus_languages' => ['fr'], 'corpus_register' => '']);

        $this->assertSame(['fr' => ['une sonde']], $writer->write('some question', '', 'en'));
        $this->assertStringNotContainsString('airworthiness', $llm->calls[0][0]['content']);
    }
}
