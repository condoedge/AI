<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

use Condoedge\Ai\Exceptions\QueryGenerationException;

/**
 * A domain calculation (ai.calculations) the host application computes itself.
 *
 * A calculation written as a query is a copy of a screen's figure: it has to be re-verified each
 * time the screen changes. One that names a `resolver` runs the code of the screen instead, so the
 * answer shows the application's own number:
 *
 *   'resolver' => [UnitBalance::class, 'rows'],   // fn (mixed $user, array $values): list<array>
 *   'values' => ['UNIT NAME?' => 'the unit the question names'],
 *   'money' => ['billed', 'paid'],   // the columns holding money: the answer may then state their total
 *
 * A calculation that declares 'money' returns every row, or caps at exactly
 * ai.query_execution.default_limit: a lower cap would have its sum presented as a complete total.
 *
 * The model names the calculation and gives the values, as for a query ({@see CalculationQuery}).
 * The resolver receives the asker and narrows the rows to what they may read: no scope is applied
 * after it.
 */
final class ApplicationCalculation
{
    private const MAX_VALUE_LENGTH = 200;

    /** @param array<string, list<string>> $values */
    private function __construct(public readonly string $key, private readonly array $entry, public readonly array $values)
    {
    }

    public static function configured(string $name): bool
    {
        return is_callable(config('ai.calculations.' . trim($name) . '.resolver'));
    }

    /**
     * @param array<string, mixed> $given what the model gave, by value name
     * @param list<string>|null $allowed the keys this turn's prompt offered: null means any
     * @throws QueryGenerationException with what the model should do instead
     */
    public static function named(string $name, array $given, ?array $allowed = null): self
    {
        $key = trim($name);
        $entry = config('ai.calculations.' . $key);

        if (!is_array($entry) || !is_callable($entry['resolver'] ?? null) || ($allowed !== null && !in_array($key, $allowed, true))) {
            throw new QueryGenerationException("\"{$name}\" is not one of the calculations of this prompt. Use one of them by its key, or write the query.");
        }

        $declared = array_keys((array) ($entry['values'] ?? []));
        $known = array_map(static fn (string $value): string => rtrim($value, '?'), $declared);
        $values = [];

        foreach ($given as $valueName => $value) {
            $valueName = strtoupper(trim(strtr(trim((string) $valueName), '_', ' '), '<>? '));
            $list = array_values(array_filter(
                array_map(static fn (mixed $item): string => is_scalar($item) ? self::clean((string) $item) : '', is_array($value) ? $value : [$value]),
                static fn (string $item): bool => $item !== ''
            ));
            if ($list === []) {
                continue;
            }
            if (!in_array($valueName, $known, true)) {
                throw new QueryGenerationException("The calculation \"{$key}\" has no <{$valueName}>" . ($known === [] ? ': it takes no value.' : ': its values are <' . implode('>, <', $known) . '>.'));
            }
            $values[$valueName] = $list;
        }

        foreach ($declared as $value) {
            if (!str_ends_with($value, '?') && !isset($values[$value])) {
                throw new QueryGenerationException("The calculation \"{$key}\" needs a value for <{$value}>. Give it in \"values\", or use another one.");
            }
        }

        return new self($key, $entry, $values);
    }

    /** @return list<array<string, mixed>> */
    public function rows(mixed $user): array
    {
        $rows = ($this->entry['resolver'])($user, $this->values);

        return array_values(array_map(static fn ($row): array => (array) $row, is_iterable($rows) ? [...$rows] : []));
    }

    private static function clean(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, self::MAX_VALUE_LENGTH);
    }
}
