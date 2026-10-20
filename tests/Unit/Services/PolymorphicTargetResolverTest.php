<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\BatchDataIngestionService;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Services\PolymorphicTargetResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Mockery;

/**
 * Unit tests for PolymorphicTargetResolver
 *
 * Verifies morph value -> catalog label resolution: morph-map alias lookup,
 * raw FQCN passthrough, FQCN-then-basename catalog keys, null for anything
 * unresolvable (never throws), and per-process memoization.
 */
class PolymorphicTargetResolverTest extends TestCase
{
    public function tearDown(): void
    {
        // Relation::morphMap is global static state — reset between tests.
        Relation::morphMap([], false);
        Mockery::close();
        parent::tearDown();
    }

    public function test_resolves_alias_via_morph_map_and_fqcn_catalog_key(): void
    {
        Relation::morphMap(['service' => 'App\\Models\\Billing\\Service']);
        config(['entities' => [
            'App\\Models\\Billing\\Service' => ['graph' => ['label' => 'Service']],
        ]]);

        $resolver = new PolymorphicTargetResolver();

        $this->assertSame('Service', $resolver->resolveLabel('service'));
    }

    public function test_resolves_alias_via_basename_catalog_key_fallback(): void
    {
        Relation::morphMap(['service' => 'App\\Models\\Billing\\Service']);
        config(['entities' => [
            'Service' => ['graph' => ['label' => 'Service']],
        ]]);

        $resolver = new PolymorphicTargetResolver();

        $this->assertSame('Service', $resolver->resolveLabel('service'));
    }

    public function test_resolves_raw_fqcn_morph_value_without_map_entry(): void
    {
        config(['entities' => [
            FakeMorphTargetModel::class => ['graph' => ['label' => 'FakeTarget']],
        ]]);

        $resolver = new PolymorphicTargetResolver();

        $this->assertSame('FakeTarget', $resolver->resolveLabel(FakeMorphTargetModel::class));
    }

    public function test_unmapped_alias_returns_null(): void
    {
        config(['entities' => []]);

        $resolver = new PolymorphicTargetResolver();

        $this->assertNull($resolver->resolveLabel('not-an-alias-or-class'));
    }

    public function test_mapped_class_without_catalog_entry_returns_null(): void
    {
        Relation::morphMap(['service' => 'App\\Models\\Billing\\Service']);
        config(['entities' => []]);

        $resolver = new PolymorphicTargetResolver();

        $this->assertNull($resolver->resolveLabel('service'));
    }

    public function test_null_empty_and_non_string_values_return_null(): void
    {
        $resolver = new PolymorphicTargetResolver();

        $this->assertNull($resolver->resolveLabel(null));
        $this->assertNull($resolver->resolveLabel(''));
        $this->assertNull($resolver->resolveLabel(42));
        $this->assertNull($resolver->resolveLabel(['service']));
    }

    public function test_memoizes_resolved_labels(): void
    {
        Relation::morphMap(['service' => 'App\\Models\\Billing\\Service']);
        config(['entities' => [
            'App\\Models\\Billing\\Service' => ['graph' => ['label' => 'Service']],
        ]]);

        $resolver = new PolymorphicTargetResolver();
        $this->assertSame('Service', $resolver->resolveLabel('service'));

        // Wipe the catalog: the memoized result must still be served.
        config(['entities' => []]);
        $this->assertSame('Service', $resolver->resolveLabel('service'));
    }

    public function test_memoizes_negative_results(): void
    {
        config(['entities' => []]);

        $resolver = new PolymorphicTargetResolver();
        $this->assertNull($resolver->resolveLabel('service'));

        // Mapping the alias afterwards must not change the memoized answer.
        Relation::morphMap(['service' => 'App\\Models\\Billing\\Service']);
        config(['entities' => [
            'App\\Models\\Billing\\Service' => ['graph' => ['label' => 'Service']],
        ]]);
        $this->assertNull($resolver->resolveLabel('service'));
    }

    public function test_container_shares_one_resolver_across_ingestion_services(): void
    {
        // The provider must hand the SAME resolver instance to the per-entity
        // syncer (via DataIngestionService's relationshipSyncer argument) and
        // to BatchDataIngestionService, so one memo cache serves a whole
        // ai:sync-relationships run.
        $this->app->instance(GraphStoreInterface::class, Mockery::mock(GraphStoreInterface::class));
        $this->app->instance(VectorStoreInterface::class, Mockery::mock(VectorStoreInterface::class));
        $this->app->instance(EmbeddingProviderInterface::class, Mockery::mock(EmbeddingProviderInterface::class));

        $shared = $this->app->make(PolymorphicTargetResolver::class);
        $ingestion = $this->app->make(DataIngestionServiceInterface::class);
        $batch = $this->app->make(BatchDataIngestionService::class);

        $this->assertInstanceOf(DataIngestionService::class, $ingestion);
        $this->assertSame($shared, $this->extractResolver($batch, 'polymorphicResolver'));

        $syncer = $this->extractResolver($ingestion, 'relationshipSyncer');
        $this->assertSame($shared, $this->extractResolver($syncer, 'polymorphicResolver'));
    }

    private function extractResolver(object $object, string $property): object
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}

/**
 * Real, autoload-resolvable class so the resolver's class_exists() passthrough
 * for raw-FQCN morph values can be exercised.
 */
class FakeMorphTargetModel
{
}
