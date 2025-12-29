# AI Package Comprehensive Improvement Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Transform the AI package into a production-ready, enterprise-grade conversational AI system that junior developers can easily configure while delivering ChatGPT-quality answers for domain questions.

**Architecture:** Phased approach addressing: (1) Developer Experience for easy adoption, (2) Conversation Quality for better answers, (3) Production Readiness for observability and reliability, (4) Advanced Features for power users.

**Tech Stack:** PHP 8.2+, Laravel 10+, Neo4j, Qdrant, Redis (for caching/sessions), OpenAI/Anthropic APIs

---

## Phase 1: Developer Experience (Make It Trivially Easy)

### Task 1: Create `ai:diagnose` Command

**Files:**
- Create: `src/Console/Commands/DiagnoseCommand.php`
- Test: `tests/Unit/Console/DiagnoseCommandTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/Console/DiagnoseCommandTest.php

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class DiagnoseCommandTest extends TestCase
{
    /** @test */
    public function it_checks_neo4j_connectivity(): void
    {
        $this->artisan('ai:diagnose')
            ->assertSuccessful()
            ->expectsOutputToContain('Neo4j');
    }

    /** @test */
    public function it_checks_qdrant_connectivity(): void
    {
        $this->artisan('ai:diagnose')
            ->assertSuccessful()
            ->expectsOutputToContain('Qdrant');
    }

    /** @test */
    public function it_checks_llm_api_key(): void
    {
        $this->artisan('ai:diagnose')
            ->assertSuccessful()
            ->expectsOutputToContain('LLM');
    }

    /** @test */
    public function it_checks_entities_config_exists(): void
    {
        $this->artisan('ai:diagnose')
            ->assertSuccessful()
            ->expectsOutputToContain('entities.php');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Console/DiagnoseCommandTest.php -v`
Expected: FAIL with "Command ai:diagnose not found"

**Step 3: Write minimal implementation**

```php
<?php
// src/Console/Commands/DiagnoseCommand.php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DiagnoseCommand extends Command
{
    protected $signature = 'ai:diagnose {--fix : Attempt to fix issues}';
    protected $description = 'Diagnose AI package configuration and connectivity';

    private array $checks = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;

    public function handle(): int
    {
        $this->info('AI Package Diagnostic Report');
        $this->info(str_repeat('=', 50));
        $this->newLine();

        // 1. Check configuration files
        $this->checkConfiguration();

        // 2. Check Neo4j connectivity
        $this->checkNeo4j();

        // 3. Check Qdrant connectivity
        $this->checkQdrant();

        // 4. Check LLM API
        $this->checkLlmApi();

        // 5. Check embedding provider
        $this->checkEmbeddingProvider();

        // Summary
        $this->newLine();
        $this->info(str_repeat('=', 50));
        $this->info("Summary: {$this->passed} passed, {$this->failed} failed, {$this->warnings} warnings");

        if ($this->failed > 0) {
            $this->newLine();
            $this->error('Some checks failed. Fix the issues above before using AI features.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All checks passed! AI package is ready to use.');
        return self::SUCCESS;
    }

    private function checkConfiguration(): void
    {
        $this->info('Configuration:');

        // Check entities.php exists
        $entitiesPath = config_path('entities.php');
        if (File::exists($entitiesPath)) {
            $entities = include $entitiesPath;
            $count = is_array($entities) ? count($entities) : 0;
            $this->pass("entities.php exists ({$count} entities configured)");
        } else {
            $this->fail('entities.php not found - run: php artisan ai:discover');
        }

        // Check ai.php config
        if (config('ai.llm.default')) {
            $this->pass('ai.php configured (default LLM: ' . config('ai.llm.default') . ')');
        } else {
            $this->fail('ai.php not configured - publish config: php artisan vendor:publish --tag=ai-config');
        }

        $this->newLine();
    }

    private function checkNeo4j(): void
    {
        $this->info('Neo4j:');

        $host = config('ai.neo4j.host', 'not set');
        $port = config('ai.neo4j.port', 'not set');

        if ($host === 'not set') {
            $this->fail('Neo4j host not configured');
            return;
        }

        $this->pass("Host: {$host}:{$port}");

        try {
            $graphStore = app(GraphStoreInterface::class);
            $schema = $graphStore->getSchema();
            $labelCount = count($schema['labels'] ?? []);
            $this->pass("Connected ({$labelCount} labels found)");
        } catch (\Exception $e) {
            $this->fail('Connection failed: ' . $e->getMessage());
        }

        $this->newLine();
    }

    private function checkQdrant(): void
    {
        $this->info('Qdrant:');

        $host = config('ai.qdrant.host', 'not set');
        $port = config('ai.qdrant.port', 'not set');

        if ($host === 'not set') {
            $this->fail('Qdrant host not configured');
            return;
        }

        $this->pass("Host: {$host}:{$port}");

        try {
            $vectorStore = app(VectorStoreInterface::class);
            $collections = $vectorStore->listCollections();
            $collectionCount = count($collections);
            $this->pass("Connected ({$collectionCount} collections found)");
        } catch (\Exception $e) {
            $this->fail('Connection failed: ' . $e->getMessage());
        }

        $this->newLine();
    }

    private function checkLlmApi(): void
    {
        $this->info('LLM API:');

        $provider = config('ai.llm.default');
        $apiKey = config("ai.llm.{$provider}.api_key");

        if (!$provider) {
            $this->fail('No LLM provider configured');
            return;
        }

        $this->pass("Provider: {$provider}");

        if (!$apiKey) {
            $this->fail('API key not set - add to .env: AI_' . strtoupper($provider) . '_API_KEY=xxx');
        } elseif (strlen($apiKey) < 20) {
            $this->warn('API key looks invalid (too short)');
        } else {
            $maskedKey = substr($apiKey, 0, 8) . '...' . substr($apiKey, -4);
            $this->pass("API key configured ({$maskedKey})");
        }

        $this->newLine();
    }

    private function checkEmbeddingProvider(): void
    {
        $this->info('Embedding Provider:');

        $provider = config('ai.embeddings.provider', 'openai');
        $model = config('ai.embeddings.model', 'text-embedding-3-small');
        $dimensions = config('ai.embeddings.dimensions', 1536);

        $this->pass("Provider: {$provider}, Model: {$model}, Dimensions: {$dimensions}");
        $this->newLine();
    }

    private function pass(string $message): void
    {
        $this->line("  <fg=green>✓</> {$message}");
        $this->passed++;
    }

    private function fail(string $message): void
    {
        $this->line("  <fg=red>✗</> {$message}");
        $this->failed++;
    }

    private function warn(string $message): void
    {
        $this->line("  <fg=yellow>!</> {$message}");
        $this->warnings++;
    }
}
```

**Step 4: Register command in service provider**

Add to `AiServiceProvider::boot()`:
```php
if ($this->app->runningInConsole()) {
    $this->commands([
        Commands\DiagnoseCommand::class,
    ]);
}
```

**Step 5: Run tests and verify**

Run: `php vendor/bin/phpunit tests/Unit/Console/DiagnoseCommandTest.php -v`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Console/Commands/DiagnoseCommand.php tests/Unit/Console/DiagnoseCommandTest.php
git commit -m "feat: add ai:diagnose command for connectivity checks"
```

---

### Task 2: Create `ai:config:validate` Command

**Files:**
- Create: `src/Console/Commands/ValidateConfigCommand.php`
- Test: `tests/Unit/Console/ValidateConfigCommandTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/Console/ValidateConfigCommandTest.php

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Tests\TestCase;

class ValidateConfigCommandTest extends TestCase
{
    /** @test */
    public function it_validates_entity_config_structure(): void
    {
        $this->artisan('ai:config:validate')
            ->assertSuccessful();
    }

    /** @test */
    public function it_detects_missing_required_fields(): void
    {
        // Test with invalid config
        config(['entities' => [
            'App\\Models\\Customer' => [
                // Missing 'graph' key
            ],
        ]]);

        $this->artisan('ai:config:validate')
            ->assertFailed()
            ->expectsOutputToContain('missing');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Console/ValidateConfigCommandTest.php -v`
Expected: FAIL

**Step 3: Write minimal implementation**

```php
<?php
// src/Console/Commands/ValidateConfigCommand.php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Illuminate\Console\Command;

class ValidateConfigCommand extends Command
{
    protected $signature = 'ai:config:validate {--fix : Attempt to fix issues}';
    protected $description = 'Validate entity configuration';

    private array $errors = [];
    private array $warnings = [];

    public function handle(): int
    {
        $this->info('Validating AI configuration...');
        $this->newLine();

        $entities = config('entities', []);

        if (empty($entities)) {
            $this->error('No entities configured. Run: php artisan ai:discover');
            return self::FAILURE;
        }

        foreach ($entities as $entityClass => $config) {
            $this->validateEntityConfig($entityClass, $config);
        }

        // Display results
        $this->displayResults();

        return empty($this->errors) ? self::SUCCESS : self::FAILURE;
    }

    private function validateEntityConfig(string $entityClass, array $config): void
    {
        $shortName = class_basename($entityClass);

        // Check required keys
        if (empty($config['graph'])) {
            $this->errors[] = "{$shortName}: missing 'graph' configuration";
        } else {
            if (empty($config['graph']['label'])) {
                $this->errors[] = "{$shortName}: missing graph label";
            }
            if (empty($config['graph']['properties'])) {
                $this->warnings[] = "{$shortName}: no properties configured";
            }
        }

        if (empty($config['vector'])) {
            $this->warnings[] = "{$shortName}: missing 'vector' configuration (entity won't be searchable)";
        } else {
            if (empty($config['vector']['collection'])) {
                $this->errors[] = "{$shortName}: missing vector collection name";
            }
            if (empty($config['vector']['embed_fields'])) {
                $this->warnings[] = "{$shortName}: no embed fields (entity won't be searchable by content)";
            }
        }

        // Check class exists
        if (!class_exists($entityClass)) {
            $this->errors[] = "{$shortName}: class does not exist ({$entityClass})";
        }
    }

    private function displayResults(): void
    {
        if (!empty($this->errors)) {
            $this->error('Errors:');
            foreach ($this->errors as $error) {
                $this->line("  <fg=red>✗</> {$error}");
            }
            $this->newLine();
        }

        if (!empty($this->warnings)) {
            $this->warn('Warnings:');
            foreach ($this->warnings as $warning) {
                $this->line("  <fg=yellow>!</> {$warning}");
            }
            $this->newLine();
        }

        if (empty($this->errors) && empty($this->warnings)) {
            $this->info('All configurations are valid!');
        }
    }
}
```

**Step 4: Run tests and verify**

Run: `php vendor/bin/phpunit tests/Unit/Console/ValidateConfigCommandTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Console/Commands/ValidateConfigCommand.php tests/Unit/Console/ValidateConfigCommandTest.php
git commit -m "feat: add ai:config:validate command"
```

---

## Phase 2: Conversation Quality (Top-Tier Answers)

### Task 3: Add Conversation Persistence

**Files:**
- Create: `database/migrations/2025_01_01_create_ai_conversations_table.php`
- Create: `src/Models/AiConversation.php`
- Create: `src/Models/AiMessage.php`
- Modify: `src/Services/Chat/AiChatService.php`
- Test: `tests/Unit/Services/Chat/ConversationPersistenceTest.php`

**Step 1: Create migration**

```php
<?php
// database/migrations/2025_01_01_000001_create_ai_conversations_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('status')->default('active'); // active, archived, deleted
            $table->json('metadata')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['team_id', 'status']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role'); // user, assistant, system
            $table->text('content');
            $table->json('response_data')->nullable(); // AiChatResponseData serialized
            $table->json('context_used')->nullable(); // RAG context for debugging
            $table->string('cypher_query')->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->float('confidence_score')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
```

**Step 2: Create models**

```php
<?php
// src/Models/AiConversation.php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiConversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'team_id',
        'title',
        'status',
        'metadata',
        'last_message_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_message_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = $model->uuid ?? (string) Str::uuid();
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\\Models\\User'));
    }

    public function addMessage(string $role, string $content, array $data = []): AiMessage
    {
        $message = $this->messages()->create([
            'role' => $role,
            'content' => $content,
            'response_data' => $data['response_data'] ?? null,
            'context_used' => $data['context_used'] ?? null,
            'cypher_query' => $data['cypher_query'] ?? null,
            'execution_time_ms' => $data['execution_time_ms'] ?? null,
            'confidence_score' => $data['confidence_score'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->update(['last_message_at' => now()]);

        // Auto-generate title from first user message
        if ($this->title === null && $role === 'user') {
            $this->update(['title' => Str::limit($content, 50)]);
        }

        return $message;
    }

    public function getRecentMessages(int $limit = 10): array
    {
        return $this->messages()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->toArray();
    }
}
```

```php
<?php
// src/Models/AiMessage.php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'response_data',
        'context_used',
        'cypher_query',
        'execution_time_ms',
        'confidence_score',
        'metadata',
    ];

    protected $casts = [
        'response_data' => 'array',
        'context_used' => 'array',
        'metadata' => 'array',
        'confidence_score' => 'float',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
```

**Step 3: Update AiChatService to use persistence**

Add to `src/Services/Chat/AiChatService.php`:

```php
// Add to constructor
protected ?AiConversation $conversation = null;

public function setConversation(AiConversation $conversation): self
{
    $this->conversation = $conversation;
    return $this;
}

public function startConversation(?int $userId = null, ?int $teamId = null): AiConversation
{
    $this->conversation = AiConversation::create([
        'user_id' => $userId,
        'team_id' => $teamId,
        'status' => 'active',
    ]);
    return $this->conversation;
}

public function getConversation(): ?AiConversation
{
    return $this->conversation;
}

// Modify ask() method to persist messages
public function ask(string $question, array $options = []): AiChatMessage
{
    // ... existing code ...

    // Persist if conversation is set
    if ($this->conversation) {
        $this->conversation->addMessage('user', $question);
        $this->conversation->addMessage('assistant', $answerText, [
            'response_data' => $responseData->toArray(),
            'execution_time_ms' => $executionTime,
            'cypher_query' => $aiResponse['cypher'] ?? null,
            'confidence_score' => $aiResponse['confidence'] ?? null,
        ]);
    }

    return AiChatMessage::assistant($answerText, $responseData);
}
```

**Step 4: Write tests**

```php
<?php
// tests/Unit/Services/Chat/ConversationPersistenceTest.php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConversationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_conversation_with_uuid(): void
    {
        $conversation = AiConversation::create([
            'status' => 'active',
        ]);

        $this->assertNotNull($conversation->uuid);
        $this->assertEquals('active', $conversation->status);
    }

    /** @test */
    public function it_adds_messages_to_conversation(): void
    {
        $conversation = AiConversation::create(['status' => 'active']);

        $message = $conversation->addMessage('user', 'Hello');

        $this->assertEquals('user', $message->role);
        $this->assertEquals('Hello', $message->content);
        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    /** @test */
    public function it_auto_generates_title_from_first_message(): void
    {
        $conversation = AiConversation::create(['status' => 'active']);

        $conversation->addMessage('user', 'How many customers do we have?');

        $this->assertEquals('How many customers do we have?', $conversation->fresh()->title);
    }

    /** @test */
    public function it_retrieves_recent_messages_in_order(): void
    {
        $conversation = AiConversation::create(['status' => 'active']);

        $conversation->addMessage('user', 'First');
        $conversation->addMessage('assistant', 'Response to first');
        $conversation->addMessage('user', 'Second');

        $messages = $conversation->getRecentMessages(10);

        $this->assertCount(3, $messages);
        $this->assertEquals('First', $messages[0]['content']);
        $this->assertEquals('Second', $messages[2]['content']);
    }
}
```

**Step 5: Run migrations and tests**

Run: `php artisan migrate`
Run: `php vendor/bin/phpunit tests/Unit/Services/Chat/ConversationPersistenceTest.php -v`
Expected: PASS

**Step 6: Commit**

```bash
git add database/migrations/ src/Models/ src/Services/Chat/AiChatService.php tests/
git commit -m "feat: add conversation persistence with AiConversation and AiMessage models"
```

---

### Task 4: Add Query Result Caching

**Files:**
- Create: `src/Services/Cache/QueryResultCache.php`
- Modify: `src/Services/ContextRetriever.php`
- Test: `tests/Unit/Services/Cache/QueryResultCacheTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/Services/Cache/QueryResultCacheTest.php

namespace Condoedge\Ai\Tests\Unit\Services\Cache;

use Condoedge\Ai\Services\Cache\QueryResultCache;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class QueryResultCacheTest extends TestCase
{
    /** @test */
    public function it_caches_context_results(): void
    {
        $cache = new QueryResultCache();

        $context = ['similar_queries' => [['question' => 'test']]];
        $cache->cacheContext('How many customers?', $context);

        $cached = $cache->getContext('How many customers?');

        $this->assertNotNull($cached);
        $this->assertEquals($context, $cached);
    }

    /** @test */
    public function it_returns_null_for_uncached_questions(): void
    {
        $cache = new QueryResultCache();

        $cached = $cache->getContext('Never asked before');

        $this->assertNull($cached);
    }

    /** @test */
    public function it_normalizes_questions_for_cache_key(): void
    {
        $cache = new QueryResultCache();

        $context = ['test' => true];
        $cache->cacheContext('How many customers?', $context);

        // Same question with different casing/spacing should hit cache
        $cached = $cache->getContext('how many customers?');

        $this->assertNotNull($cached);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Services/Cache/QueryResultCacheTest.php -v`
Expected: FAIL

**Step 3: Write minimal implementation**

```php
<?php
// src/Services/Cache/QueryResultCache.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class QueryResultCache
{
    private string $prefix;
    private int $ttl;

    public function __construct(?string $prefix = null, ?int $ttl = null)
    {
        $this->prefix = $prefix ?? config('ai.cache.prefix', 'ai.query.');
        $this->ttl = $ttl ?? config('ai.cache.ttl', 3600); // 1 hour default
    }

    public function cacheContext(string $question, array $context): void
    {
        $key = $this->buildKey($question, 'context');
        Cache::put($key, $context, $this->ttl);
    }

    public function getContext(string $question): ?array
    {
        $key = $this->buildKey($question, 'context');
        return Cache::get($key);
    }

    public function cacheQuery(string $question, string $cypher, array $metadata = []): void
    {
        $key = $this->buildKey($question, 'query');
        Cache::put($key, [
            'cypher' => $cypher,
            'metadata' => $metadata,
            'cached_at' => now()->toIso8601String(),
        ], $this->ttl);
    }

    public function getQuery(string $question): ?array
    {
        $key = $this->buildKey($question, 'query');
        return Cache::get($key);
    }

    public function invalidate(string $question): void
    {
        Cache::forget($this->buildKey($question, 'context'));
        Cache::forget($this->buildKey($question, 'query'));
    }

    public function flush(): void
    {
        // Note: This only works with cache drivers that support tags
        // For redis: Cache::tags([$this->prefix])->flush();
        // For file/array: manually clear keys starting with prefix
    }

    private function buildKey(string $question, string $type): string
    {
        $normalized = $this->normalizeQuestion($question);
        $hash = md5($normalized);
        return "{$this->prefix}{$type}.{$hash}";
    }

    private function normalizeQuestion(string $question): string
    {
        // Lowercase, trim, remove extra whitespace
        $normalized = strtolower(trim($question));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Remove punctuation for matching
        $normalized = preg_replace('/[^\w\s]/', '', $normalized);

        return $normalized;
    }
}
```

**Step 4: Run tests and verify**

Run: `php vendor/bin/phpunit tests/Unit/Services/Cache/QueryResultCacheTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Services/Cache/QueryResultCache.php tests/Unit/Services/Cache/QueryResultCacheTest.php
git commit -m "feat: add QueryResultCache for caching context and query results"
```

---

### Task 5: Add Clarification Questions for Ambiguous Queries

**Files:**
- Create: `src/Services/Chat/AmbiguityDetector.php`
- Modify: `src/Services/Chat/AiChatService.php`
- Test: `tests/Unit/Services/Chat/AmbiguityDetectorTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/Services/Chat/AmbiguityDetectorTest.php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Services\Chat\AmbiguityDetector;
use Condoedge\Ai\Tests\TestCase;

class AmbiguityDetectorTest extends TestCase
{
    private AmbiguityDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AmbiguityDetector();
    }

    /** @test */
    public function it_detects_vague_questions(): void
    {
        $result = $this->detector->analyze('Show me data');

        $this->assertTrue($result['is_ambiguous']);
        $this->assertNotEmpty($result['clarification_questions']);
    }

    /** @test */
    public function it_passes_specific_questions(): void
    {
        $result = $this->detector->analyze('How many active customers do we have?');

        $this->assertFalse($result['is_ambiguous']);
    }

    /** @test */
    public function it_suggests_entity_clarification(): void
    {
        $result = $this->detector->analyze(
            'Show me the list',
            ['Customer', 'Order', 'Product'] // Available entities
        );

        $this->assertTrue($result['is_ambiguous']);
        $this->assertStringContainsString('Customer', $result['clarification_questions'][0]);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Services/Chat/AmbiguityDetectorTest.php -v`
Expected: FAIL

**Step 3: Write minimal implementation**

```php
<?php
// src/Services/Chat/AmbiguityDetector.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

class AmbiguityDetector
{
    private array $vagueTerms = [
        'data', 'stuff', 'things', 'info', 'information',
        'list', 'records', 'items', 'results', 'it', 'them',
    ];

    private array $specificIndicators = [
        'how many', 'count', 'total', 'sum', 'average',
        'customers', 'orders', 'products', 'users', 'teams',
        'active', 'pending', 'completed', 'recent', 'last',
        'by', 'grouped', 'sorted', 'filtered', 'where',
    ];

    public function analyze(string $question, array $availableEntities = []): array
    {
        $questionLower = strtolower($question);
        $isAmbiguous = false;
        $clarificationQuestions = [];
        $reasons = [];

        // Check for vague terms without specific context
        $hasVagueTerms = $this->hasVagueTerms($questionLower);
        $hasSpecificIndicators = $this->hasSpecificIndicators($questionLower);

        if ($hasVagueTerms && !$hasSpecificIndicators) {
            $isAmbiguous = true;
            $reasons[] = 'Question contains vague terms without specifics';

            // Suggest entity clarification if we know available entities
            if (!empty($availableEntities)) {
                $entityList = implode(', ', array_slice($availableEntities, 0, 5));
                $clarificationQuestions[] = "Which type of data are you interested in? ({$entityList})";
            } else {
                $clarificationQuestions[] = 'What specific information are you looking for?';
            }
        }

        // Check if question is too short (likely incomplete)
        if (str_word_count($question) < 3) {
            $isAmbiguous = true;
            $reasons[] = 'Question is very short';
            $clarificationQuestions[] = 'Could you provide more details about what you\'re looking for?';
        }

        // Check for missing entity reference
        if (!$this->hasEntityReference($questionLower, $availableEntities) && !empty($availableEntities)) {
            $isAmbiguous = true;
            $reasons[] = 'No clear entity reference found';
            $entityList = implode(', ', array_slice($availableEntities, 0, 5));
            $clarificationQuestions[] = "Which entity are you asking about? ({$entityList})";
        }

        return [
            'is_ambiguous' => $isAmbiguous,
            'confidence' => $isAmbiguous ? 0.4 : 0.9,
            'reasons' => $reasons,
            'clarification_questions' => array_unique($clarificationQuestions),
        ];
    }

    private function hasVagueTerms(string $question): bool
    {
        foreach ($this->vagueTerms as $term) {
            if (str_contains($question, $term)) {
                return true;
            }
        }
        return false;
    }

    private function hasSpecificIndicators(string $question): bool
    {
        $count = 0;
        foreach ($this->specificIndicators as $indicator) {
            if (str_contains($question, $indicator)) {
                $count++;
            }
        }
        return $count >= 2; // Need at least 2 specific indicators
    }

    private function hasEntityReference(string $question, array $entities): bool
    {
        foreach ($entities as $entity) {
            $entityLower = strtolower($entity);
            $entityPlural = $entityLower . 's';
            if (str_contains($question, $entityLower) || str_contains($question, $entityPlural)) {
                return true;
            }
        }
        return false;
    }
}
```

**Step 4: Run tests and verify**

Run: `php vendor/bin/phpunit tests/Unit/Services/Chat/AmbiguityDetectorTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Services/Chat/AmbiguityDetector.php tests/Unit/Services/Chat/AmbiguityDetectorTest.php
git commit -m "feat: add AmbiguityDetector for clarification questions"
```

---

## Phase 3: Production Readiness (Observability)

### Task 6: Add Health Check Endpoint

**Files:**
- Create: `src/Http/Controllers/HealthController.php`
- Create: `routes/api.php`
- Test: `tests/Feature/HealthEndpointTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Feature/HealthEndpointTest.php

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    /** @test */
    public function it_returns_health_status(): void
    {
        $response = $this->getJson('/api/ai/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'services' => [
                    'neo4j',
                    'qdrant',
                    'llm',
                ],
                'timestamp',
            ]);
    }

    /** @test */
    public function it_returns_503_when_services_down(): void
    {
        // Mock failing services
        config(['ai.neo4j.host' => 'invalid-host']);

        $response = $this->getJson('/api/ai/health');

        $response->assertStatus(503);
    }
}
```

**Step 2: Write implementation**

```php
<?php
// src/Http/Controllers/HealthController.php

declare(strict_types=1);

namespace Condoedge\Ai\Http\Controllers;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\Resilience\CircuitBreaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $services = [];
        $healthy = true;

        // Check Neo4j
        try {
            $graphStore = app(GraphStoreInterface::class);
            $graphStore->getSchema();
            $services['neo4j'] = ['status' => 'healthy'];
        } catch (\Exception $e) {
            $services['neo4j'] = ['status' => 'unhealthy', 'error' => $e->getMessage()];
            $healthy = false;
        }

        // Check Qdrant
        try {
            $vectorStore = app(VectorStoreInterface::class);
            $vectorStore->listCollections();
            $services['qdrant'] = ['status' => 'healthy'];
        } catch (\Exception $e) {
            $services['qdrant'] = ['status' => 'unhealthy', 'error' => $e->getMessage()];
            $healthy = false;
        }

        // Check LLM API key configured
        $llmConfigured = config('ai.llm.default') && config('ai.llm.' . config('ai.llm.default') . '.api_key');
        $services['llm'] = ['status' => $llmConfigured ? 'configured' : 'not_configured'];
        if (!$llmConfigured) {
            $healthy = false;
        }

        // Check circuit breakers
        $services['circuit_breakers'] = $this->getCircuitBreakerStatus();

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'services' => $services,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function getCircuitBreakerStatus(): array
    {
        $breakers = ['neo4j', 'qdrant', 'llm'];
        $status = [];

        foreach ($breakers as $name) {
            try {
                $breaker = app(CircuitBreaker::class);
                $state = $breaker->getState($name);
                $status[$name] = [
                    'state' => $state,
                    'failure_count' => $breaker->getFailureCount($name),
                ];
            } catch (\Exception $e) {
                $status[$name] = ['state' => 'unknown'];
            }
        }

        return $status;
    }
}
```

**Step 3: Add route**

```php
// routes/api.php
Route::prefix('ai')->group(function () {
    Route::get('health', \Condoedge\Ai\Http\Controllers\HealthController::class);
});
```

**Step 4: Commit**

```bash
git add src/Http/Controllers/HealthController.php routes/api.php tests/Feature/HealthEndpointTest.php
git commit -m "feat: add /api/ai/health endpoint for service monitoring"
```

---

### Task 7: Add Query Analytics & Audit Logging

**Files:**
- Create: `database/migrations/2025_01_01_create_ai_query_logs_table.php`
- Create: `src/Models/AiQueryLog.php`
- Create: `src/Services/Analytics/QueryAnalytics.php`
- Modify: `src/Services/AiManager.php` (add logging)

**Step 1: Create migration**

```php
<?php
// database/migrations/2025_01_01_000002_create_ai_query_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_query_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('team_id')->nullable()->index();
            $table->foreignId('conversation_id')->nullable()->index();
            $table->text('question');
            $table->text('cypher_query')->nullable();
            $table->string('template_used')->nullable();
            $table->float('confidence_score')->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->integer('result_count')->nullable();
            $table->string('status'); // success, failed, timeout, rejected
            $table->text('error_message')->nullable();
            $table->json('context_stats')->nullable(); // tokens used, entities matched
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['template_used', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_query_logs');
    }
};
```

**Step 2: Create model and analytics service**

```php
<?php
// src/Models/AiQueryLog.php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;

class AiQueryLog extends Model
{
    protected $fillable = [
        'user_id', 'team_id', 'conversation_id',
        'question', 'cypher_query', 'template_used',
        'confidence_score', 'execution_time_ms', 'result_count',
        'status', 'error_message', 'context_stats', 'metadata',
    ];

    protected $casts = [
        'context_stats' => 'array',
        'metadata' => 'array',
        'confidence_score' => 'float',
    ];

    public static function logSuccess(array $data): self
    {
        return self::create(array_merge($data, ['status' => 'success']));
    }

    public static function logFailure(array $data, string $error): self
    {
        return self::create(array_merge($data, [
            'status' => 'failed',
            'error_message' => $error,
        ]));
    }
}
```

```php
<?php
// src/Services/Analytics/QueryAnalytics.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Analytics;

use Condoedge\Ai\Models\AiQueryLog;
use Illuminate\Support\Facades\DB;

class QueryAnalytics
{
    public function getSuccessRate(int $days = 7): float
    {
        $stats = AiQueryLog::where('created_at', '>=', now()->subDays($days))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as successful')
            ->first();

        return $stats->total > 0 ? ($stats->successful / $stats->total) * 100 : 0;
    }

    public function getAverageExecutionTime(int $days = 7): float
    {
        return AiQueryLog::where('created_at', '>=', now()->subDays($days))
            ->where('status', 'success')
            ->avg('execution_time_ms') ?? 0;
    }

    public function getMostFailedQuestions(int $limit = 10): array
    {
        return AiQueryLog::where('status', 'failed')
            ->select('question', DB::raw('COUNT(*) as failure_count'))
            ->groupBy('question')
            ->orderByDesc('failure_count')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function getTemplateUsage(int $days = 7): array
    {
        return AiQueryLog::where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('template_used')
            ->select('template_used', DB::raw('COUNT(*) as count'))
            ->groupBy('template_used')
            ->orderByDesc('count')
            ->get()
            ->toArray();
    }

    public function getDashboardStats(): array
    {
        return [
            'success_rate_7d' => $this->getSuccessRate(7),
            'avg_execution_time_7d' => $this->getAverageExecutionTime(7),
            'total_queries_today' => AiQueryLog::whereDate('created_at', today())->count(),
            'failed_queries_today' => AiQueryLog::whereDate('created_at', today())
                ->where('status', 'failed')
                ->count(),
        ];
    }
}
```

**Step 3: Commit**

```bash
git add database/migrations/ src/Models/AiQueryLog.php src/Services/Analytics/QueryAnalytics.php
git commit -m "feat: add query analytics and audit logging"
```

---

## Phase 4: Advanced Features

### Task 8: Add Relationship Importance Weights

**Files:**
- Modify: `config/ai.php` (add relationship_weights config)
- Modify: `src/Services/ContextRetriever.php` (use weights in filtering)

**Step 1: Add configuration**

```php
// Add to config/ai.php
'relationship_weights' => [
    // High importance - direct business relationships
    'PURCHASED' => 1.0,
    'BELONGS_TO' => 0.9,
    'MEMBER_OF' => 0.9,
    'CREATED_BY' => 0.8,
    'OWNS' => 0.8,

    // Medium importance - secondary relationships
    'RELATED_TO' => 0.6,
    'TAGGED_WITH' => 0.5,
    'CATEGORIZED_AS' => 0.5,

    // Low importance - metadata relationships
    'VIEWED' => 0.3,
    'LOGGED' => 0.2,

    // Default for unspecified relationships
    'default' => 0.5,
],
```

**Step 2: Use weights in ContextRetriever**

Add method to `ContextRetriever.php`:

```php
private function getRelationshipWeight(string $relationshipType): float
{
    $weights = config('ai.relationship_weights', []);
    return $weights[$relationshipType] ?? $weights['default'] ?? 0.5;
}

private function filterRelationshipsByImportance(array $relationships, float $threshold = 0.5): array
{
    return array_filter($relationships, function ($rel) use ($threshold) {
        $type = is_array($rel) ? ($rel['type'] ?? '') : $rel;
        return $this->getRelationshipWeight($type) >= $threshold;
    });
}
```

**Step 3: Commit**

```bash
git add config/ai.php src/Services/ContextRetriever.php
git commit -m "feat: add relationship importance weights for smarter context selection"
```

---

### Task 9: Add Smart Query Learning (Learn from Successful Queries)

**Files:**
- Create: `src/Services/Learning/QueryLearner.php`
- Create: `src/Console/Commands/LearnFromLogsCommand.php`

**Step 1: Create QueryLearner service**

```php
<?php
// src/Services/Learning/QueryLearner.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Learning;

use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Models\AiQueryLog;

class QueryLearner
{
    private const COLLECTION = 'learned_queries';

    public function __construct(
        private VectorStoreInterface $vectorStore,
        private EmbeddingProviderInterface $embeddingProvider
    ) {}

    /**
     * Learn from successful queries in the logs
     */
    public function learnFromLogs(int $minConfidence = 80, int $limit = 100): array
    {
        $successfulQueries = AiQueryLog::where('status', 'success')
            ->where('confidence_score', '>=', $minConfidence / 100)
            ->whereNotNull('cypher_query')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $learned = 0;
        $skipped = 0;

        foreach ($successfulQueries as $log) {
            // Check if similar query already learned
            if ($this->isAlreadyLearned($log->question)) {
                $skipped++;
                continue;
            }

            // Add to learned queries collection
            $this->addLearnedQuery(
                $log->question,
                $log->cypher_query,
                [
                    'template' => $log->template_used,
                    'confidence' => $log->confidence_score,
                    'learned_from_log_id' => $log->id,
                ]
            );
            $learned++;
        }

        return [
            'processed' => $successfulQueries->count(),
            'learned' => $learned,
            'skipped' => $skipped,
        ];
    }

    public function addLearnedQuery(string $question, string $cypherQuery, array $metadata = []): void
    {
        $embedding = $this->embeddingProvider->embed($question);

        $this->vectorStore->upsert(self::COLLECTION, [[
            'id' => md5($question),
            'vector' => $embedding,
            'payload' => array_merge($metadata, [
                'question' => $question,
                'cypher_query' => $cypherQuery,
                'learned_at' => now()->toIso8601String(),
            ]),
        ]]);
    }

    public function findSimilarLearnedQuery(string $question, float $threshold = 0.85): ?array
    {
        $embedding = $this->embeddingProvider->embed($question);

        $results = $this->vectorStore->search(
            self::COLLECTION,
            $embedding,
            limit: 1,
            filter: [],
            scoreThreshold: $threshold
        );

        if (empty($results)) {
            return null;
        }

        return [
            'question' => $results[0]['payload']['question'],
            'cypher_query' => $results[0]['payload']['cypher_query'],
            'score' => $results[0]['score'],
            'metadata' => $results[0]['payload'],
        ];
    }

    private function isAlreadyLearned(string $question): bool
    {
        return $this->findSimilarLearnedQuery($question, 0.95) !== null;
    }
}
```

**Step 2: Create command**

```php
<?php
// src/Console/Commands/LearnFromLogsCommand.php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Services\Learning\QueryLearner;
use Illuminate\Console\Command;

class LearnFromLogsCommand extends Command
{
    protected $signature = 'ai:learn
                            {--min-confidence=80 : Minimum confidence score to learn from}
                            {--limit=100 : Maximum queries to process}';

    protected $description = 'Learn from successful queries in the logs';

    public function handle(QueryLearner $learner): int
    {
        $this->info('Learning from successful queries...');

        $result = $learner->learnFromLogs(
            minConfidence: (int) $this->option('min-confidence'),
            limit: (int) $this->option('limit')
        );

        $this->info("Processed: {$result['processed']}");
        $this->info("Learned: {$result['learned']}");
        $this->info("Skipped (already known): {$result['skipped']}");

        return self::SUCCESS;
    }
}
```

**Step 3: Commit**

```bash
git add src/Services/Learning/ src/Console/Commands/LearnFromLogsCommand.php
git commit -m "feat: add smart query learning from successful queries"
```

---

## Summary: Architecture After Improvements

```
┌─────────────────────────────────────────────────────────────────┐
│                         AI Chat System                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  User Question                                                  │
│       │                                                         │
│       ▼                                                         │
│  ┌──────────────────┐     ┌──────────────────┐                 │
│  │ AmbiguityDetector │────▶│ Clarification?   │                 │
│  └──────────────────┘     └──────────────────┘                 │
│       │                                                         │
│       ▼ (if clear)                                              │
│  ┌──────────────────┐     ┌──────────────────┐                 │
│  │ QueryResultCache │────▶│ Cache Hit?       │────▶ Return     │
│  └──────────────────┘     └──────────────────┘                 │
│       │                                                         │
│       ▼ (cache miss)                                            │
│  ┌──────────────────┐     ┌──────────────────┐                 │
│  │  QueryLearner    │────▶│ Learned Query?   │────▶ Use it     │
│  └──────────────────┘     └──────────────────┘                 │
│       │                                                         │
│       ▼ (no learned query)                                      │
│  ┌──────────────────────────────────────────────────────┐      │
│  │                   RAG Pipeline                        │      │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐   │      │
│  │  │ Qdrant      │  │ Neo4j       │  │ Entity      │   │      │
│  │  │ (vectors)   │  │ (schema)    │  │ Metadata    │   │      │
│  │  └─────────────┘  └─────────────┘  └─────────────┘   │      │
│  │         │                │                │          │      │
│  │         └────────────────┼────────────────┘          │      │
│  │                          ▼                           │      │
│  │              SemanticContextSelector                 │      │
│  │              (weighted relationships)                │      │
│  └──────────────────────────────────────────────────────┘      │
│       │                                                         │
│       ▼                                                         │
│  ┌──────────────────┐                                          │
│  │  Query Generator │────▶ Cypher Query                        │
│  └──────────────────┘                                          │
│       │                                                         │
│       ▼                                                         │
│  ┌──────────────────┐     ┌──────────────────┐                 │
│  │   Neo4j Store    │     │   AiQueryLog     │ (audit)         │
│  └──────────────────┘     └──────────────────┘                 │
│       │                                                         │
│       ▼                                                         │
│  ┌──────────────────┐     ┌──────────────────┐                 │
│  │ResponseGenerator │     │ AiConversation   │ (persist)       │
│  └──────────────────┘     └──────────────────┘                 │
│       │                                                         │
│       ▼                                                         │
│  Natural Language Answer + Suggestions                          │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Execution Order

1. **Phase 1 (Developer Experience):** Tasks 1-2 - Make it easy to adopt
2. **Phase 2 (Conversation Quality):** Tasks 3-5 - Improve answer quality
3. **Phase 3 (Production Readiness):** Tasks 6-7 - Add observability
4. **Phase 4 (Advanced):** Tasks 8-9 - Power features

---

## Is Neo4j + Qdrant Enough?

**Yes, for most cases.** The current architecture is solid:

- **Neo4j**: Excellent for relationship queries, multi-hop reasoning
- **Qdrant**: Great for semantic search, RAG context

**Consider adding later:**

1. **Redis**: For session/cache management (conversation state, query caching)
2. **Knowledge Graph Ontology**: For complex reasoning (OWL/RDF if needed)
3. **LangGraph**: For multi-step agent workflows (if questions get complex)

**Don't add prematurely:**
- The improvements above will solve 90% of quality issues
- Additional stores add operational complexity
- Start simple, add when you hit limits
