<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryExecutionResult;
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
        $selectionInfo = $context->selectionInfo;
        if (!empty($selectionInfo)) {
            $this->info("Selection method: " . ($selectionInfo['method'] ?? 'unknown'));
            $this->info("Selected entities: " . implode(', ', $selectionInfo['selected_entities'] ?? []));
            $this->info("Entities: " . ($selectionInfo['entities_selected'] ?? 0) .
                       ", Relationships: " . ($selectionInfo['relationships_selected'] ?? 0) .
                       ", Scopes: " . ($selectionInfo['scopes_selected'] ?? 0));
        }

        // Show quality
        $quality = $context->quality;
        $this->info("Context quality: " . ($quality['level'] ?? 'unknown') . " ({$quality['score']}/{$quality['max']})");

        // Show detected entities
        $entityMeta = $context->entityMetadata;
        $detected = $entityMeta['detected_entities'] ?? [];
        $this->info("Detected entities: " . (empty($detected) ? 'NONE' : implode(', ', array_map('class_basename', $detected))));

        $detectedScopes = $entityMeta['detected_scopes'] ?? [];
        if (!empty($detectedScopes)) {
            $this->info("Detected scopes: " . implode(', ', array_keys($detectedScopes)));
        }

        // Show schema info
        $schema = $context->graphSchema;
        $this->info("Schema labels: " . implode(', ', $schema['labels'] ?? []));

        if (!empty($context->errors)) {
            $this->warn("Context errors: " . implode('; ', $context->errors));
        }

        if ($showDebug) {
            // Show Query Understanding results
            $qu = $context->qu;
            $this->newLine();
            $this->comment('Query Understanding:');
            $this->line('  Entity types: ' . (empty($qu->entityTypes) ? 'NONE' : implode(', ', $qu->entityTypes)));
            $this->line('  Person names: ' . (empty($qu->personNames) ? 'NONE' : implode(', ', $qu->personNames)));
            $this->line('  Property concepts: ' . (empty($qu->propertyConcepts) ? 'NONE' : implode(', ', $qu->propertyConcepts)));
            $this->line('  Search query: ' . ($qu->searchQuery ?: 'N/A'));
            $this->line('  Intent: ' . ($qu->intent ?: 'N/A'));
            $this->line('  Question type: ' . ($qu->questionType ?: 'N/A'));
            if ($qu->clarifiedQuestion) {
                $this->line('  Clarified question: ' . $qu->clarifiedQuestion);
            }
            if (!empty($qu->searchTags)) {
                $this->line('  Search tags: ' . implode(', ', $qu->searchTags));
            }

            // Show similar queries
            $this->newLine();
            $this->comment('Similar queries:');
            foreach ($context->similarQueries as $sq) {
                $this->line(sprintf("  [%.2f] %s → %s", $sq['score'], $sq['question'], substr($sq['query'], 0, 80)));
            }

            // Show relevant entities sample
            $this->newLine();
            $this->comment('Example entities:');
            foreach ($context->relevantEntities as $label => $entities) {
                $this->line("  {$label}: " . count($entities) . " examples");
            }
        }

        // Step 2: Query generation (skip for knowledge-only questions)
        $this->newLine();
        $this->comment('--- Step 2: Query Generation ---');

        $queryResult = ['cypher' => null, 'confidence' => 0, 'warnings' => [], 'explanation' => '', 'metadata' => []];
        $execMs = 0;
        $executionResult = ['data' => [], 'metadata' => []];

        // Retrieve file context BEFORE query decision — try all queries and merge results
        $fileContext = [];
        if (config('ai.file_context.enabled', true)) {
            $searchQueries = array_filter(array_unique([
                $context->qu->searchQuery,
                $question,
                $context->qu->clarifiedQuestion,
            ]));

            if (!empty($context->qu->searchTags)) {
                $searchQueries[] = implode(' ', $context->qu->searchTags);
            }

            $allFiles = [];
            foreach ($searchQueries as $searchQuery) {
                try {
                    $result = AI::retrieveFileContext($searchQuery, $user);
                    $found = count($result['relevant_files'] ?? []);
                    if ($showDebug) {
                        $this->line("  File search [{$found} files]: \"" . substr($searchQuery, 0, 70) . '"');
                    }
                    foreach ($result['relevant_files'] ?? [] as $file) {
                        $fileId = $file['file_id'] ?? $file['filename'] ?? '';
                        if (!isset($allFiles[$fileId]) || ($file['relevance'] ?? 0) > ($allFiles[$fileId]['relevance'] ?? 0)) {
                            $allFiles[$fileId] = $file;
                        }
                    }
                } catch (\Throwable $e) {
                    if ($showDebug) {
                        $this->warn("  File search failed: {$e->getMessage()}");
                    }
                }
            }

            if (!empty($allFiles)) {
                $files = array_values($allFiles);
                usort($files, fn($a, $b) => ($b['priority_score'] ?? $b['relevance'] ?? 0) <=> ($a['priority_score'] ?? $a['relevance'] ?? 0));
                $fileContext = [
                    'relevant_files' => $files,
                    'file_count' => count($files),
                    'has_physical' => collect($files)->contains('source', 'physical'),
                    'has_database' => collect($files)->contains(fn($f) => ($f['source'] ?? '') !== 'physical'),
                ];
            }
        }

        if ($showDebug && !empty($fileContext['relevant_files'])) {
            $this->comment('File context:');
            foreach ($fileContext['relevant_files'] as $f) {
                $tierInfo = isset($f['authority_note']) ? " [{$f['authority_note']}]" : '';
                $this->line(sprintf("  [%.2f] %s%s: %s", $f['relevance'], $f['filename'], $tierInfo, substr($f['snippet'], 0, 80)));
            }
        }

        $hasFileContext = !empty($fileContext['relevant_files']);
        $isKnowledgeQuery = $context->qu->questionType === 'knowledge_query';

        $queryMs = 0;

        if ($isKnowledgeQuery) {
            $this->info('Question type: knowledge_query — skipping Cypher generation.');
            if (!$hasFileContext) {
                $this->warn('No file context found — response will be limited. Run `ai:ingest --docs` to index documentation.');
            }
            $queryResult['confidence'] = 1.0;
            $queryResult['metadata'] = ['source' => 'file_context', 'question_type' => 'knowledge_query'];
        } else {
            $queryStart = microtime(true);

            try {
                $queryResult = AI::generateQuery($question, $context, ['user' => $user]);
            } catch (\Throwable $e) {
                $this->error("Query generation failed: {$e->getMessage()}");
                return 1;
            }

            $queryMs = round((microtime(true) - $queryStart) * 1000);
            $this->info("Query generated in {$queryMs}ms");
        }

        $this->info("Cypher: " . ($queryResult['cypher'] ?? 'NULL'));
        $this->info("Confidence: " . ($queryResult['confidence'] ?? 0));

        if (!empty($queryResult['warnings'])) {
            $this->warn("Warnings: " . implode('; ', $queryResult['warnings']));
        }

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
            // File context already retrieved in Step 2

            // Build PipelineContext for response generation
            $pipelineCtx = new PipelineContext();
            $pipelineCtx->question = $question;
            $pipelineCtx->fileContext = $fileContext;
            $pipelineCtx->queryResult = new QueryGenerationResult(
                cypher: $queryResult['cypher'] ?? null,
                explanation: $queryResult['explanation'] ?? '',
                confidence: (float) ($queryResult['confidence'] ?? 0.0),
                warnings: $queryResult['warnings'] ?? [],
                metadata: $queryResult['metadata'] ?? [],
            );
            $pipelineCtx->executionResult = new QueryExecutionResult(
                success: !empty($executionResult['data']) || empty($executionResult['errors'] ?? []),
                data: $executionResult['data'] ?? [],
                stats: $executionResult['stats'] ?? [],
                metadata: $executionResult['metadata'] ?? [],
                errors: $executionResult['errors'] ?? [],
            );

            $response = AI::generateResponse($pipelineCtx, [
                'user' => $user,
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
