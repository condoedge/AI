<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Digest;

use Condoedge\Ai\Services\Digest\AttentionSourceRegistry;
use Condoedge\Ai\Tests\TestCase;

/**
 * Multi-query digest v1 — the host-config registry reader.
 *
 * `ai.attention_sources` is the WHITE-LABEL seam: every CondoEdge specific
 * (labels, enum literals, cypher) lives in the HOST config; the package only
 * ships this reader. The reader must be strict about what counts as a usable
 * source (label + cypher required, fallback only as a labeled pair) so a
 * misconfigured entry degrades to "not configured" instead of a broken turn.
 */
class AttentionSourceRegistryTest extends TestCase
{
    /** @test */
    public function it_returns_enabled_sources_in_config_order_with_normalized_shape(): void
    {
        $registry = new AttentionSourceRegistry([
            'open_tasks' => [
                'label' => 'Open and urgent tasks',
                'description' => 'Tasks not yet closed.',
                'cypher' => 'MATCH (t:Task) RETURN t.title',
                'action_key' => 'tasks',
                'entity' => 'Task',
                'limit' => 7,
            ],
            'meetings' => [
                'label' => 'Upcoming meetings',
                'cypher' => 'MATCH (m:Meeting) RETURN m.name',
                'fallback_cypher' => 'MATCH (m:Meeting) RETURN m.name ORDER BY m.start_date DESC',
                'fallback_label' => 'Most recent meetings — none scheduled',
            ],
        ]);

        $sources = $registry->enabledSources();

        $this->assertSame(['open_tasks', 'meetings'], array_keys($sources));
        $this->assertSame('Open and urgent tasks', $sources['open_tasks']['label']);
        $this->assertSame(7, $sources['open_tasks']['limit']);
        $this->assertSame('tasks', $sources['open_tasks']['action_key']);
        $this->assertSame('Task', $sources['open_tasks']['entity']);
        $this->assertNull($sources['open_tasks']['fallback_cypher']);
        $this->assertSame('Most recent meetings — none scheduled', $sources['meetings']['fallback_label']);
        $this->assertNotNull($sources['meetings']['fallback_cypher']);
    }

    /** @test */
    public function disabled_and_structurally_invalid_entries_are_dropped(): void
    {
        $registry = new AttentionSourceRegistry([
            'held_back' => [
                'label' => 'Ships disabled pending W7',
                'cypher' => 'MATCH (m:MaintenanceDate) RETURN m',
                'enabled' => false,
            ],
            'no_cypher' => ['label' => 'No query'],
            'no_label' => ['cypher' => 'MATCH (n) RETURN n'],
            'not_an_array' => 'MATCH (n) RETURN n',
            'valid' => ['label' => 'Valid', 'cypher' => 'MATCH (t:Task) RETURN t'],
        ]);

        $this->assertSame(['valid'], array_keys($registry->enabledSources()));
        $this->assertTrue($registry->hasEnabled());
    }

    /** @test */
    public function a_fallback_without_its_mandatory_label_is_stripped_not_mislabeled(): void
    {
        // Design invariant: fallback rows must NEVER masquerade as primary rows,
        // so a fallback_cypher without fallback_label is unusable — dropped.
        $registry = new AttentionSourceRegistry([
            'meetings' => [
                'label' => 'Upcoming meetings',
                'cypher' => 'MATCH (m:Meeting) RETURN m.name',
                'fallback_cypher' => 'MATCH (m:Meeting) RETURN m.name',
            ],
        ]);

        $source = $registry->enabledSources()['meetings'];
        $this->assertNull($source['fallback_cypher']);
        $this->assertNull($source['fallback_label']);
    }

    /** @test */
    public function it_reads_live_config_when_not_injected(): void
    {
        config()->set('ai.attention_sources', [
            'from_config' => ['label' => 'From config', 'cypher' => 'MATCH (n:Thing) RETURN n'],
        ]);
        config()->set('ai.digest', ['max_sources' => 3, 'default_row_cap' => 2]);

        $registry = new AttentionSourceRegistry();

        $this->assertSame(['from_config'], array_keys($registry->enabledSources()));
        $this->assertSame(3, $registry->maxSources());
        $this->assertSame(2, $registry->defaultRowCap());
    }

    /** @test */
    public function defaults_are_eight_sources_and_five_rows_and_never_below_one(): void
    {
        $registry = new AttentionSourceRegistry([], []);
        $this->assertSame(8, $registry->maxSources());
        $this->assertSame(5, $registry->defaultRowCap());
        $this->assertFalse($registry->hasEnabled());

        $floored = new AttentionSourceRegistry([], ['max_sources' => 0, 'default_row_cap' => -2]);
        $this->assertSame(1, $floored->maxSources());
        $this->assertSame(1, $floored->defaultRowCap());
    }

    /** @test */
    public function cap_overflow_is_structurally_visible_sources_to_run_plus_skipped_source_keys(): void
    {
        // Amendment B2: an enabled source dropped by max_sources must be
        // distinguishable from a never-configured one — the registry exposes
        // exactly which keys were capped out.
        $registry = new AttentionSourceRegistry([
            'first' => ['label' => '1', 'cypher' => 'MATCH (n) RETURN n'],
            'second' => ['label' => '2', 'cypher' => 'MATCH (n) RETURN n'],
            'third' => ['label' => '3', 'cypher' => 'MATCH (n) RETURN n'],
            'held' => ['label' => 'Held', 'cypher' => 'MATCH (n) RETURN n', 'enabled' => false],
        ], ['max_sources' => 2]);

        $this->assertSame(['first', 'second'], array_keys($registry->sourcesToRun()));
        $this->assertSame(['third'], $registry->skippedSourceKeys(), 'capped-out enabled keys are data, never silence');
    }

    /** @test */
    public function the_fallback_source_of_an_entity_is_its_only_source_when_that_one_declares_a_fallback(): void
    {
        // Eval p16-A meetings: an empty "next meeting" result is answered from the
        // source the host declared for that entity — when exactly ONE source speaks for it.
        $registry = new AttentionSourceRegistry([
            'open_tasks' => ['label' => 'Open tasks', 'cypher' => 'MATCH (t:Task) RETURN t.id AS id', 'entity' => 'Task'],
            'upcoming_meetings' => [
                'label' => 'Upcoming meetings',
                'cypher' => 'MATCH (m:Meeting) RETURN m.id AS id',
                'fallback_cypher' => 'MATCH (m:Meeting) RETURN m.id AS id ORDER BY m.start_date DESC',
                'fallback_label' => 'Most recent meetings — none scheduled',
                'entity' => 'Meeting',
                'limit' => 3,
            ],
        ]);

        $source = $registry->fallbackSourceFor('Meeting');

        $this->assertNotNull($source);
        $this->assertSame('upcoming_meetings', $source['key']);
        $this->assertSame('Most recent meetings — none scheduled', $source['fallback_label']);
        $this->assertSame(3, $source['limit']);
        $this->assertNull($registry->fallbackSourceFor('Task'), 'its only source declares no fallback');
        $this->assertNull($registry->fallbackSourceFor('Invoice'), 'no source for the entity');
        $this->assertNull($registry->fallbackSourceFor(''));
        $this->assertNull($registry->fallbackSourceFor(null));
    }

    /** @test */
    public function several_sources_for_one_entity_are_ambiguous_and_give_no_fallback_source(): void
    {
        $fallback = [
            'fallback_cypher' => 'MATCH (b:Bill) RETURN b.id AS id ORDER BY b.due_at DESC',
            'fallback_label' => 'Most recently approved bills',
        ];
        $registry = new AttentionSourceRegistry([
            'bills_pending_approval' => ['label' => 'Awaiting approval', 'cypher' => 'MATCH (b:Bill) RETURN b.id AS id', 'entity' => 'Bill'] + $fallback,
            'bills_approved_unpaid' => ['label' => 'Approved, unpaid', 'cypher' => 'MATCH (b:Bill) RETURN b.id AS id', 'entity' => 'Bill'],
            'held' => ['label' => 'Held', 'cypher' => 'MATCH (m:Meeting) RETURN m.id AS id', 'entity' => 'Meeting', 'enabled' => false] + $fallback,
        ]);

        $this->assertNull($registry->fallbackSourceFor('Bill'), 'which of the two the empty query stands for is unknown');
        $this->assertNull($registry->fallbackSourceFor('Meeting'), 'a disabled source is not a source');
    }

    /** @test */
    public function no_overflow_means_no_skipped_keys_and_disabled_entries_never_count_as_skipped(): void
    {
        $registry = new AttentionSourceRegistry([
            'only' => ['label' => 'Only', 'cypher' => 'MATCH (n) RETURN n'],
            'held' => ['label' => 'Held', 'cypher' => 'MATCH (n) RETURN n', 'enabled' => false],
        ], ['max_sources' => 8]);

        $this->assertSame(['only'], array_keys($registry->sourcesToRun()));
        $this->assertSame([], $registry->skippedSourceKeys(), 'enabled=false is deliberate config, not cap overflow');
    }
}
