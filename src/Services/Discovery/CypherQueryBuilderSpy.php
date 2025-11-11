<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Closure;

/**
 * CypherQueryBuilderSpy
 *
 * Query builder spy that records Eloquent method calls instead of executing SQL.
 * This allows us to capture developer intent and convert it to Cypher patterns.
 *
 * This spy implements common Eloquent query builder methods and records them
 * for later conversion to Cypher patterns by CypherPatternGenerator.
 */
class CypherQueryBuilderSpy
{
    /**
     * Recorded method calls
     *
     * @var array
     */
    private array $calls = [];

    /**
     * The model class being queried
     *
     * @var string|null
     */
    private ?string $modelClass = null;

    /**
     * Create a new spy instance
     *
     * @param string|null $modelClass Optional model class context
     */
    public function __construct(?string $modelClass = null)
    {
        $this->modelClass = $modelClass;
    }

    /**
     * Add a basic where clause
     *
     * @param string|Closure $column
     * @param mixed $operator
     * @param mixed $value
     * @param string $boolean
     * @return self
     */
    public function where($column, $operator = null, $value = null, string $boolean = 'and'): self
    {
        // Handle closure (nested where)
        if ($column instanceof Closure) {
            $nested = new self($this->modelClass);
            $column($nested);

            $this->calls[] = [
                'method' => 'where',
                'type' => 'nested',
                'nested_calls' => $nested->getCalls(),
                'boolean' => $boolean,
            ];

            return $this;
        }

        // Handle two-argument form: where('status', 'active')
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->calls[] = [
            'method' => 'where',
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add an "or where" clause
     *
     * @param string|Closure $column
     * @param mixed $operator
     * @param mixed $value
     * @return self
     */
    public function orWhere($column, $operator = null, $value = null): self
    {
        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a "where in" clause
     *
     * @param string $column
     * @param array $values
     * @param string $boolean
     * @param bool $not
     * @return self
     */
    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->calls[] = [
            'method' => 'whereIn',
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
            'not' => $not,
        ];

        return $this;
    }

    /**
     * Add a "where not in" clause
     *
     * @param string $column
     * @param array $values
     * @param string $boolean
     * @return self
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add a "where null" clause
     *
     * @param string $column
     * @param string $boolean
     * @param bool $not
     * @return self
     */
    public function whereNull(string $column, string $boolean = 'and', bool $not = false): self
    {
        $this->calls[] = [
            'method' => 'whereNull',
            'type' => 'null',
            'column' => $column,
            'boolean' => $boolean,
            'not' => $not,
        ];

        return $this;
    }

    /**
     * Add a "where not null" clause
     *
     * @param string $column
     * @param string $boolean
     * @return self
     */
    public function whereNotNull(string $column, string $boolean = 'and'): self
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * Add a relationship exists clause
     *
     * @param string $relation
     * @param Closure|null $callback
     * @param string $operator
     * @param int $count
     * @return self
     */
    public function whereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1): self
    {
        $nested = null;

        if ($callback !== null) {
            $nested = new self($this->modelClass);
            $callback($nested);
        }

        $this->calls[] = [
            'method' => 'whereHas',
            'type' => 'relationship',
            'relation' => $relation,
            'nested_calls' => $nested ? $nested->getCalls() : [],
            'operator' => $operator,
            'count' => $count,
        ];

        return $this;
    }

    /**
     * Add a relationship does not exist clause
     *
     * @param string $relation
     * @param Closure|null $callback
     * @return self
     */
    public function whereDoesntHave(string $relation, ?Closure $callback = null): self
    {
        return $this->whereHas($relation, $callback, '<', 1);
    }

    /**
     * Add a date comparison clause
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @param string $boolean
     * @return self
     */
    public function whereDate(string $column, $operator, $value = null, string $boolean = 'and'): self
    {
        // Handle two-argument form
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->calls[] = [
            'method' => 'whereDate',
            'type' => 'date',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a time comparison clause
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @param string $boolean
     * @return self
     */
    public function whereTime(string $column, $operator, $value = null, string $boolean = 'and'): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->calls[] = [
            'method' => 'whereTime',
            'type' => 'time',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a between clause
     *
     * @param string $column
     * @param array $values
     * @param string $boolean
     * @param bool $not
     * @return self
     */
    public function whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->calls[] = [
            'method' => 'whereBetween',
            'type' => 'between',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
            'not' => $not,
        ];

        return $this;
    }

    /**
     * Add a "where not between" clause
     *
     * @param string $column
     * @param array $values
     * @param string $boolean
     * @return self
     */
    public function whereNotBetween(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add a "where column" clause (comparing two columns)
     *
     * @param string $first
     * @param string|null $operator
     * @param string|null $second
     * @param string $boolean
     * @return self
     */
    public function whereColumn(string $first, ?string $operator = null, ?string $second = null, string $boolean = 'and'): self
    {
        if (func_num_args() === 2) {
            $second = $operator;
            $operator = '=';
        }

        $this->calls[] = [
            'method' => 'whereColumn',
            'type' => 'column',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Get all recorded calls
     *
     * @return array
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * Get the model class context
     *
     * @return string|null
     */
    public function getModelClass(): ?string
    {
        return $this->modelClass;
    }

    /**
     * Check if any calls have been recorded
     *
     * @return bool
     */
    public function hasCalls(): bool
    {
        return !empty($this->calls);
    }

    /**
     * Clear all recorded calls
     *
     * @return void
     */
    public function clearCalls(): void
    {
        $this->calls = [];
    }

    /**
     * Count the number of recorded calls
     *
     * @return int
     */
    public function countCalls(): int
    {
        return count($this->calls);
    }
}
