<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Stubs;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;

/**
 * Recording fake of the EXISTING QueryExecutor contract for GraphQueryTool tests. It captures
 * the query + options the tool hands over and returns a scripted QueryExecutionResult (or
 * throws). It does NOT re-implement tenant scoping — that lives in the real QueryExecutor and is
 * proven by its own suite; here we only assert the tool reuses the contract correctly.
 */
final class RecordingQueryExecutor implements QueryExecutorInterface
{
    public ?string $lastQuery = null;

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public ?\Throwable $throw = null;

    public int $calls = 0;

    public function __construct(private ?QueryExecutionResult $result = null)
    {
    }

    public function execute(?string $cypherQuery, array $parameters = [], array $options = []): QueryExecutionResult
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->calls++;
        $this->lastQuery = $cypherQuery;
        $this->lastOptions = $options;

        return $this->result ?? new QueryExecutionResult(success: true);
    }

    public function executeCount(?string $cypherQuery, array $parameters = [], array $options = []): int
    {
        return 0;
    }

    public function executePaginated(?string $cypherQuery, int $page = 1, int $perPage = 20, array $parameters = [], array $options = []): array
    {
        return [];
    }

    public function explain(?string $cypherQuery, array $parameters = []): array
    {
        return [];
    }

    public function test(?string $cypherQuery): bool
    {
        return true;
    }
}
