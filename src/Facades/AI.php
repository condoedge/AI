<?php

declare(strict_types=1);

namespace Condoedge\Ai\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * AI Facade - Laravel Facade for AI System
 *
 * This facade provides static access to the AiManager service while properly
 * leveraging Laravel's service container. This is the recommended way to access
 * AI functionality throughout your application.
 *
 * **Basic Usage:**
 * ```php
 * use Condoedge\Ai\Facades\AI;
 *
 * // Data Ingestion
 * AI::ingest($customer);
 * AI::ingestBatch([$customer1, $customer2]);
 * AI::sync($customer);
 * AI::remove($customer);
 *
 * // Context Retrieval (RAG)
 * $context = AI::retrieveContext("Show teams with most active members");
 * $similar = AI::searchSimilar("Show all teams");
 * $schema = AI::getSchema();
 *
 * // Embeddings
 * $vector = AI::embed("Some text to embed");
 * $vectors = AI::embedBatch(["text 1", "text 2"]);
 *
 * // LLM Chat
 * $response = AI::chat("What is 2+2?");
 * $data = AI::chatJson("Generate JSON with name and age");
 * $answer = AI::complete("Translate 'hello' to French", "You are a translator");
 *
 * // Query Generation
 * $result = AI::generateQuery("Show all customers with orders > 100");
 * $validation = AI::validateQuery("MATCH (n:Customer) RETURN n LIMIT 10");
 * $safe = AI::sanitizeQuery("MATCH (n) DELETE n"); // Removes DELETE
 * $fullPipeline = AI::askQuestion("How many teams are active?");
 *
 * // Query Execution
 * $result = AI::executeQuery("MATCH (n:Customer) RETURN n LIMIT 10");
 * $count = AI::executeCount("MATCH (n:Customer) RETURN n");
 * $paginated = AI::executePaginated("MATCH (n:Customer) RETURN n", page: 1, perPage: 20);
 * $plan = AI::explainQuery("MATCH (n:Customer) RETURN n");
 *
 * // Full Pipeline (Question → Query → Execute)
 * $answer = AI::ask("How many customers do we have?");
 *
 * // Response Generation
 * $response = AI::generateResponse("How many teams?", $queryResult, "MATCH (n:Team) RETURN count(n)");
 * $insights = AI::extractInsights($queryResult);
 * $charts = AI::suggestVisualizations($queryResult, "MATCH (n) RETURN n");
 *
 * // Complete Pipeline (Question → Answer with insights)
 * $fullAnswer = AI::answerQuestion("Which customers have the most orders?");
 * // Returns: ['question', 'answer', 'insights', 'visualizations', 'cypher', 'data', 'stats']
 * ```
 *
 * **Testing:**
 * ```php
 * use Condoedge\Ai\Facades\AI;
 *
 * // Mock facade in tests
 * AI::shouldReceive('ingest')
 *     ->once()
 *     ->with($customer)
 *     ->andReturn([
 *         'graph_stored' => true,
 *         'vector_stored' => true,
 *         'relationships_created' => 2,
 *         'errors' => []
 *     ]);
 *
 * // Your test code
 * $result = $myService->processCustomer($customer);
 * ```
 *
 * **Architecture:**
 * - Facade proxies to AiManager registered in service container
 * - AiManager uses proper dependency injection
 * - All services are singletons managed by Laravel
 * - Fully testable using Laravel's facade mocking
 *
 * @method static array ingest(\Condoedge\Ai\Domain\Contracts\Nodeable $entity)
 * @method static array ingestBatch(array $entities)
 * @method static bool remove(\Condoedge\Ai\Domain\Contracts\Nodeable $entity)
 * @method static array sync(\Condoedge\Ai\Domain\Contracts\Nodeable $entity)
 * @method static array retrieveContext(string $question, array $options = [])
 * @method static array searchSimilar(string $question, array $options = [])
 * @method static array getSchema()
 * @method static array getExampleEntities(array $labels, int $limit = 3)
 * @method static array embed(string $text)
 * @method static array embedBatch(array $texts)
 * @method static int getEmbeddingDimensions()
 * @method static string getEmbeddingModel()
 * @method static string chat(string|array $input, array $options = [])
 * @method static object|array chatJson(string|array $input, array $options = [])
 * @method static string complete(string $prompt, string|null $systemPrompt = null, array $options = [])
 * @method static void stream(array $messages, callable $callback, array $options = [])
 * @method static string getLlmModel()
 * @method static string getLlmProvider()
 * @method static int getLlmMaxTokens()
 * @method static int countTokens(string $text)
 * @method static array generateQuery(string $question, array $context = [], array $options = [])
 * @method static array validateQuery(string $cypherQuery, array $options = [])
 * @method static string sanitizeQuery(string $cypherQuery)
 * @method static array getQueryTemplates()
 * @method static string|null detectQueryTemplate(string $question)
 * @method static array askQuestion(string $question, array $options = [])
 * @method static array executeQuery(string $cypherQuery, array $parameters = [], array $options = [])
 * @method static int executeCount(string $cypherQuery, array $parameters = [], array $options = [])
 * @method static array executePaginated(string $cypherQuery, int $page = 1, int $perPage = 20, array $parameters = [], array $options = [])
 * @method static array explainQuery(string $cypherQuery, array $parameters = [])
 * @method static bool testQuery(string $cypherQuery)
 * @method static array ask(string $question, array $options = [])
 * @method static array generateResponse(string $originalQuestion, array $queryResult, string $cypherQuery, array $options = [])
 * @method static array extractInsights(array $queryResult)
 * @method static array suggestVisualizations(array $queryResult, string $cypherQuery)
 * @method static array answerQuestion(string $question, array $options = [])
 *
 * @see \Condoedge\Ai\Services\AiManager
 * @package Condoedge\Ai\Facades
 */
class AI extends Facade
{
    /**
     * Get the registered name of the component
     *
     * This returns the key used in the service container to resolve
     * the underlying AiManager instance.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'ai';
    }
}
