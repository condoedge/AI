<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Services\Extractors\MarkdownExtractor;
use Condoedge\Ai\Services\Extractors\TextExtractor;
use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Services\SemanticChunker;
use Condoedge\Ai\Tests\Fixtures\InMemoryMapChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Mockery;

/**
 * A help article's frontmatter becomes its title and payload metadata; it is neither
 * stored nor embedded as text. A document without frontmatter is processed as before.
 */
class FileProcessorFrontmatterTest extends TestCase
{
    private const FILE = 'physical:aide/acheteur/achat-adhesion.md';

    private string $dir;

    private InMemoryMapChunkStore $store;

    /** @var list<string> */
    private array $embedded = [];

    public function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        Bus::fake();
        $this->dir = sys_get_temp_dir() . '/frontmatter_' . uniqid();
        mkdir($this->dir);
        $this->store = new InMemoryMapChunkStore();
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        Mockery::close();
        parent::tearDown();
    }

    private function process(string $markdown): array
    {
        file_put_contents($this->dir . '/achat-adhesion.md', $markdown);
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->andReturnUsing(function (array $texts) {
            $this->embedded = [...$this->embedded, ...$texts];

            return array_map(fn () => [0.1, 0.2], $texts);
        });
        $registry = new FileExtractorRegistry();
        $registry->registerMany([new TextExtractor(), new MarkdownExtractor()]);

        $result = (new FileProcessor($registry, new SemanticChunker(), $embedder, $this->store))->processFile(
            (object) ['id' => self::FILE, 'name' => 'achat-adhesion.md', 'path' => $this->dir . '/achat-adhesion.md'],
            ['collection' => 'documentation_chunks', 'min_chunk_chars' => 20]
        );
        $this->assertTrue($result->success, (string) $result->error);

        return $this->store->getFileChunks(self::FILE, 'documentation_chunks');
    }

    private const BODY = "# Payer une adhésion\n\nDans une campagne d'adhésion, vous payez une cotisation pour devenir membre.\n\n"
        . "## Étape par étape\n\n1. Cliquez sur le bouton principal de la campagne.\n2. Payez sur la page de paiement sécurisée.";

    private function article(string $title = 'Payer une adhésion et devenir membre'): string
    {
        return "---\ntitre: {$title}\npublic: acheteur\npages: [campaign.public, payment.success]\n"
            . "chemin: Page de la campagne > Je contribue!\nmots_cles: [adhésion, membre]\nvoir_aussi: [achat-payer-sa-commande]\n---\n\n" . self::BODY;
    }

    private function useCoolectoKeys(): void
    {
        config(['ai.file_context.frontmatter.keys' => ['title' => 'titre', 'audience' => 'public', 'breadcrumb' => 'chemin', 'keywords' => 'mots_cles', 'related' => 'voir_aussi']]);
    }

    /** @test */
    public function the_frontmatter_is_metadata_not_text(): void
    {
        $this->useCoolectoKeys();
        $chunks = $this->process($this->article());

        foreach ($chunks as $chunk) {
            $this->assertStringNotContainsString('titre:', $chunk->content);
            $this->assertStringNotContainsString('voir_aussi', $chunk->content);
            $this->assertSame('Payer une adhésion et devenir membre', $chunk->metadata['document_title']);
            $this->assertSame(['acheteur'], $chunk->metadata['audience']);
            $this->assertSame(['campaign.public', 'payment.success'], $chunk->metadata['pages']);
            $this->assertSame('Page de la campagne > Je contribue!', $chunk->metadata['breadcrumb']);
            $this->assertSame(['adhésion', 'membre'], $chunk->metadata['keywords']);
            $this->assertSame(['achat-payer-sa-commande'], $chunk->metadata['related']);
            $this->assertSame('achat-adhesion', $chunk->metadata['slug']);
        }
        $this->assertSame(0, $chunks[0]->startPosition, 'positions are in the body the chunks come from');
        foreach ($this->embedded as $text) {
            $this->assertStringStartsWith("Page de la campagne > Je contribue!\n", $text, 'the breadcrumb leads the embedded text');
            $this->assertStringNotContainsString('mots_cles', $text);
            $this->assertStringNotContainsString('adhésion, membre', $text, 'keywords are not embedded by default');
        }
    }

    /** @test */
    public function keywords_are_embedded_only_when_the_host_turns_it_on(): void
    {
        $this->useCoolectoKeys();
        config(['ai.file_context.frontmatter.embed_keywords' => true]);
        $this->process($this->article());

        $this->assertStringStartsWith("Page de la campagne > Je contribue!\nadhésion, membre\n", $this->embedded[0]);
    }

    /** @test */
    public function a_document_without_frontmatter_is_processed_exactly_as_before(): void
    {
        $chunks = $this->process(self::BODY);
        $plainEmbedded = $this->embedded;

        $this->assertSame('Payer une adhésion', $chunks[0]->metadata['document_title']);
        foreach ($chunks as $chunk) {
            $this->assertSame(
                ['heading_path', 'section_title', 'article_no', 'article_range', 'articles', 'is_preamble', 'page', 'document_title'],
                array_keys($chunk->metadata)
            );
        }
        $this->assertStringStartsWith("Payer une adhésion:\n", $plainEmbedded[0]);
    }
}
