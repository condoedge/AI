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
