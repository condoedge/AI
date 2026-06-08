<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Contracts\AiActionInterface;

/**
 * Base for the ship-by-default generative-UI actions (open_modal, render_table, link,
 * export_excel, export_pdf). Provides SAFE DEFAULTS so each concrete action only declares
 * what differs.
 *
 * Defaults: no aliases, empty schema, 'link' presentation, no approval required,
 * authorize=true (these built-ins are presentational / read-only — domain actions that
 * mutate override requiresApproval()+authorize()).
 */
abstract class AbstractBuiltInAction implements AiActionInterface
{
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return $this->label();
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function presentation(): string
    {
        return 'link';
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function authorize(mixed $user, array $input): bool
    {
        return true;
    }
}
