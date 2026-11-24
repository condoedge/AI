<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Skills;

use Condoedge\Ai\Contracts\AudienceResolverInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\DTOs\ResolvedAudience;
use Condoedge\Ai\Services\ResponseSections\SkillSection;
use Condoedge\Ai\Services\Skills\SkillLibrary;
use Condoedge\Ai\Services\Skills\SkillSelector;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;

/**
 * A host skill (ai.skills): a folder read from disk, whose references are picked by
 * embedding similarity among those the asker may read, in the question's language,
 * and printed whole for the answering call.
 */
class SkillSelectorTest extends TestCase
{
    private string $folder;

    public function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir() . '/ai-skill-' . bin2hex(random_bytes(4));
        mkdir($this->folder . '/references/staff', 0777, true);
        file_put_contents($this->folder . '/SKILL.md', "---\nname: bookkeeping\ndescription: Books.\ndisclaimer:\n  en: Not an accountant.\n---\nName the page where it is done.\n");
        $this->reference('funds', "title: Funds\ndescription: What each fund pays for.\nquestions: [Can the reserve pay for snow?]\npages: [budget]\nlanguage: en");
        $this->reference('fonds', "title: Fonds\ndescription: Ce que chaque fonds paie.\nlanguage: fr");
        $this->reference('staff/closing', "title: Year-end\ndescription: Closing the year.\naudience: [staff]\npages: [closing]\nlanguage: en");
        $this->reference('untitled', 'title: No description');

        SkillLibrary::forget();
        config(['ai.skills.paths' => [$this->folder], 'ai.skills.min_similarity' => 0.4, 'ai.skills.margin' => 0.5]);
    }

    public function tearDown(): void
    {
        SkillLibrary::forget();
        array_map('unlink', glob($this->folder . '/references/staff/*') ?: []);
        array_map('unlink', glob($this->folder . '/references/*.md') ?: []);
        rmdir($this->folder . '/references/staff');
        rmdir($this->folder . '/references');
        unlink($this->folder . '/SKILL.md');
        rmdir($this->folder);

        parent::tearDown();
    }

    private function reference(string $id, string $frontmatter): void
    {
        file_put_contents("{$this->folder}/references/{$id}.md", "---\n{$frontmatter}\n---\nBody of {$id}.\n");
    }

    private function selector(array $question): SkillSelector
    {
        $vectors = [
            'Funds — What each fund pays for. Can the reserve pay for snow?' => [1.0, 0.0, 0.0],
            'Year-end — Closing the year.' => [0.6, 0.8, 0.0],
            'Fonds — Ce que chaque fonds paie.' => [0.0, 1.0, 0.0],
            'q' => $question,
        ];

        return new SkillSelector(new class($vectors) implements EmbeddingProviderInterface {
            public function __construct(private array $vectors) {}

            public function embed(string $text): array
            {
                return $this->vectors[$text] ?? [0.0, 0.0, 1.0];
            }

            public function embedBatch(array $texts): array
            {
                return array_map(fn ($t) => $this->embed($t), $texts);
            }

            public function getDimensions(): int
            {
                return 3;
            }

            public function getModel(): string
            {
                return 'fake-' . bin2hex(random_bytes(4));
            }

            public function getMaxLength(): int
            {
                return 8000;
            }
        }, new AiLogger());
    }

    private function audience(string $key): void
    {
        $resolver = new class implements AudienceResolverInterface {
            public static string $key = '';

            public function resolve(mixed $user): ?ResolvedAudience
            {
                return new ResolvedAudience(self::$key);
            }
        };
        $resolver::$key = $key;
        config(['ai.audiences.resolver' => $resolver::class, 'ai.audiences.map' => [
            'staff' => ['documents' => ['staff', 'owner']],
            'owner' => ['documents' => ['owner']],
        ]]);
    }

    public function test_the_library_reads_the_folder_and_ignores_a_reference_without_description(): void
    {
        $skill = SkillLibrary::all()['bookkeeping'];

        $this->assertSame('Name the page where it is done.', $skill['instructions']);
        $this->assertSame(['en' => 'Not an accountant.'], $skill['disclaimer']);
        $this->assertSame(['fonds', 'funds', 'staff/closing'], array_keys($skill['references']));
        $this->assertSame(['budget'], $skill['references']['funds']['pages']);
        $this->assertSame('Body of funds.', $skill['references']['funds']['content']);
    }

    public function test_the_references_within_the_margin_are_selected_best_first_in_the_question_language(): void
    {
        $skill = $this->selector([0.9, 0.3, 0.3])->select('q', new QueryUnderstandingResult(language: 'en'), null);

        $this->assertSame('bookkeeping', $skill['name']);
        $this->assertSame(['en' => 'Not an accountant.'], $skill['disclaimer']);
        $this->assertSame(['funds', 'staff/closing'], array_column($skill['references'], 'id'));
    }

    public function test_a_reference_of_another_audience_is_never_selected(): void
    {
        $this->audience('owner');

        $skill = $this->selector([0.6, 0.8, 0.0])->select('q', new QueryUnderstandingResult(language: 'en'), new \stdClass());

        $this->assertSame(['funds'], array_column($skill['references'], 'id'));
    }

    public function test_no_skill_is_selected_below_the_floor(): void
    {
        $this->assertSame([], $this->selector([0.1, 0.1, 0.99])->select('q', new QueryUnderstandingResult(language: 'en'), null));
    }

    public function test_at_most_max_references_are_loaded(): void
    {
        config(['ai.skills.max_references' => 1]);

        $skill = $this->selector([0.9, 0.3, 0.3])->select('q', new QueryUnderstandingResult(language: 'en'), null);

        $this->assertSame(['funds'], array_column($skill['references'], 'id'));
    }

    public function test_the_section_prints_the_instructions_and_each_reference_whole(): void
    {
        $context = new PipelineContext();
        $section = new SkillSection();
        $this->assertFalse($section->shouldInclude($context));

        $context->skill = $this->selector([0.9, 0.3, 0.3])->select('q', new QueryUnderstandingResult(language: 'en'), null);
        $prompt = $section->format($context);

        $this->assertTrue($section->shouldInclude($context));
        $this->assertStringContainsString('Name the page where it is done.', $prompt);
        $this->assertStringContainsString("--- Funds ---\n\nBody of funds.", $prompt);
        $this->assertStringContainsString('--- Year-end ---', $prompt);
    }
}
