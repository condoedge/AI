<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Facades\AI;
use Illuminate\Console\Command;

class TestQuestionCommand extends Command
{
    protected $signature = 'ai:test-question
                            {question : The question to test}
                            {--user= : User ID to simulate (default: first user)}
                            {--debug : Show detailed debug info (context, scores, cypher)}';

    protected $description = 'Test an AI question end-to-end and show the full pipeline result';

    public function handle(): int
    {
        $question = $this->argument('question');
        $showDebug = $this->option('debug');

        $this->info("Testing question: \"{$question}\"");
        $this->newLine();

        // Resolve user
        $userId = $this->option('user');
        $userClass = config('auth.providers.users.model', 'App\\Models\\User');
        $user = $userId ? $userClass::find($userId) : $userClass::first();

        if (!$user) {
            $this->error('No user found. Use --user=ID to specify one.');
            return 1;
        }

        $this->info("User: {$user->name} (ID: {$user->id}, Email: {$user->email})");
        auth()->login($user);

        // In CLI, currentTeamId() may return null since there's no web session.
        // If the app auth adapter relies on it, warn the user.
        if (function_exists('currentTeamId') && currentTeamId() === null && $user->current_team_id) {
            $this->warn("Note: Team context not set in CLI. Security filters may not work correctly.");
            $this->warn("User's team_id is {$user->current_team_id} but currentTeamId() returns null.");
        }

        // Step 1: Context retrieval with timing
        $this->newLine();
        $this->comment('--- Step 1: Context Retrieval ---');
        $contextStart = microtime(true);

        try {
            $context = AI::retrieveContext($question);
        } catch (\Throwable $e) {
            $this->error("Context retrieval failed: {$e->getMessage()}");
            return 1;
        }

        $contextMs = round((microtime(true) - $contextStart) * 1000);
        $this->info("Context retrieved in {$contextMs}ms");

        // Show selection info
        $selectionInfo = $context['selection_info'] ?? [];
        if (!empty($selectionInfo)) {
            $this->info("Selection method: " . ($selectionInfo['method'] ?? 'unknown'));
            $this->info("Selected entities: " . implode(', ', $selectionInfo['selected_entities'] ?? []));
            $this->info("Entities: " . ($selectionInfo['entities_selected'] ?? 0) .
                       ", Relationships: " . ($selectionInfo['relationships_selected'] ?? 0) .
                       ", Scopes: " . ($selectionInfo['scopes_selected'] ?? 0));
        }

        // Show quality
        $quality = $context['quality'] ?? [];
        $this->info("Context quality: " . ($quality['level'] ?? 'unknown') . " ({$quality['score']}/{$quality['max']})");

        // Show detected entities
        $entityMeta = $context['entity_metadata'] ?? [];
        $detected = $entityMeta['detected_entities'] ?? [];
        $this->info("Detected entities: " . (empty($detected) ? 'NONE' : implode(', ', array_map('class_basename', $detected))));

        $detectedScopes = $entityMeta['detected_scopes'] ?? [];
        if (!empty($detectedScopes)) {
            $this->info("Detected scopes: " . implode(', ', array_keys($detectedScopes)));
        }

        // Show schema info
        $schema = $context['graph_schema'] ?? [];
        $this->info("Schema labels: " . implode(', ', $schema['labels'] ?? []));

        if (!empty($context['errors'])) {
            $this->warn("Context errors: " . implode('; ', $context['errors']));
        }

        if ($showDebug) {
            // Show Query Understanding results
            $quResult = $context['query_understanding'] ?? null;
            if ($quResult) {
                $this->newLine();
                $this->comment('Query Understanding:');
                $this->line('  Entity types: ' . (empty($quResult['entity_types']) ? 'NONE' : implode(', ', $quResult['entity_types'])));
                $this->line('  Person names: ' . (empty($quResult['person_names']) ? 'NONE' : implode(', ', $quResult['person_names'])));
                $this->line('  Property concepts: ' . (empty($quResult['property_concepts']) ? 'NONE' : implode(', ', $quResult['property_concepts'])));
                $this->line('  Search query: ' . ($quResult['search_query'] ?: 'N/A'));
                $this->line('  Intent: ' . ($quResult['intent'] ?? 'N/A'));
            } else {
                $this->newLine();
                $this->comment('Query Understanding: not available (disabled or failed)');
            }

            // Show similar queries
            $this->newLine();
            $this->comment('Similar queries:');
            foreach ($context['similar_queries'] ?? [] as $sq) {
                $this->line(sprintf("  [%.2f] %s → %s", $sq['score'], $sq['question'], substr($sq['query'], 0, 80)));
            }

            // Show relevant entities sample
            $this->newLine();
            $this->comment('Example entities:');
            foreach ($context['relevant_entities'] ?? [] as $label => $entities) {
                $this->line("  {$label}: " . count($entities) . " examples");
            }
        }

        // Step 2: Query generation
        $this->newLine();
        $this->comment('--- Step 2: Query Generation ---');
        $queryStart = microtime(true);

        try {
            $queryResult = AI::generateQuery($question, $context, ['user' => $user]);
        } catch (\Throwable $e) {
            $this->error("Query generation failed: {$e->getMessage()}");
            return 1;
        }

        $queryMs = round((microtime(true) - $queryStart) * 1000);
        $this->info("Query generated in {$queryMs}ms");
        $this->info("Cypher: " . ($queryResult['cypher'] ?? 'NULL'));
        $this->info("Confidence: " . ($queryResult['confidence'] ?? 0));

        if (!empty($queryResult['warnings'])) {
            $this->warn("Warnings: " . implode('; ', $queryResult['warnings']));
        }

        $execMs = 0;
        $executionResult = ['data' => [], 'metadata' => []];

        if (empty($queryResult['cypher'])) {
            $this->comment('No Cypher query needed (LLM will answer from context/files).');
        } else {
            // Step 3: Query execution
            $this->newLine();
            $this->comment('--- Step 3: Query Execution ---');
            $execStart = microtime(true);

            try {
                $executionResult = AI::executeQuery($queryResult['cypher']);
            } catch (\Throwable $e) {
                $this->error("Query execution failed: {$e->getMessage()}");

                if ($showDebug) {
                    $this->line("Stack trace: " . $e->getTraceAsString());
                }

                return 1;
            }

            $execMs = round((microtime(true) - $execStart) * 1000);
            $dataCount = count($executionResult['data'] ?? []);
            $this->info("Query executed in {$execMs}ms, returned {$dataCount} rows");

            if ($showDebug && $dataCount > 0) {
                $this->comment('First 3 rows:');
                foreach (array_slice($executionResult['data'], 0, 3) as $i => $row) {
                    $this->line("  [{$i}] " . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            }
        }

        // Step 4: Response generation (includes file context from answerQuestion pipeline)
        $this->newLine();
        $this->comment('--- Step 4: Response Generation ---');
        $respStart = microtime(true);

        try {
            // Retrieve file context for response generation
            $fileContext = [];
            if (config('ai.file_context.enabled', true)) {
                try {
                    $fileContext = AI::retrieveFileContext($question, $user);
                } catch (\Throwable $e) {
                    if ($showDebug) {
                        $this->warn("File context retrieval failed: {$e->getMessage()}");
                    }
                }
            }

            if ($showDebug && !empty($fileContext['relevant_files'])) {
                $this->comment('File context:');
                foreach ($fileContext['relevant_files'] as $f) {
                    $this->line(sprintf("  [%.2f] %s: %s", $f['relevance'], $f['filename'], substr($f['snippet'], 0, 80)));
                }
            }

            $response = AI::generateResponse($question, $executionResult, $queryResult['cypher'] ?? '', [
                'user' => $user,
                'file_context' => $fileContext,
            ]);
        } catch (\Throwable $e) {
            $this->error("Response generation failed: {$e->getMessage()}");
            return 1;
        }

        $respMs = round((microtime(true) - $respStart) * 1000);

        // Final answer
        $this->newLine();
        $this->comment('=== FINAL ANSWER ===');
        $this->line($response['answer'] ?? 'No answer generated');

        // Summary
        $this->newLine();
        $this->comment('--- Timing Summary ---');
        $totalMs = $contextMs + $queryMs + $execMs + $respMs;
        $this->info("Context: {$contextMs}ms | Query Gen: {$queryMs}ms | Execution: {$execMs}ms | Response: {$respMs}ms | Total: {$totalMs}ms");

        return 0;
    }
}
