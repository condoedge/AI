<?php

declare(strict_types=1);

namespace Condoedge\Ai\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * AI Facade — static access to the AiManager pipeline orchestrator.
 *
 * @method static array ingest(\Condoedge\Ai\Domain\Contracts\Nodeable $entity)
 * @method static array ingestBatch(array $entities)
 * @method static bool remove(\Condoedge\Ai\Domain\Contracts\Nodeable $entity)
 * @method static array sync(\Condoedge\Ai\Domain\Contracts\Nodeable $entity)
 * @method static array syncRelationships(array $entities)
 * @method static \Condoedge\Ai\DTOs\PipelineContext retrieveContext(string $question, array $options = [])
 * @method static array searchSimilar(string $question, array $options = [])
 * @method static array getSchema()
 * @method static array getExampleEntities(array $labels, int $limit = 3)
 * @method static array storeQuery(string $question, ?string $cypherQuery, array $metadata = [], string $collection = 'questions')
 * @method static array embed(string $text)
 * @method static string chat(string|array $input, array $options = [])
 * @method static string complete(string $prompt, ?string $systemPrompt = null, array $options = [])
 * @method static array generateQuery(string $question, ?\Condoedge\Ai\DTOs\PipelineContext $context = null, array $options = [])
 * @method static array validateQuery(?string $cypherQuery, array $options = [])
 * @method static array executeQuery(?string $cypherQuery, array $parameters = [], array $options = [])
 * @method static array generateResponse(\Condoedge\Ai\DTOs\PipelineContext $context, array $options = [])
 * @method static array extractInsights(array $queryResult)
 * @method static array suggestVisualizations(array $queryResult, ?string $cypherQuery)
 * @method static array answerQuestion(string $question, array $options = [])
 * @method static array retrieveFileContext(string $question, mixed $user)
 * @method static string deparameterizeCypher(string $cypher)
 *
 * @see \Condoedge\Ai\Services\AiManager
 * @package Condoedge\Ai\Facades
 */
class AI extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ai';
    }
}
