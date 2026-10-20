<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\DTOs\PrunedEntitySelection;
use Condoedge\Ai\Services\Retrieval\EntitySelectionPruner;
use Condoedge\Ai\Tests\Fixtures\SelectionFixture;
use Condoedge\Ai\Tests\TestCase;

/**
 * Task 5.10 — principled entity-selection pruning.
 *
 * seed (QU entity types / anchor / page / focus) + matched-scope labels
 *   → identity (only when possessive) → tenant-scope hops → shortest-path closure
 *   → ownership-path labels (possessive) ; vector hits ONLY when there is no seed;
 * global cap, ordered by tier.
 */
class EntitySelectionPrunerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        config(['ai.security.enabled' => true]);
    }

    private function fixturePruner(int $maxLabels = 12): EntitySelectionPruner
    {
        config(['entities' => SelectionFixture::entityConfigs()]);

        return new EntitySelectionPruner(
            entityConfigs: SelectionFixture::entityConfigs(),
            identityLabels: ['User', 'Team'],
            userEntityPaths: SelectionFixture::userEntityPaths(),
            maxLabels: $maxLabels,
        );
    }

    private function pruneGold(string $id): PrunedEntitySelection
    {
        $q = SelectionFixture::question($id);
        $seeds = [];
        foreach ($q['entity_types'] as $label) {
            $seeds[$label] = "Query Understanding: '{$label}'";
        }

        return $this->fixturePruner()->prune(
            seeds: $seeds,
            scopeLabels: $q['scope_labels'],
            vectorHits: $q['vector_hits'],
            isPossessive: $q['is_possessive'],
            schemaLabels: SelectionFixture::labels(),
            patterns: SelectionFixture::patterns(),
        );
    }

    // ------------------------------------------------------------ gold questions

    public function test_q1_team_members_with_roles_is_small_and_sufficient(): void
    {
        $result = $this->pruneGold('q1');

        foreach (['Team', 'User', 'TeamRole'] as $label) {
            $this->assertContains($label, $result->labels);
        }
        $this->assertLessThanOrEqual(4, count($result->labels), implode(', ', $result->labels));
        // The former selection pulled in every User/Team neighbour.
        foreach (['Signature', 'Tag', 'Tax', 'Channel', 'Review'] as $noise) {
            $this->assertNotContains($noise, $result->labels);
        }
    }

    public function test_q2_unit_balance_is_small_and_sufficient(): void
    {
        $result = $this->pruneGold('q2');

        foreach (['Unit', 'Invoice', 'Union'] as $label) {
            $this->assertContains($label, $result->labels);
        }
        $this->assertLessThanOrEqual(8, count($result->labels), implode(', ', $result->labels));
        // Not possessive: no identity labels.
        $this->assertNotContains('User', $result->labels);
        $this->assertSame([], $result->identityLabels);
        // Union is there because Unit/Invoice are tenant-scoped through it.
        $this->assertSame(EntitySelectionPruner::TIER_TENANT, $result->tiers['Union']);
    }

    public function test_q3_board_and_president_is_small_and_has_no_finance_labels(): void
    {
        $result = $this->pruneGold('q3');

        foreach (['BoardMember', 'Contact', 'Union'] as $label) {
            $this->assertContains($label, $result->labels);
        }
        foreach (['Bill', 'Fund', 'Invoice', 'Transaction'] as $noise) {
            $this->assertNotContains($noise, $result->labels);
        }
        $this->assertLessThanOrEqual(8, count($result->labels), implode(', ', $result->labels));
    }

    public function test_regression_questions_keep_their_subject(): void
    {
        $q4 = $this->pruneGold('q4');
        $this->assertContains('Invoice', $q4->labels, 'receivables live on Invoice (matched scope)');
        $this->assertContains('Union', $q4->labels);
        $this->assertLessThanOrEqual(10, count($q4->labels), implode(', ', $q4->labels));

        $q5 = $this->pruneGold('q5');
        foreach (['Meeting', 'Schedule'] as $label) {
            $this->assertContains($label, $q5->labels);
        }
        $this->assertLessThanOrEqual(10, count($q5->labels), implode(', ', $q5->labels));
    }

    // ------------------------------------------------------------ tiers (synthetic graph)

    private function syntheticPruner(array $overrides = []): EntitySelectionPruner
    {
        $configs = [
            'App\\Contact' => ['graph' => ['label' => 'Contact']],
            'App\\Unit' => ['graph' => ['label' => 'Unit', 'relationships' => [
                ['type' => 'UNION', 'target_label' => 'Union'],
            ]], 'security' => ['team_path' => 'union.team_id']],
            'App\\ContactUnit' => ['graph' => ['label' => 'ContactUnit']],
            'App\\Union' => ['graph' => ['label' => 'Union'], 'security' => ['team_path' => 'team_id']],
            'App\\Team' => ['graph' => ['label' => 'Team']],
            'App\\User' => ['graph' => ['label' => 'User']],
            'App\\Tax' => ['graph' => ['label' => 'Tax']],
            'App\\Meeting' => ['graph' => ['label' => 'Meeting']],
            'App\\A' => ['graph' => ['label' => 'A']],
            'App\\B' => ['graph' => ['label' => 'B']],
            'App\\C' => ['graph' => ['label' => 'C']],
            'App\\D' => ['graph' => ['label' => 'D']],
            'App\\E' => ['graph' => ['label' => 'E']],
            'App\\Role' => ['graph' => ['label' => 'Role', 'relationships' => [
                // A scalar-FK join (no live edge) still connects the labels.
                ['type' => 'BELONGS_TO_A', 'target_label' => 'A', 'foreign_key' => 'a_id'],
            ]]],
            'App\\F' => ['graph' => ['label' => 'F']],
            'App\\G' => ['graph' => ['label' => 'G']],
        ];
        $configs = array_replace($configs, $overrides['configs'] ?? []);
        config(['entities' => $configs]);

        return new EntitySelectionPruner(
            entityConfigs: $configs,
            identityLabels: ['User', 'Team'],
            userEntityPaths: $overrides['paths'] ?? [
                'Unit' => ['entities' => ['User', 'Contact', 'ContactUnit', 'Unit']],
            ],
            maxLabels: $overrides['max'] ?? 12,
            closureDepth: $overrides['depth'] ?? 2,
            vectorFallbackTopK: $overrides['topk'] ?? 5,
        );
    }

    private function syntheticLabels(): array
    {
        return ['Contact', 'Unit', 'ContactUnit', 'Union', 'Team', 'User', 'Tax', 'Meeting', 'A', 'B', 'C', 'D', 'E', 'Role', 'F', 'G'];
    }

    private function syntheticPatterns(): array
    {
        $p = fn (string $f, string $t, string $to, int $c = 10) => ['from_label' => $f, 'type' => $t, 'to_label' => $to, 'count' => $c];

        return [
            $p('ContactUnit', 'CONTACT', 'Contact'),
            $p('ContactUnit', 'UNIT', 'Unit'),
            $p('Unit', 'UNION', 'Union'),
            $p('Union', 'TEAM', 'Team'),
            $p('Tax', 'BELONGS_TO_TEAM', 'Team'),
            $p('Meeting', 'BELONGS_TO_TEAM', 'Team'),
            $p('Contact', 'USER', 'User'),
            // A - B - C - D - E chain
            $p('A', 'R', 'B'),
            $p('B', 'R', 'C'),
            $p('C', 'R', 'D'),
            $p('D', 'R', 'E'),
        ];
    }

    private function prune(EntitySelectionPruner $pruner, array $seeds, array $scopes = [], array $vector = [], bool $possessive = false): PrunedEntitySelection
    {
        $seedMap = [];
        foreach ($seeds as $s) {
            $seedMap[$s] = 'seed';
        }

        return $pruner->prune($seedMap, $scopes, $vector, $possessive, $this->syntheticLabels(), $this->syntheticPatterns());
    }

    public function test_closure_adds_the_labels_on_the_shortest_path_between_seeds(): void
    {
        $result = $this->prune($this->syntheticPruner(), ['Contact', 'Unit']);

        $this->assertContains('ContactUnit', $result->labels);
        $this->assertSame(EntitySelectionPruner::TIER_CLOSURE, $result->tiers['ContactUnit']);
    }

    public function test_closure_is_bounded_by_depth(): void
    {
        // A..E is 4 hops (3 intermediates) — beyond the default depth of 2 intermediates.
        $far = $this->prune($this->syntheticPruner(), ['A', 'E']);
        $this->assertSame(['A', 'E'], $far->labels);

        // A..D is 3 hops (2 intermediates) — within depth 2.
        $near = $this->prune($this->syntheticPruner(), ['A', 'D']);
        $this->assertSame(['A', 'D', 'B', 'C'], $near->labels);

        $shallow = $this->prune($this->syntheticPruner(['depth' => 1]), ['A', 'D']);
        $this->assertSame(['A', 'D'], $shallow->labels);
    }

    public function test_closure_never_routes_through_an_identity_label(): void
    {
        // Tax and Meeting only share Team — a tenancy anchor, not a join path.
        $result = $this->prune($this->syntheticPruner(), ['Tax', 'Meeting']);

        $this->assertSame(['Tax', 'Meeting'], $result->labels);
    }

    public function test_closure_uses_configured_foreign_key_joins(): void
    {
        $result = $this->prune($this->syntheticPruner(), ['Role', 'B']);

        // Role -(fk a_id, no live edge)- A -[:R]- B
        $this->assertSame(['Role', 'B', 'A'], $result->labels);
    }

    public function test_identity_labels_only_when_possessive(): void
    {
        $plain = $this->prune($this->syntheticPruner(), ['Tax']);
        $this->assertSame(['Tax'], $plain->labels);
        $this->assertSame([], $plain->identityLabels);

        $mine = $this->prune($this->syntheticPruner(), ['Tax'], possessive: true);
        $this->assertSame(['Tax', 'User', 'Team'], $mine->labels);
        $this->assertSame(['User', 'Team'], $mine->identityLabels);
    }

    public function test_tenant_scope_labels_are_added_for_seeds(): void
    {
        $result = $this->prune($this->syntheticPruner(), ['Unit']);

        $this->assertSame(['Unit', 'Union'], $result->labels);
        $this->assertSame(EntitySelectionPruner::TIER_TENANT, $result->tiers['Union']);
    }

    public function test_ownership_path_labels_only_when_possessive(): void
    {
        $plain = $this->prune($this->syntheticPruner(), ['Unit']);
        $this->assertNotContains('ContactUnit', $plain->labels);

        $mine = $this->prune($this->syntheticPruner(), ['Unit'], possessive: true);
        $this->assertContains('ContactUnit', $mine->labels);
        $this->assertContains('Contact', $mine->labels);
        $this->assertSame(EntitySelectionPruner::TIER_OWNERSHIP, $mine->tiers['ContactUnit']);
    }

    public function test_a_collective_possessive_keeps_the_identity_anchors_without_the_ownership_paths(): void
    {
        // Eval p17: "How much do we owe…" — the team anchor is wanted, the labels on
        // the asker's PERSONAL ownership path are not (they are how "my X" reaches X).
        $ours = $this->syntheticPruner()->prune(
            ['Unit' => 'seed'], [], [], true, $this->syntheticLabels(), $this->syntheticPatterns(), ownershipPaths: false
        );

        $this->assertSame(['User', 'Team'], $ours->identityLabels);
        $this->assertNotContains('ContactUnit', $ours->labels);
        $this->assertNotContains('Contact', $ours->labels);
        $this->assertNotContains(EntitySelectionPruner::TIER_OWNERSHIP, $ours->tiers);
    }

    public function test_the_ownership_paths_follow_the_identity_anchors_unless_told_otherwise(): void
    {
        $pruner = $this->syntheticPruner();
        $mine = $pruner->prune(['Unit' => 'seed'], [], [], true, $this->syntheticLabels(), $this->syntheticPatterns(), ownershipPaths: true);
        $default = $pruner->prune(['Unit' => 'seed'], [], [], true, $this->syntheticLabels(), $this->syntheticPatterns());

        $this->assertSame(EntitySelectionPruner::TIER_OWNERSHIP, $mine->tiers['ContactUnit']);
        $this->assertSame($mine->labels, $default->labels);

        // Never an ownership path without the identity anchor it starts from.
        $plain = $pruner->prune(['Unit' => 'seed'], [], [], false, $this->syntheticLabels(), $this->syntheticPatterns(), ownershipPaths: true);
        $this->assertNotContains('ContactUnit', $plain->labels);
    }

    public function test_scope_labels_are_seeds_too(): void
    {
        $result = $this->prune($this->syntheticPruner(), ['Tax'], scopes: ['Meeting']);

        $this->assertSame(['Tax', 'Meeting'], $result->labels);
        $this->assertSame(EntitySelectionPruner::TIER_SCOPE, $result->tiers['Meeting']);
    }

    public function test_the_labels_of_a_matched_calculation_are_kept_and_scoped(): void
    {
        $pruner = $this->syntheticPruner();
        $result = $pruner->prune(
            ['Unit' => 'seed'], ['Meeting'], [], false, $this->syntheticLabels(), $this->syntheticPatterns(), calculationLabels: ['Union', 'Tax']
        );

        // Calculation labels rank right after the question's own labels…
        $this->assertSame(['Unit', 'Meeting', 'Tax', 'Union'], $result->labels);
        $this->assertSame(EntitySelectionPruner::TIER_CALCULATION, $result->tiers['Tax']);
        // …except one that is a seed's tenant hop: it keeps the tenant tier (compact card).
        $this->assertSame(EntitySelectionPruner::TIER_TENANT, $result->tiers['Union']);

        // A calculation label is itself a terminal: its own tenant hop comes along.
        $scoped = $pruner->prune(['Tax' => 'seed'], [], [], false, $this->syntheticLabels(), $this->syntheticPatterns(), calculationLabels: ['Unit']);
        $this->assertSame(['Tax', 'Unit', 'Union'], $scoped->labels);
    }

    public function test_calculation_labels_alone_suppress_the_vector_fallback(): void
    {
        $result = $this->syntheticPruner()->prune(
            [], [], ['Meeting' => 0.9], false, $this->syntheticLabels(), $this->syntheticPatterns(), calculationLabels: ['Tax']
        );

        $this->assertSame(['Tax'], $result->labels);
    }

    public function test_q4_receivables_keeps_invoice_through_the_matched_calculation(): void
    {
        // QU typed "receivables" as Account/AccountBalance; the canonical receivables
        // formula the question asks for is over Invoice.
        $q = SelectionFixture::question('q4');
        $seeds = array_fill_keys($q['entity_types'], 'qu');

        $result = $this->fixturePruner()->prune(
            $seeds, [], [], $q['is_possessive'], SelectionFixture::labels(), SelectionFixture::patterns(), calculationLabels: ['Invoice']
        );

        $this->assertContains('Invoice', $result->labels);
        $this->assertSame(EntitySelectionPruner::TIER_CALCULATION, $result->tiers['Invoice']);
    }

    public function test_vector_hits_are_ignored_when_there_is_a_seed(): void
    {
        $result = $this->prune($this->syntheticPruner(), ['Tax'], vector: ['Meeting' => 0.9, 'A' => 0.8]);

        $this->assertSame(['Tax'], $result->labels);
    }

    public function test_vector_hits_are_a_capped_fallback_when_there_is_no_seed(): void
    {
        $result = $this->prune(
            $this->syntheticPruner(['topk' => 2]),
            [],
            vector: ['A' => 0.5, 'Tax' => 0.9, 'Meeting' => 0.7]
        );

        // Top 2 by score, in score order.
        $this->assertSame(['Tax', 'Meeting'], $result->labels);
        $this->assertSame(EntitySelectionPruner::TIER_VECTOR, $result->tiers['Tax']);
    }

    public function test_the_global_cap_keeps_the_highest_tiers(): void
    {
        $result = $this->prune($this->syntheticPruner(['max' => 3]), ['Contact', 'Unit'], possessive: true);

        // seeds first, each with its tenant hop (Unit → Union); identity, closure and
        // ownership are cut.
        $this->assertSame(['Contact', 'Unit', 'Union'], $result->labels);
    }

    public function test_tenant_hops_outrank_identity_labels_under_the_cap(): void
    {
        // Phase 5C review: with identity ahead of tenant hops, a possessive question
        // with several seeds could cut the Union hop its seeds are scoped through.
        $result = $this->prune($this->syntheticPruner(['max' => 3]), ['Unit', 'Tax'], possessive: true);

        $this->assertSame(['Unit', 'Tax', 'Union'], $result->labels);
        $this->assertSame([], $result->identityLabels);
        $this->assertContains('User', $result->dropped);
    }

    public function test_a_label_is_never_kept_without_its_tenant_hop(): void
    {
        // Unit + its Union hop do not fit next to Tax: Unit is dropped, and the
        // orphan hop is not kept on its own.
        $result = $this->prune($this->syntheticPruner(['max' => 2]), ['Tax', 'Unit']);

        $this->assertSame(['Tax'], $result->labels);
        $this->assertContains('Unit', $result->dropped);
        $this->assertNotContains('Union', $result->labels);
    }

    public function test_closure_labels_get_their_tenant_hops(): void
    {
        $pruner = $this->syntheticPruner(['configs' => [
            'App\\C' => ['graph' => ['label' => 'C', 'relationships' => [['type' => 'IN_F', 'target_label' => 'F']]],
                'security' => ['team_path' => 'f.team_id']],
        ]]);

        $result = $this->prune($pruner, ['A', 'D']);

        $this->assertSame(['A', 'D', 'B', 'C', 'F'], $result->labels);
        $this->assertSame(EntitySelectionPruner::TIER_CLOSURE, $result->tiers['C']);
        $this->assertSame(EntitySelectionPruner::TIER_TENANT, $result->tiers['F']);
    }

    public function test_ownership_labels_get_their_tenant_hops(): void
    {
        $pruner = $this->syntheticPruner(['configs' => [
            'App\\ContactUnit' => ['graph' => ['label' => 'ContactUnit', 'relationships' => [['type' => 'IN_G', 'target_label' => 'G']]],
                'security' => ['team_path' => 'g.team_id']],
        ]]);

        $result = $this->prune($pruner, ['Unit'], possessive: true);

        $this->assertContains('ContactUnit', $result->labels);
        $this->assertContains('G', $result->labels);
        $this->assertSame(EntitySelectionPruner::TIER_TENANT, $result->tiers['G']);
    }

    public function test_labels_absent_from_the_graph_are_dropped(): void
    {
        $result = $this->prune($this->syntheticPruner(), ['Tax', 'Pet']);

        $this->assertSame(['Tax'], $result->labels);
    }

    public function test_seed_labels_are_matched_case_insensitively_to_graph_labels(): void
    {
        $result = $this->prune($this->syntheticPruner(), ['tax']);

        $this->assertSame(['Tax'], $result->labels);
    }

    public function test_every_label_carries_its_reason(): void
    {
        $result = $this->prune($this->syntheticPruner(), ['Contact', 'Unit']);

        $this->assertSame('seed', $result->reasons['Contact']);
        $this->assertStringContainsString('Contact', $result->reasons['ContactUnit']);
        $this->assertStringContainsString('Unit', $result->reasons['ContactUnit']);
    }
}
