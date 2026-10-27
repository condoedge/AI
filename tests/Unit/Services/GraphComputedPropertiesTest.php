<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Carbon\Carbon;
use Condoedge\Ai\Console\Commands\IngestEntitiesCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Services\BatchDataIngestionService;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Services\GraphPropertyMapper;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;

/**
 * Plan 9.0-p: COMPUTED graph properties.
 *
 * Host entity configs declare `graph.computed` (graph property => accessor/method name or
 * callable), `graph.with` (eager-loads) and `graph.casts` (forced graph types). Every
 * ingestion path (single ingest/sync, batch ingest, ai:ingest chunk query) routes node
 * properties through ONE mapping point: GraphPropertyMapper::mapNodeProperties().
 */
class GraphComputedPropertiesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('entities', []);
        // Model events must not reach the real AI facade while fixtures are created.
        $app['config']->set('ai.auto_sync.enabled', false);
    }

    public function setUp(): void
    {
        parent::setUp();

        Schema::create('computed_invoices', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('status')->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->dateTime('due_on')->nullable();
            $table->timestamps();
        });

        Schema::create('computed_payments', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('computed_invoice_id');
            $table->decimal('amount', 10, 2);
        });
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function seedInvoice(string $name = 'INV-1', array $payments = [10.25, 14.25]): ComputedInvoice
    {
        $invoice = ComputedInvoice::create([
            'name' => $name,
            'status' => 2,
            'total' => '100.00',
            'due_on' => '2026-01-15 10:00:00',
        ]);

        foreach ($payments as $amount) {
            ComputedPayment::create(['computed_invoice_id' => $invoice->id, 'amount' => $amount]);
        }

        return $invoice;
    }

    // ------------------------------------------------------------------ config

    public function test_graph_config_reads_computed_with_and_casts(): void
    {
        $fn = fn ($m) => 1;
        $config = GraphConfig::fromArray([
            'label' => 'Invoice',
            'properties' => ['id'],
            'computed' => ['due_amount' => 'real_due_amount', 'x' => $fn],
            'with' => ['payments', 'details.taxes'],
            'casts' => ['status' => 'string'],
        ]);

        $this->assertSame('real_due_amount', $config->computed['due_amount']);
        $this->assertSame($fn, $config->computed['x']);
        $this->assertSame(['payments', 'details.taxes'], $config->with);
        $this->assertSame(['status' => 'string'], $config->casts);
    }

    public function test_graph_config_defaults_are_empty(): void
    {
        $config = GraphConfig::fromArray(['label' => 'Invoice', 'properties' => ['id']]);

        $this->assertSame([], $config->computed);
        $this->assertSame([], $config->with);
        $this->assertSame([], $config->casts);
    }

    // ------------------------------------------------------------------ mapper

    public function test_mapper_evaluates_accessors_methods_and_callables_with_typed_values(): void
    {
        $invoice = ComputedInvoice::find($this->seedInvoice()->id);
        $mapper = new GraphPropertyMapper();

        $props = $mapper->mapNodeProperties($invoice, $invoice->getGraphConfig());

        // accessor returning a decimal string → float
        $this->assertSame(75.5, $props['due_amount']);
        // method (no args) returning an int → int
        $this->assertSame(2, $props['payment_count']);
        // method returning a float
        $this->assertSame(75.5, $props['balance']);
        // accessor returning Carbon → the model's own date serialization (same as toArray dates)
        $this->assertSame(Carbon::parse('2026-01-15 10:00:00', 'UTC')->toJSON(), $props['due_date']);
        $this->assertSame($invoice->toArray()['due_on'], $props['due_date'], 'computed dates match attribute dates');
        // bool stays bool
        $this->assertFalse($props['is_settled']);
        // callable fn($model)
        $this->assertSame('INV-1!', $props['shout']);
        // plain properties still extracted
        $this->assertSame('INV-1', $props['name']);
    }

    public function test_mapper_lazy_loads_with_relations_for_single_models(): void
    {
        $invoice = ComputedInvoice::find($this->seedInvoice()->id);
        $this->assertFalse($invoice->relationLoaded('payments'));

        (new GraphPropertyMapper())->mapNodeProperties($invoice, $invoice->getGraphConfig());

        $this->assertTrue($invoice->relationLoaded('payments'), 'graph.with relations are loadMissing()-ed');
    }

    public function test_null_computed_value_is_carried_as_null_so_neo4j_omits_it(): void
    {
        $invoice = ComputedInvoice::find($this->seedInvoice()->id);

        $props = (new GraphPropertyMapper())->mapNodeProperties($invoice, $invoice->getGraphConfig());

        // Same null handling as regular properties: the key is carried with null, which
        // Neo4j's SET n = $props / SET n.x = null stores as "no property" (and clears a
        // stale value on update).
        $this->assertArrayHasKey('nothing', $props);
        $this->assertNull($props['nothing']);
    }

    public function test_throwing_accessor_is_skipped_with_one_warning_per_entity_property(): void
    {
        $this->seedInvoice('A');
        $this->seedInvoice('B');
        $mapper = new GraphPropertyMapper();
        Log::spy();

        $results = [];
        foreach (ComputedBrokenInvoice::all() as $invoice) {
            $results[] = $mapper->mapNodeProperties($invoice, $invoice->getGraphConfig());
        }

        $this->assertCount(2, $results);
        foreach ($results as $props) {
            $this->assertArrayNotHasKey('broken', $props, 'failing computed property is skipped');
            $this->assertSame(75.5, $props['due_amount'], 'other computed properties still land');
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'computed')
                && ($context['property'] ?? null) === 'broken')
            ->once();
    }

    public function test_casts_force_graph_types(): void
    {
        $entity = new class {
            public function toArray(): array
            {
                return [
                    'id' => 1,
                    'status' => 2,
                    'code' => '00042',
                    'amount' => '12.30',
                    'count' => '7',
                    'flag' => 'yes',
                    'off' => 0,
                    'when' => '2026-03-01 08:30:00',
                    'missing' => null,
                ];
            }
        };

        $config = new GraphConfig(
            label: 'Thing',
            properties: ['id', 'status', 'code', 'amount', 'count', 'flag', 'off', 'when', 'missing'],
            casts: [
                'status' => 'string',
                'code' => 'string',
                'amount' => 'float',
                'count' => 'int',
                'flag' => 'bool',
                'off' => 'boolean',
                'when' => 'date',
                'missing' => 'int',
            ],
        );

        $props = (new GraphPropertyMapper())->mapNodeProperties($entity, $config);

        $this->assertSame('2', $props['status']);
        $this->assertSame('00042', $props['code']);
        $this->assertSame(12.3, $props['amount']);
        $this->assertSame(7, $props['count']);
        $this->assertTrue($props['flag']);
        $this->assertFalse($props['off']);
        $this->assertSame(Carbon::parse('2026-03-01 08:30:00')->toJSON(), $props['when']);
        $this->assertNull($props['missing']);
    }

    public function test_the_node_reads_the_entitys_graph_array_when_it_declares_one(): void
    {
        $entity = new class {
            public function toArray(): array
            {
                throw new \RuntimeException('serializes the loaded relations: never read for the graph');
            }

            public function toGraphArray(): array
            {
                return ['id' => 1, 'name' => 'INV-1', 'team_id' => 3];
            }
        };

        $config = new GraphConfig(label: 'Thing', properties: ['id', 'name']);

        $this->assertSame(['id' => 1, 'name' => 'INV-1'], (new GraphPropertyMapper())->mapNodeProperties($entity, $config));
        $this->assertSame(['id' => 1, 'name' => 'INV-1', 'team_id' => 3], GraphPropertyMapper::entityData($entity));
    }

    public function test_a_local_datetime_cast_writes_the_wall_clock_of_the_app_timezone(): void
    {
        config()->set('app.timezone', 'America/New_York');
        $entity = new class {
            public function toArray(): array
            {
                return ['id' => 1, 'utc' => '2026-10-01T01:30:00.000000Z', 'raw' => '2026-09-30 21:30:00', 'day' => '2026-09-30', 'none' => null];
            }
        };

        $config = new GraphConfig(
            label: 'Thing',
            properties: ['id', 'utc', 'raw', 'day', 'none'],
            computed: ['carbon' => fn () => Carbon::parse('2026-10-01 01:30:00', 'UTC')],
            casts: ['utc' => 'local_datetime', 'raw' => 'local_datetime', 'day' => 'local_datetime', 'none' => 'local_datetime', 'carbon' => 'local_datetime'],
        );

        $props = (new GraphPropertyMapper())->mapNodeProperties($entity, $config);

        $this->assertSame('2026-09-30T21:30:00', $props['utc'], 'a serialized UTC date reads as the local wall clock');
        $this->assertSame('2026-09-30T21:30:00', $props['raw'], 'a raw database value is already local');
        $this->assertSame('2026-09-30T00:00:00', $props['day']);
        $this->assertNull($props['none']);
        $this->assertSame('2026-09-30T21:30:00', $props['carbon']);
    }

    public function test_cast_applies_to_computed_property_before_numeric_normalization(): void
    {
        $invoice = ComputedInvoice::find($this->seedInvoice()->id);
        $mapper = new GraphPropertyMapper();

        $props = $mapper->mapNodeProperties($invoice, $invoice->getGraphConfig());

        // graph.casts => ['status' => 'string'] on an int column
        $this->assertSame('2', $props['status']);
    }

    public function test_without_computed_config_behaviour_is_unchanged(): void
    {
        $entity = new class {
            public function toArray(): array
            {
                return ['id' => 1, 'code' => '2410', 'n' => 3];
            }
        };

        $props = (new GraphPropertyMapper())->mapNodeProperties(
            $entity,
            new GraphConfig('Thing', ['id', 'code', 'n'])
        );

        $this->assertSame(['id' => 1, 'code' => '2410', 'n' => 3], $props);
    }

    // ------------------------------------------------------------------ single path

    /** @return array{0: DataIngestionService, 1: \Mockery\MockInterface} */
    private function singleService(): array
    {
        $graph = Mockery::mock(GraphStoreInterface::class);
        $service = new DataIngestionService(
            $graph,
        );

        return [$service, $graph];
    }

    public function test_single_sync_create_writes_computed_values(): void
    {
        $invoice = ComputedInvoice::find($this->seedInvoice()->id);
        [$service, $graph] = $this->singleService();

        $graph->shouldReceive('nodeExists')->andReturn(false);
        $captured = null;
        $graph->shouldReceive('createNode')->once()->andReturnUsing(function ($label, $props) use (&$captured) {
            $captured = $props;
            return $props['id'];
        });

        $service->sync($invoice);

        $this->assertSame(75.5, $captured['due_amount']);
        $this->assertSame(2, $captured['payment_count']);
        $this->assertSame('2', $captured['status']);
        $this->assertSame($invoice->id, $captured['id']);
    }

    public function test_single_sync_update_writes_computed_values(): void
    {
        $invoice = ComputedInvoice::find($this->seedInvoice()->id);
        [$service, $graph] = $this->singleService();

        $graph->shouldReceive('nodeExists')->andReturn(true);
        $captured = null;
        $graph->shouldReceive('updateNode')->once()->andReturnUsing(function ($label, $id, $props) use (&$captured) {
            $captured = $props;
            return true;
        });

        $service->sync($invoice);

        $this->assertSame(75.5, $captured['due_amount']);
        $this->assertSame(75.5, $captured['balance']);
        $this->assertArrayNotHasKey('id', $captured);
    }

    // ------------------------------------------------------------------ batch path

    public function test_batch_ingest_eager_loads_with_once_and_writes_computed_values(): void
    {
        $this->seedInvoice('A', [1.5]);
        $this->seedInvoice('B', [2.0, 3.0]);
        $this->seedInvoice('C', []);
        $models = ComputedInvoice::all()->all();

        $graph = Mockery::mock(GraphStoreInterface::class);
        $vector = Mockery::mock(VectorStoreInterface::class)->shouldIgnoreMissing();
        $embedding = Mockery::mock(EmbeddingProviderInterface::class)->shouldIgnoreMissing();
        $service = new BatchDataIngestionService(
            Mockery::mock(DataIngestionServiceInterface::class),
            $graph,
            Mockery::mock(AiLogger::class)->shouldIgnoreMissing(),
        );

        $batch = null;
        $graph->shouldReceive('query')->andReturnUsing(function ($cypher, $params) use (&$batch) {
            if (str_contains($cypher, 'UNWIND $batch')) {
                $batch = $params['batch'];
            }
            return [];
        });

        DB::enableQueryLog();
        $service->ingestBatch($models);
        $paymentQueries = array_filter(
            DB::getQueryLog(),
            fn ($q) => str_contains($q['query'], 'computed_payments')
        );
        DB::disableQueryLog();

        $this->assertCount(1, $paymentQueries, 'graph.with is eager-loaded once per batch, not per model');

        $byName = array_column($batch, null, 'name');
        $this->assertSame(98.5, $byName['A']['due_amount']);
        $this->assertSame(95.0, $byName['B']['due_amount']);
        $this->assertSame(100.0, $byName['C']['due_amount']);
        $this->assertSame(0, $byName['C']['payment_count']);
        $this->assertSame('2', $byName['A']['status']);
    }

    public function test_ai_ingest_pages_by_key_so_a_row_deleted_meanwhile_does_not_skip_another(): void
    {
        $ids = [$this->seedInvoice('A')->id, $this->seedInvoice('B')->id, $this->seedInvoice('C')->id];

        $received = [];
        $batch = Mockery::mock(BatchDataIngestionService::class);
        $batch->shouldReceive('ingestBatch')->andReturnUsing(function (array $entities) use (&$received, $ids) {
            $received = array_merge($received, array_map(fn ($entity) => $entity->id, $entities));
            ComputedInvoice::whereKey($ids[0])->delete(); // the app deletes a row while the ingest runs

            return ['total' => count($entities), 'succeeded' => count($entities), 'partially_succeeded' => 0, 'failed' => 0, 'errors' => []];
        });

        $command = new IngestEntitiesCommand($batch, Mockery::mock(GraphStoreInterface::class)->shouldIgnoreMissing());
        $command->setLaravel($this->app);
        $command->run(new ArrayInput(['--model' => ComputedInvoice::class, '--chunk' => 1]), new BufferedOutput());

        $this->assertSame($ids, $received);
    }

    public function test_ai_ingest_chunk_query_applies_graph_with(): void
    {
        $this->seedInvoice('A');
        $this->seedInvoice('B');

        $received = [];
        $batch = Mockery::mock(BatchDataIngestionService::class);
        $batch->shouldReceive('ingestBatch')->andReturnUsing(function (array $entities) use (&$received) {
            $received = array_merge($received, $entities);
            return ['total' => count($entities), 'succeeded' => count($entities), 'partially_succeeded' => 0, 'failed' => 0, 'errors' => []];
        });

        $command = new IngestEntitiesCommand(
            $batch,
            Mockery::mock(GraphStoreInterface::class)->shouldIgnoreMissing(),
        );
        $command->setLaravel($this->app);
        $command->run(new ArrayInput(['--model' => ComputedInvoice::class]), new BufferedOutput());

        $this->assertCount(2, $received);
        foreach ($received as $model) {
            $this->assertTrue($model->relationLoaded('payments'), 'chunk query eager-loads graph.with');
        }
    }
}

class ComputedPayment extends Model
{
    protected $table = 'computed_payments';
    protected $guarded = [];
    public $timestamps = false;
}

class ComputedInvoice extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $table = 'computed_invoices';
    protected $guarded = [];
    protected $casts = ['due_on' => 'datetime', 'total' => 'decimal:2'];

    public function nodeableConfig(): array
    {
        return [
            'graph' => [
                'label' => 'ComputedInvoice',
                'properties' => ['id', 'name', 'status'],
                'computed' => [
                    'due_amount' => 'real_due_amount',
                    'balance' => 'outstandingBalance',
                    'payment_count' => 'paymentCount',
                    'due_date' => 'due_date',
                    'is_settled' => 'is_settled',
                    'nothing' => 'nothing',
                    'shout' => fn ($model) => $model->name . '!',
                ],
                'with' => ['payments'],
                'casts' => ['status' => 'string'],
                'relationships' => [],
            ],
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ComputedPayment::class, 'computed_invoice_id');
    }

    /** Legacy accessor returning a decimal STRING (like CondoEdge's real_due_amount). */
    public function getRealDueAmountAttribute(): string
    {
        return number_format((float) $this->total - (float) $this->payments->sum('amount'), 2, '.', '');
    }

    public function getDueDateAttribute(): ?Carbon
    {
        return $this->due_on;
    }

    public function getIsSettledAttribute(): bool
    {
        return (float) $this->real_due_amount <= 0.0;
    }

    public function getNothingAttribute(): mixed
    {
        return null;
    }

    public function outstandingBalance(): float
    {
        return (float) $this->real_due_amount;
    }

    public function paymentCount(): int
    {
        return $this->payments->count();
    }
}

class ComputedBrokenInvoice extends ComputedInvoice
{
    public function nodeableConfig(): array
    {
        $config = parent::nodeableConfig();
        $config['graph']['label'] = 'ComputedBrokenInvoice';
        $config['graph']['computed']['broken'] = 'broken_value';

        return $config;
    }

    public function getBrokenValueAttribute(): mixed
    {
        throw new \RuntimeException('boom');
    }
}
