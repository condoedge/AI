<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Contracts\AiActionInterface;

/**
 * Base for the ship-by-default generative-UI actions (open_modal, render_table, link,
 * export_excel, export_pdf). Provides SAFE DEFAULTS so each concrete action only declares
 * what differs.
 *
 * Defaults: no aliases, description = label, empty schema, read-only (mutates=false),
 * authorize=true. Domain actions that mutate override mutates()+authorize().
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

    public function mutates(): bool
    {
        return false;
    }

    /**
     * Backward-compatible alias of {@see mutates()} for any host action written against the
     * old method name. The dispatcher reads mutates().
     */
    final public function requiresApproval(): bool
    {
        return $this->mutates();
    }

    public function authorize(mixed $user, array $input): bool
    {
        return true;
    }
}
