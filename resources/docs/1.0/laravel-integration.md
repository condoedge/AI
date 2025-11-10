# Laravel Integration

Complete guide to integrating the AI system with Laravel applications.

---

## Service Provider

The `AiServiceProvider` automatically registers all services in Laravel's container.

### Registration

Add to `config/app.php`:

```php
'providers' => [
    // ...
    AiSystem\AiServiceProvider::class,
],
```

> **Note:** Laravel 11+ auto-discovers providers automatically.

---

## Usage Approaches

### 1. Using AI Facade (Simplest)

```php
use AiSystem\Facades\AI;

class CustomerController extends Controller
{
    public function store(Request $request)
    {
        $customer = Customer::create($request->validated());

        // Simple facade call
        $status = AI::ingest($customer);

        return response()->json([
            'customer' => $customer,
            'ai_status' => $status
        ]);
    }

    public function search(Request $request)
    {
        $question = $request->input('question');

        // Retrieve context and generate response
        $context = AI::retrieveContext($question);
        $response = AI::chat("Answer this: {$question}");

        return response()->json([
            'answer' => $response,
            'context' => $context
        ]);
    }
}
```

### 2. Using AiManager with Dependency Injection (Recommended)

```php
use AiSystem\Services\AiManager;

class CustomerController extends Controller
{
    public function __construct(private AiManager $ai) {}

    public function store(Request $request)
    {
        $customer = Customer::create($request->validated());

        // Use injected AiManager
        $status = $this->ai->ingest($customer);

        return response()->json([
            'customer' => $customer,
            'ai_status' => $status
        ], 201);
    }

    public function search(Request $request)
    {
        $question = $request->input('question');

        $context = $this->ai->retrieveContext($question, [
            'collection' => 'customers',
            'limit' => 10
        ]);

        return response()->json($context);
    }

    public function ask(Request $request)
    {
        $question = $request->input('question');

        // Retrieve context
        $context = $this->ai->retrieveContext($question);

        // Build prompt
        $prompt = $this->buildPrompt($question, $context);

        // Generate answer
        $answer = $this->ai->chat($prompt);

        return response()->json([
            'question' => $question,
            'answer' => $answer,
            'context' => $context
        ]);
    }

    private function buildPrompt(string $question, array $context): string
    {
        return sprintf(
            "Question: %s\n\nContext: %s\n\nProvide a helpful answer.",
            $question,
            json_encode($context['similar_queries'])
        );
    }
}
```

### 3. Using Individual Services (Maximum Control)

```php
use AiSystem\Contracts\DataIngestionServiceInterface;
use AiSystem\Contracts\ContextRetrieverInterface;
use AiSystem\Contracts\LlmProviderInterface;

class CustomerController extends Controller
{
    public function __construct(
        private DataIngestionServiceInterface $ingestion,
        private ContextRetrieverInterface $context,
        private LlmProviderInterface $llm
    ) {}

    public function store(Request $request)
    {
        $customer = Customer::create($request->validated());
        $status = $this->ingestion->ingest($customer);

        return response()->json([
            'customer' => $customer,
            'ai_status' => $status
        ]);
    }

    public function search(Request $request)
    {
        $question = $request->input('question');
        $context = $this->context->retrieveContext($question);

        return response()->json($context);
    }

    public function ask(Request $request)
    {
        $question = $request->input('question');

        $context = $this->context->retrieveContext($question);
        $answer = $this->llm->chat([
            ['role' => 'user', 'content' => $question]
        ]);

        return response()->json([
            'answer' => $answer,
            'context' => $context
        ]);
    }
}
```

---

## Model Observers

Automatically sync entities when they change:

### Using AI Facade

```php
use AiSystem\Facades\AI;
use App\Models\Customer;

class CustomerObserver
{
    public function created(Customer $customer)
    {
        AI::ingest($customer);
    }

    public function updated(Customer $customer)
    {
        AI::sync($customer);
    }

    public function deleted(Customer $customer)
    {
        AI::remove($customer);
    }
}
```

### Using Dependency Injection (Better for Testing)

```php
use AiSystem\Services\AiManager;
use App\Models\Customer;

class CustomerObserver
{
    public function __construct(private AiManager $ai) {}

    public function created(Customer $customer)
    {
        $this->ai->ingest($customer);
    }

    public function updated(Customer $customer)
    {
        $this->ai->sync($customer);
    }

    public function deleted(Customer $customer)
    {
        $this->ai->remove($customer);
    }
}
```

### Register Observer

In `AppServiceProvider`:

```php
use App\Models\Customer;
use App\Observers\CustomerObserver;

public function boot()
{
    Customer::observe(CustomerObserver::class);
}
```

---

## Artisan Commands

### Batch Ingest Command

```php
use Illuminate\Console\Command;
use AiSystem\Facades\AI;
use App\Models\Customer;

class IngestCustomersCommand extends Command
{
    protected $signature = 'ai:ingest-customers';
    protected $description = 'Ingest all customers into AI system';

    public function handle()
    {
        $customers = Customer::all();
        $this->info("Ingesting {$customers->count()} customers...");

        $result = AI::ingestBatch($customers->toArray());

        $this->info("✓ Succeeded: {$result['succeeded']}");
        $this->info("✓ Partially: {$result['partially_succeeded']}");
        $this->info("✗ Failed: {$result['failed']}");

        if (!empty($result['errors'])) {
            $this->error('Errors occurred:');
            foreach ($result['errors'] as $id => $errors) {
                $this->error("  Entity {$id}: " . implode(', ', $errors));
            }
        }

        return 0;
    }
}
```

### With Dependency Injection

```php
use Illuminate\Console\Command;
use AiSystem\Services\AiManager;
use App\Models\Customer;

class IngestCustomersCommand extends Command
{
    protected $signature = 'ai:ingest-customers';
    protected $description = 'Ingest all customers into AI system';

    public function __construct(private AiManager $ai)
    {
        parent::__construct();
    }

    public function handle()
    {
        $customers = Customer::all();
        $this->info("Ingesting {$customers->count()} customers...");

        $result = $this->ai->ingestBatch($customers->toArray());

        $this->info("✓ Succeeded: {$result['succeeded']}");
        $this->info("✓ Partially: {$result['partially_succeeded']}");
        $this->info("✗ Failed: {$result['failed']}");

        if (!empty($result['errors'])) {
            $this->error('Errors occurred:');
            foreach ($result['errors'] as $id => $errors) {
                $this->error("  Entity {$id}: " . implode(', ', $errors));
            }
        }

        return 0;
    }
}
```

Register in `app/Console/Kernel.php`:

```php
protected $commands = [
    Commands\IngestCustomersCommand::class,
];
```

Run:

```bash
php artisan ai:ingest-customers
```

---

## Queue Integration

### Queued Ingestion

```php
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use AiSystem\Facades\AI;

class IngestEntityJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private Nodeable $entity
    ) {}

    public function handle()
    {
        $status = AI::ingest($this->entity);

        if (!empty($status['errors'])) {
            \Log::warning('Ingestion errors', $status['errors']);
        }
    }
}
```

Dispatch:

```php
IngestEntityJob::dispatch($customer);
```

---

## Middleware

### Protect Documentation Routes

Create middleware:

```php
namespace App\Http\Middleware;

class CheckAiDocsAccess
{
    public function handle($request, Closure $next)
    {
        if (!auth()->check()) {
            abort(403, 'Unauthorized access to AI documentation');
        }

        return $next($request);
    }
}
```

Configure in `config/ai.php`:

```php
'documentation' => [
    'enabled' => env('AI_DOCS_ENABLED', true),
    'route_prefix' => env('AI_DOCS_PREFIX', 'ai-docs'),
    'middleware' => ['web', 'auth'],  // Add auth middleware
],
```

---

## API Routes

```php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AiController;

Route::prefix('api/ai')->group(function () {
    Route::post('/ingest', [AiController::class, 'ingest']);
    Route::post('/search', [AiController::class, 'search']);
    Route::post('/ask', [AiController::class, 'ask']);
});
```

### AI Controller (Using Facade)

```php
use AiSystem\Facades\AI;

class AiController extends Controller
{
    public function ingest(Request $request)
    {
        $validated = $request->validate([
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer'
        ]);

        $model = "App\\Models\\{$validated['entity_type']}";
        $entity = $model::findOrFail($validated['entity_id']);

        $status = AI::ingest($entity);

        return response()->json($status);
    }

    public function search(Request $request)
    {
        $question = $request->validate([
            'question' => 'required|string'
        ])['question'];

        $similar = AI::searchSimilar($question, [
            'collection' => 'entities',
            'limit' => 10
        ]);

        return response()->json($similar);
    }

    public function ask(Request $request)
    {
        $question = $request->validate([
            'question' => 'required|string'
        ])['question'];

        $context = AI::retrieveContext($question);
        $response = AI::chat($question);

        return response()->json([
            'question' => $question,
            'answer' => $response,
            'context' => $context
        ]);
    }
}
```

---

## Event Listeners

### Listen for Model Events

```php
use Illuminate\Support\Facades\Event;
use App\Events\CustomerCreated;
use AiSystem\Facades\AI;

Event::listen(CustomerCreated::class, function ($event) {
    AI::ingest($event->customer);
});
```

---

## Testing

### Feature Tests with Facade Mocking

```php
use Tests\TestCase;
use AiSystem\Facades\AI;
use App\Models\Customer;

class CustomerAiTest extends TestCase
{
    public function test_customer_ingestion()
    {
        // Mock the facade
        AI::shouldReceive('ingest')
            ->once()
            ->andReturn([
                'graph_stored' => true,
                'vector_stored' => true,
                'relationships_created' => 1,
                'errors' => []
            ]);

        $customer = Customer::factory()->create();

        // Your code that uses AI::ingest()
        $service = new CustomerService();
        $result = $service->processCustomer($customer);

        $this->assertTrue($result['success']);
    }

    public function test_search_endpoint()
    {
        AI::shouldReceive('searchSimilar')
            ->once()
            ->andReturn([
                ['question' => 'Test', 'score' => 0.9, 'metadata' => []]
            ]);

        $response = $this->postJson('/api/ai/search', [
            'question' => 'Show all customers'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => ['question', 'score', 'metadata']
        ]);
    }
}
```

---

## Blade Integration

### Display AI-Powered Search

```blade
<form action="{{ route('ai.search') }}" method="POST">
    @csrf
    <input type="text" name="question" placeholder="Ask a question...">
    <button type="submit">Search</button>
</form>

@if(isset($results))
    <div class="results">
        @foreach($results as $result)
            <div class="result">
                <strong>{{ $result['metadata']['name'] }}</strong>
                <span>Score: {{ $result['score'] }}</span>
            </div>
        @endforeach
    </div>
@endif
```

---

See also: [Simple Usage](/docs/{{version}}/simple-usage) | [Testing](/docs/{{version}}/testing) | [Examples](/docs/{{version}}/examples)
