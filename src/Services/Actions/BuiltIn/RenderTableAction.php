<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * render_table — generative-UI built-in.
 *
 * Injects a TABLE built from a tabular result set into the chat message. The result set is
 * taken from the render context ('result_set' or 'rows'), so the LLM only needs to say
 * "show this as a table" — it never has to ship the data through the link.
 *
 * Link grammar:
 *   [Show as table](action://render_table?limit=10)
 *
 * Renders an _Rows/_Html table when Kompo helpers are present; otherwise returns the
 * normalized {headers, rows} payload that MessagesQuery::renderTableData() already knows
 * how to display.
 */
class RenderTableAction extends AbstractBuiltInAction
{
    /** Hard cap so a runaway result set can't blow up the bubble. */
    private const MAX_ROWS = 100;

    public function key(): string
    {
        return 'render_table';
    }

    public function label(): string
    {
        return 'Show as table';
    }

    public function description(): string
    {
        return 'Render the current result set as a table.';
    }

    public function presentation(): string
    {
        return 'table';
    }

    public function inputSchema(): array
    {
        return [
            'limit' => ['type' => 'int', 'default' => 10, 'label' => 'Max rows'],
        ];
    }

    public function handle(array $input, array $context): mixed
    {
        $rows = $context['result_set'] ?? $context['rows'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            return AiActionResult::error('No data available to render as a table.');
        }

        $limit = (int) ($input['limit'] ?? 10);
        if ($limit <= 0) {
            $limit = 10;
        }
        $limit = min($limit, self::MAX_ROWS);

        $payload = $this->normalize($rows, $limit);

        // The 'table' response_data shape consumed by MessagesQuery::renderTableData().
        $tableData = [
            'type' => 'table',
            'headers' => $payload['headers'],
            'rows' => $payload['rows'],
        ];

        return AiActionResult::ok(
            $tableData,
            'Rendered ' . count($payload['rows']) . ' row(s) as a table.'
        );
    }

    /**
     * Normalize an array of associative rows into {headers, rows} for table rendering.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{headers: string[], rows: array<int, array<int, string>>}
     */
    private function normalize(array $rows, int $limit): array
    {
        $rows = array_slice(array_values($rows), 0, $limit);

        // Derive headers from the union of keys across the (capped) rows, preserving order.
        $headers = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                if (!in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }
        }

        $tableRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $tableRows[] = [$this->stringify($row)];
                continue;
            }
            $cells = [];
            foreach ($headers as $h) {
                $cells[] = $this->stringify($row[$h] ?? '');
            }
            $tableRows[] = $cells;
        }

        return ['headers' => $headers, 'rows' => $tableRows];
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
