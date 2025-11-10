<?php

require_once __DIR__ . '/vendor/autoload.php';

use AiSystem\Services\ResponseGenerator;
use AiSystem\Contracts\LlmProviderInterface;

echo "=== ResponseGenerator Manual Tests ===\n\n";

// Create mock LLM
$llm = new class implements LlmProviderInterface {
    public function chat(string|array $input, array $options = []): string
    {
        return "Mock chat response";
    }

    public function chatJson(string|array $input, array $options = []): object|array
    {
        return ['result' => 'mock'];
    }

    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        // Simulate generating a response based on the prompt
        if (stripos($prompt, 'No results') !== false || stripos($prompt, 'returned no results') !== false) {
            return "No results were found for your query. Please try a different search or broaden your criteria.";
        }

        if (stripos($prompt, 'error') !== false) {
            return "An error occurred while processing your request.";
        }

        return "Based on the query results, there are 42 customers in total. This data shows a healthy customer base with diverse order patterns.";
    }

    public function stream(array $messages, callable $callback, array $options = []): void
    {
        $callback("Mock stream response");
    }

    public function getModel(): string
    {
        return "mock-model";
    }

    public function getProvider(): string
    {
        return "mock-provider";
    }

    public function getMaxTokens(): int
    {
        return 2000;
    }

    public function countTokens(string $text): int
    {
        return str_word_count($text);
    }
};

// Create ResponseGenerator
$config = [
    'default_format' => 'text',
    'default_style' => 'detailed',
    'default_max_length' => 200,
    'temperature' => 0.3,
    'include_insights' => true,
    'include_visualizations' => true,
    'summarize_threshold' => 10,
];

$generator = new ResponseGenerator($llm, $config);

// Test 1: Generate response from query results
echo "Test 1: Generate response from query results\n";
try {
    $queryResult = [
        'data' => [
            ['count' => 42]
        ],
        'stats' => [
            'execution_time_ms' => 15,
            'rows_returned' => 1
        ]
    ];

    $result = $generator->generate(
        "How many customers do we have?",
        $queryResult,
        "MATCH (c:Customer) RETURN count(c) as count"
    );

    echo "✓ Generated response:\n";
    echo "  Answer: " . $result['answer'] . "\n";
    echo "  Format: " . $result['format'] . "\n";
    echo "  Insights: " . count($result['insights']) . "\n";
    echo "  Visualizations: " . count($result['visualizations']) . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 2: Empty response
echo "Test 2: Generate empty response\n";
try {
    $result = $generator->generateEmptyResponse(
        "Find customers named Zzzz",
        "MATCH (c:Customer {name: 'Zzzz'}) RETURN c"
    );

    echo "✓ Generated empty response:\n";
    echo "  Answer: " . substr($result['answer'], 0, 50) . "...\n";
    echo "  Empty result: " . ($result['metadata']['empty_result'] ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 3: Error response
echo "Test 3: Generate error response\n";
try {
    $error = new RuntimeException("Query timeout exceeded");
    $result = $generator->generateErrorResponse(
        "Complex query",
        $error
    );

    echo "✓ Generated error response:\n";
    echo "  Answer: " . substr($result['answer'], 0, 50) . "...\n";
    echo "  Error type: " . $result['metadata']['error_type'] . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 4: Summarize data
echo "Test 4: Summarize large dataset\n";
try {
    $data = [];
    for ($i = 1; $i <= 20; $i++) {
        $data[] = ['id' => $i, 'name' => "Item $i"];
    }

    $summarized = $generator->summarize($data, 10);
    echo "✓ Summarized from " . count($data) . " to " . count($summarized) . " items\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 5: Extract insights
echo "Test 5: Extract insights from numeric data\n";
try {
    $data = [
        ['value' => 10],
        ['value' => 20],
        ['value' => 30],
        ['value' => 100] // Outlier
    ];

    $insights = $generator->extractInsights($data);
    echo "✓ Extracted " . count($insights) . " insights:\n";
    foreach ($insights as $insight) {
        echo "  - $insight\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 6: Suggest visualizations for count query
echo "Test 6: Suggest visualizations for count query\n";
try {
    $data = [['count' => 42]];
    $cypher = "MATCH (n:Customer) RETURN count(n) as count";

    $suggestions = $generator->suggestVisualizations($data, $cypher);
    echo "✓ Generated " . count($suggestions) . " visualization suggestions:\n";
    foreach ($suggestions as $suggestion) {
        echo "  - {$suggestion['type']}: {$suggestion['rationale']}\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 7: Suggest visualizations for relationship query
echo "Test 7: Suggest visualizations for relationship query\n";
try {
    $data = [
        ['customer' => 'Alice', 'order' => 'Order1'],
        ['customer' => 'Bob', 'order' => 'Order2']
    ];
    $cypher = "MATCH (c:Customer)-[:PLACED]->(o:Order) RETURN c.name, o.id";

    $suggestions = $generator->suggestVisualizations($data, $cypher);
    echo "✓ Generated " . count($suggestions) . " visualization suggestions:\n";
    foreach ($suggestions as $suggestion) {
        echo "  - {$suggestion['type']}: {$suggestion['rationale']}\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 8: Time series detection
echo "Test 8: Detect time series data\n";
try {
    $data = [
        ['date' => '2024-01-01', 'count' => 10],
        ['date' => '2024-01-02', 'count' => 15]
    ];
    $cypher = "MATCH (o:Order) RETURN o.date, count(o)";

    $suggestions = $generator->suggestVisualizations($data, $cypher);
    echo "✓ Generated " . count($suggestions) . " visualization suggestions:\n";
    foreach ($suggestions as $suggestion) {
        echo "  - {$suggestion['type']}: {$suggestion['rationale']}\n";
    }

    $hasLineChart = false;
    foreach ($suggestions as $s) {
        if ($s['type'] === 'line-chart') {
            $hasLineChart = true;
            break;
        }
    }
    echo "  Line chart suggested: " . ($hasLineChart ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 9: Response with options
echo "Test 9: Generate response with custom options\n";
try {
    $queryResult = [
        'data' => [
            ['name' => 'Alice', 'orders' => 10],
            ['name' => 'Bob', 'orders' => 8]
        ]
    ];

    $result = $generator->generate(
        "Show top customers",
        $queryResult,
        "MATCH (c:Customer) RETURN c.name, c.orders ORDER BY c.orders DESC",
        [
            'format' => 'markdown',
            'style' => 'concise',
            'max_length' => 50,
            'temperature' => 0.5
        ]
    );

    echo "✓ Generated response with options:\n";
    echo "  Format: " . $result['format'] . "\n";
    echo "  Style: " . $result['metadata']['style'] . "\n";
    echo "  Result count: " . $result['metadata']['result_count'] . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

echo "=== All Manual Tests Completed ===\n";
