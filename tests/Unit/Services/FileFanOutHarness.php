<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Mockery;

/**
 * Drives the real document fan-out (AiManager::fanOutFileContext) over scripted
 * per-angle rankings: each angle "searches" through $this->rankings and emits,
 * like the real provider, its best DISTINCT passages of every file (up to the
 * per-file budget), each reference carrying the angle's full ranking and the
 * passage's heading path when $this->headings names one.
 */
trait FileFanOutHarness
{
    /** @var list<string> */
    private array $searched = [];

    /** @var callable(string): array<string, array<int, float>> One angle's file id => chunk_index => score. */
    private $rankings;

    /** @var array<int, list<string>> chunk_index => heading path. */
    private array $headings = [];

    /** @var array<string, mixed> */
    private array $trace = [];

    /**
     * Emit, like the real provider asked for PASSAGE_CHUNKS, the chunks every snippet is
     * made of: the passage's ±context_window neighbours, "chunk N" each.
     */
    private bool $passageChunks = false;

    /**
     * @param array<string, mixed> $conversation The conversation memory of the turn.
     * @return list<array> The final references, in prompt order.
     */
    private function fanOut(string $question, QueryUnderstandingResult $qu, array $conversation = []): array
    {
        $provider = Mockery::mock(FileContextProvider::class);
        $provider->shouldReceive('getFileContext')->andReturnUsing(function ($query, $user, $options = []) {
            $named = array_map('strval', (array) ($options['article_numbers'] ?? []));
            if ($named === []) {
                $this->searched[] = $query;
            }
            $window = max(0, (int) config('ai.file_context.context_window', 2));
            $files = [];
            foreach (($this->rankings)($query) as $fileId => $scores) {
                arsort($scores);
                $emitted = [];
                foreach ($scores as $index => $score) {
                    if ($named !== [] && !in_array((string) $index, $named, true)) {
                        continue; // the exact-article angle returns only the named article's passage
                    }
                    if (count($emitted) >= (int) ($options['chunks_per_file'] ?? 1)) {
                        break;
                    }
                    foreach ($emitted as $taken) {
                        if ($named === [] && abs($index - $taken) <= $window) {
                            continue 2;
                        }
                    }
                    $emitted[] = $index;
                    $parts = [];
                    if ($this->passageChunks && ($options[FileContextProvider::PASSAGE_CHUNKS] ?? false) === true) {
                        foreach (range(max(0, $index - $window), $index + $window) as $neighbour) {
                            $parts[$neighbour] = "chunk {$neighbour}";
                        }
                    }
                    $files[] = ($parts !== [] ? ['snippet_chunks' => $parts] : []) + [
                        'file_id' => $fileId,
                        'filename' => basename((string) $fileId),
                        'snippet' => $parts !== [] ? implode(FileContextProvider::SNIPPET_SEPARATOR, $parts) : "chunk {$index}",
                        'relevance' => $score,
                        'chunk_index' => $index,
                        'source' => 'physical',
                        'is_preamble' => false,
                    ] + (isset($this->headings[$index]) ? [
                        'heading' => implode(' › ', $this->headings[$index]),
                        'heading_path' => $this->headings[$index],
                    ] : []) + ($named !== [] ? ['article_match' => true] : ['chunk_scores' => $scores]);
                }
            }
            usort($files, static fn (array $a, array $b) => $b['relevance'] <=> $a['relevance']);

            return ['relevant_files' => $files, 'file_count' => count($files)];
        });

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            Mockery::mock(ContextRetrieverInterface::class),
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            Mockery::mock(QueryGeneratorInterface::class),
            Mockery::mock(QueryExecutorInterface::class),
            Mockery::mock(ResponseGeneratorInterface::class),
            Mockery::mock(VectorStoreInterface::class),
            $provider,
        );

        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = $qu;
        $ctx->conversationContext = $conversation;
        $trace = [];
        $method = new \ReflectionMethod($manager, 'fanOutFileContext');
        $method->setAccessible(true);
        $ctx = $method->invokeArgs($manager, [$ctx, $question, ['user' => (object) ['id' => 13]], &$trace]);
        $this->trace = $trace;

        return $ctx->fileContext['relevant_files'] ?? [];
    }
}
