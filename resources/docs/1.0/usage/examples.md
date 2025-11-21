# Real-World Examples

Complete, production-ready examples demonstrating the AI Text-to-Query System.

---

## Example 1: Customer Search with Semantic Search

Build a semantic customer search that understands meaning, not just keywords.

### Entity Setup

```php
// app/Models/Customer.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'company', 'industry', 'notes'];

    public function getId(): string|int
    {
        return $this->id;
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
```

### Configuration

```php
// config/entities.php
return [
    'Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => ['id', 'name', 'email', 'company', 'industry'],
            'relationships' => [
                [
                    'type' => 'PURCHASED',
                    'target_label' => 'Order',
                    'foreign_key' => 'order_id'
                ]
            ]
        ],
        'vector' => [
            'collection' => 'customers',
            'embed_fields' => ['name', 'company', 'industry', 'notes'],
            'metadata' => ['id', 'email', 'company', 'industry']
        ]
    ]
];
```

### Controller

```php
// app/Http/Controllers/CustomerSearchController.php
namespace App\Http\Controllers;

use Condoedge\Ai\Facades\AI;
use Illuminate\Http\Request;

class CustomerSearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->input('query');

        // Semantic search using AI
        $results = AI::searchSimilar($query, [
            'collection' => 'customers',
            'limit' => 10,
            'scoreThreshold' => 0.7
        ]);

        return view('customers.search', [
            'query' => $query,
            'results' => $results
        ]);
    }

    public function ingestAll()
    {
        $customers = Customer::all();

        $result = AI::ingestBatch($customers->toArray());

        return response()->json([
            'message' => "Ingested {$result['succeeded']} customers",
            'details' => $result
        ]);
    }
}
```

### View

```blade
<!-- resources/views/customers/search.blade.php -->
<form action="{{ route('customers.search') }}" method="GET">
    <input type="text" name="query" value="{{ $query }}"
           placeholder="Search customers (e.g., 'software companies in tech')">
    <button type="submit">Search</button>
</form>

@foreach($results as $result)
    <div class="customer-result">
        <h3>{{ $result['metadata']['company'] }}</h3>
        <p>Industry: {{ $result['metadata']['industry'] }}</p>
        <p>Email: {{ $result['metadata']['email'] }}</p>
        <span class="score">Relevance: {{ round($result['score'] * 100) }}%</span>
    </div>
@endforeach
```

---

## Example 2: AI-Powered Question Answering

Build a system that answers questions about your data using natural language.

### Controller

```php
namespace App\Http\Controllers;

use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\GraphStore\Neo4jStore;

class AiQuestionController extends Controller
{
    public function ask(Request $request)
    {
        $question = $request->input('question');

        // Step 1: Get context using RAG
        $context = AI::retrieveContext($question, [
            'collection' => 'questions',
            'limit' => 5,
            'includeSchema' => true,
            'includeExamples' => true
        ]);

        // Step 2: Build prompt for LLM
        $systemPrompt = "You are a Cypher query expert. Generate ONLY valid Neo4j Cypher queries. Return JSON with 'query' and 'explanation' fields.";

        $userPrompt = $this->buildPrompt($question, $context);

        // Step 3: Generate Cypher query
        $llmResponse = AI::chatJson($userPrompt, ['temperature' => 0.2]);

        $cypherQuery = $llmResponse->query ?? '';
        $explanation = $llmResponse->explanation ?? '';

        // Step 4: Execute query (if safe)
        $results = [];
        $error = null;

        if ($this->isSafeQuery($cypherQuery)) {
            try {
                $neo4j = app(Neo4jStore::class);
                $results = $neo4j->query($cypherQuery);
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        // Step 5: Generate human-readable answer
        $answer = $this->generateAnswer($results, $question);

        return response()->json([
            'question' => $question,
            'answer' => $answer,
            'cypher_query' => $cypherQuery,
            'explanation' => $explanation,
            'results' => $results,
            'error' => $error,
            'context_used' => $context
        ]);
    }

    private function buildPrompt(string $question, array $context): string
    {
        return sprintf(
            "User Question: %s\n\nGraph Schema:\n%s\n\nSimilar Past Queries:\n%s\n\nGenerate a Cypher query to answer the question. Return JSON format.",
            $question,
            json_encode($context['graph_schema'], JSON_PRETTY_PRINT),
            json_encode($context['similar_queries'], JSON_PRETTY_PRINT)
        );
    }

    private function isSafeQuery(string $query): bool
    {
        // Basic safety checks
        $dangerous = ['DELETE', 'DROP', 'CREATE INDEX', 'REMOVE'];
        foreach ($dangerous as $keyword) {
            if (stripos($query, $keyword) !== false) {
                return false;
            }
        }
        return true;
    }

    private function generateAnswer(array $results, string $question): string
    {
        if (empty($results)) {
            return "I couldn't find any results for that question.";
        }

        $prompt = sprintf(
            "Convert these database results into a natural answer for the question.\n\nQuestion: %s\n\nResults: %s",
            $question,
            json_encode(array_slice($results, 0, 10))
        );

        return AI::complete($prompt, "You are a helpful assistant explaining data results.");
    }
}
```

---

## Example 3: Knowledge Graph Chatbot

Build an interactive chatbot that queries your knowledge graph.

### Service

```php
namespace App\Services;

use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\GraphStore\Neo4jStore;

class KnowledgeGraphChatbot
{
    private array $conversationHistory = [];

    public function __construct(
        private Neo4jStore $neo4j
    ) {}

    public function chat(string $userMessage): array
    {
        // Add user message to history
        $this->conversationHistory[] = [
            'role' => 'user',
            'content' => $userMessage
        ];

        // Determine if this is a data query or general conversation
        $intent = $this->detectIntent($userMessage);

        if ($intent === 'data_query') {
            $response = $this->handleDataQuery($userMessage);
        } else {
            $response = $this->handleConversation($userMessage);
        }

        // Add assistant response to history
        $this->conversationHistory[] = [
            'role' => 'assistant',
            'content' => $response['message']
        ];

        return $response;
    }

    private function detectIntent(string $message): string
    {
        $dataKeywords = ['show', 'find', 'list', 'who', 'what', 'how many', 'count'];

        foreach ($dataKeywords as $keyword) {
            if (stripos($message, $keyword) !== false) {
                return 'data_query';
            }
        }

        return 'conversation';
    }

    private function handleDataQuery(string $query): array
    {
        // Get context
        $context = AI::retrieveContext($query);

        // Generate Cypher
        $prompt = [
            ['role' => 'system', 'content' => 'Generate Cypher queries. Return JSON.'],
            ['role' => 'user', 'content' => $this->buildQueryPrompt($query, $context)]
        ];

        $llmResponse = AI::chatJson($prompt);
        $cypherQuery = $llmResponse->query ?? '';

        // Execute
        try {
            $results = $this->neo4j->query($cypherQuery);
            $message = $this->formatResults($results, $query);

            return [
                'type' => 'data_query',
                'message' => $message,
                'query' => $cypherQuery,
                'results' => $results
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'error',
                'message' => "I encountered an error: " . $e->getMessage()
            ];
        }
    }

    private function handleConversation(string $message): array
    {
        $response = AI::chat($this->conversationHistory);

        return [
            'type' => 'conversation',
            'message' => $response
        ];
    }

    private function buildQueryPrompt(string $query, array $context): string
    {
        return sprintf(
            "Question: %s\nSchema: %s\nGenerate Cypher query.",
            $query,
            json_encode($context['graph_schema'])
        );
    }

    private function formatResults(array $results, string $query): string
    {
        $prompt = sprintf(
            "Explain these results naturally.\nQuestion: %s\nResults: %s",
            $query,
            json_encode(array_slice($results, 0, 5))
        );

        return AI::complete($prompt);
    }

    public function getHistory(): array
    {
        return $this->conversationHistory;
    }
}
```

### Controller

```php
class ChatbotController extends Controller
{
    public function chat(Request $request)
    {
        $message = $request->input('message');

        $chatbot = new KnowledgeGraphChatbot(app(Neo4jStore::class));
        $response = $chatbot->chat($message);

        return response()->json($response);
    }
}
```

---

## Example 4: Automatic Entity Sync with Observers

Keep AI system in sync automatically.

### Observer

```php
namespace App\Observers;

use Condoedge\Ai\Facades\AI;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class CustomerObserver
{
    public function created(Customer $customer)
    {
        $status = AI::ingest($customer);

        if (!empty($status['errors'])) {
            Log::warning("Customer {$customer->id} ingestion errors", $status['errors']);
        }
    }

    public function updated(Customer $customer)
    {
        $status = AI::sync($customer);

        if (!empty($status['errors'])) {
            Log::warning("Customer {$customer->id} sync errors", $status['errors']);
        }
    }

    public function deleted(Customer $customer)
    {
        AI::remove($customer);
    }
}
```

### Register

```php
// app/Providers/AppServiceProvider.php
use App\Models\Customer;
use App\Observers\CustomerObserver;

public function boot()
{
    Customer::observe(CustomerObserver::class);
}
```

---

## Example 5: Batch Processing Command

Ingest large datasets efficiently.

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Condoedge\Ai\Facades\AI;
use App\Models\Customer;

class IngestAllCustomersCommand extends Command
{
    protected $signature = 'ai:ingest-all-customers {--batch-size=100}';
    protected $description = 'Ingest all customers into AI system';

    public function handle()
    {
        $batchSize = $this->option('batch-size');
        $total = Customer::count();

        $this->info("Ingesting {$total} customers in batches of {$batchSize}...");

        $bar = $this->output->createProgressBar($total);

        Customer::chunk($batchSize, function ($customers) use ($bar) {
            $result = AI::ingestBatch($customers->toArray());

            $this->line("\nBatch: {$result['succeeded']} succeeded, {$result['failed']} failed");

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $id => $errors) {
                    $this->error("  Customer {$id}: " . implode(', ', $errors));
                }
            }

            $bar->advance($customers->count());
        });

        $bar->finish();

        $this->newLine();
        $this->info('✓ Ingestion complete!');
    }
}
```

Run:

```bash
php artisan ai:ingest-all-customers --batch-size=50
```

---

## Best Practices Summary

1. **Use batch operations** for multiple entities
2. **Implement observers** for automatic sync
3. **Add error logging** for debugging
4. **Validate queries** before execution
5. **Cache contexts** when possible
6. **Use queues** for large operations
7. **Monitor API costs** (OpenAI/Anthropic)
8. **Test with small datasets** first

---

See also: [Simple Usage](/docs/{{version}}/usage/simple-usage) | [Laravel Integration](/docs/{{version}}/usage/laravel-integration) | [Testing](/docs/{{version}}/usage/testing)
