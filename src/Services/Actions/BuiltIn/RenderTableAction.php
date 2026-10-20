<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Response\TableRenderer;

/**
 * render_table — generative-UI built-in.
 *
 * Returns a TABLE intent built from a tabular result set. The result set is taken from the
 * context's result_set, so the LLM only needs to say "show this as a table" — it never has to
 * ship the data through the link. The presenter renders the normalized {headers, rows} payload.
 *
 * Link grammar:
 *   [Show as table](action://render_table?limit=10)
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

    public function inputSchema(): array
    {
        return [
            'limit' => ['type' => 'int', 'default' => 10, 'label' => 'Max rows'],
        ];
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $rows = $context->resultSet;
        if (empty($rows)) {
            return AiActionResult::error('No data available to render as a table.');
        }

        $limit = (int) ($input['limit'] ?? 10);
        if ($limit <= 0) {
            $limit = 10;
        }
        $limit = min($limit, self::MAX_ROWS);

        // THE one renderer (Phase 8 batch A): same redaction as exports (internal
        // id columns, UI decorations) — the former private normalize() did none —
        // and the same humanized headers / cells as the chat table.
        [$headers, $matrix] = (new TableRenderer())->matrix(
            array_slice(array_values($rows), 0, $limit),
            ['humanize_values' => true]
        );

        if ($matrix === []) {
            return AiActionResult::error('No displayable data to render as a table.');
        }

        return AiActionResult::table(
            ['headers' => $headers, 'rows' => $matrix],
            'Rendered ' . count($matrix) . ' row(s) as a table.'
        );
    }
}
