<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Security\RowScope;
use Condoedge\Ai\Services\Security\TeamPathResolver;

/**
 * TenantScopingRulesSection
 *
 * The per-entity counterpart of the static scopeable-subset rules: for EVERY
 * tenant-sensitive label in the schema it tells the model the EXACT $ai_team_ids
 * predicate to emit, derived from the SAME {@see TeamPathResolver::ruleFor()}
 * template the acceptance gate verifies and the executor injects. Because the
 * instruction, the gate's accept-condition and the injector's fallback all come
 * from one resolver, the generator can emit scope the gate accepts AS-IS (avoiding
 * the post-hoc splice that broke UNION column projections).
 *
 * SYSTEM role, priority 76 (right after query_rules/75, before question/80). Emits
 * nothing when team security is off, when the subset rules are disabled, or when no
 * schema label is tenant-scoped — so unscoped deployments are unaffected.
 *
 * For a user the host narrows below the team (RowScope) it renders the row rules
 * ($ai_row_scope_ids) instead, and only for labels that have one.
 */
class TenantScopingRulesSection extends BasePromptSection
{
    protected string $name = 'tenant_scoping_rules';
    protected int $priority = 76;

    public function __construct(
        private readonly TeamPathResolver $resolver = new TeamPathResolver(),
        ?string $name = null,
        ?int $priority = null
    ) {
        parent::__construct($name, $priority);
    }

    public function role(): string
    {
        // Static, per-entity scoping policy belongs in the system framing.
        return 'system';
    }

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        return $this->scopedLabelRules($context, $options)['lines'] !== [];
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        if (!$this->enabled()) {
            return '';
        }

        ['lines' => $rules, 'param' => $param, 'row_scoped' => $rowScoped] = $this->scopedLabelRules($context, $options);
        if ($rules === []) {
            return '';
        }

        $lines = [];
        $lines[] = $rowScoped
            ? 'TENANT SCOPING — every query MUST keep results within the records this user may see.'
            : 'TENANT SCOPING — every query MUST keep results within the user\'s teams.';
        $lines[] = 'For each labelled node you MATCH and use, add its team filter EXACTLY as shown,';
        $lines[] = "using the SAME bound parameter {$param}:";

        foreach ($rules as $line) {
            $lines[] = '  ' . $line;
        }

        $lines[] = 'Rules:';
        $lines[] = '  - Apply the filter to the node\'s OWN variable; reuse the variable, do not rebind it.';
        $lines[] = "  - {$param} is provided automatically — never inline team ids as a list.";
        $lines[] = '  - Combine multiple node filters with AND in a single WHERE per query part.';
        if ($rowScoped) {
            $lines[] = "  - These filters replace any team filter shown elsewhere in these instructions: never compare a team_id with {$param}.";
        }
        // Tenant-specific UNION rules only; the generic "same column aliases" rule is in
        // QueryRulesSection so it ships even with scoping off (review M-3).
        $lines[] = 'UNION RULES (critical):';
        $lines[] = '  - Treat each UNION branch as a complete, independent query: MATCH its own';
        $lines[] = "    labelled nodes AND add their {$param} filter INSIDE that branch.";
        $lines[] = '  - Express team scoping as a WHERE predicate whenever possible; if a relationship';
        $lines[] = '    MATCH is required, place it before the branch\'s RETURN and do NOT add the';
        $lines[] = '    helper node to the RETURN.';

        return $this->header('TENANT SCOPING RULES') . implode("\n", $lines) . "\n\n";
    }

    /**
     * One instruction line per tenant-scoped label present in the schema. Empty when
     * no label is tenant-scoped.
     *
     * @return array{lines: list<string>, param: string, row_scoped: bool}
     */
    private function scopedLabelRules(PipelineContext $context, array $options = []): array
    {
        $rowScoped = RowScope::idsFor($options['user'] ?? auth()->user()) !== null;
        $param = '$' . ($rowScoped ? TeamPathResolver::ROW_SCOPE_IDS_PARAM : TeamPathResolver::TEAM_IDS_PARAM);

        $labels = $context->graphSchema['labels'] ?? [];
        if (!is_array($labels) || $labels === []) {
            return ['lines' => [], 'param' => $param, 'row_scoped' => $rowScoped];
        }

        $lines = [];
        $seen = [];

        foreach ($labels as $label) {
            if (!is_string($label) || $label === '' || isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;

            $alias = $this->aliasFor($label);
            $rule = $this->resolver->ruleFor($label, $alias, $rowScoped);

            if ($rule === null) {
                continue; // not tenant-scoped → no instruction
            }

            $lines[] = sprintf('- (%s:%s) → %s', $alias, $label, $rule['filter']);
        }

        return ['lines' => $lines, 'param' => $param, 'row_scoped' => $rowScoped];
    }

    /**
     * Label-initial alias matching the generator's conventions (e.g. Invoice → i),
     * so the rendered template uses the variable the model is most likely to pick.
     */
    private function aliasFor(string $label): string
    {
        return strtolower(substr($label, 0, 1));
    }

    /**
     * Whether the per-entity scoping instructions should be emitted. Mirrors the
     * static scopeable-subset gate in SemanticPromptBuilder so the two are toggled
     * together. Container-tolerant for unit tests.
     */
    private function enabled(): bool
    {
        if (function_exists('app') && app()->bound('config')) {
            if (!config('ai.security.enabled', true)) {
                return false;
            }
            if (!config('ai.security.enforce_scopeable_subset', true)) {
                return false;
            }
        }

        return true;
    }
}
