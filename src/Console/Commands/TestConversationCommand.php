<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\AiChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Multi-turn conversation testing command.
 *
 * Two modes:
 * (A) Interactive REPL — type questions, see results, context carries forward
 * (B) Script mode — run predefined conversation scripts from JSON files
 *
 * Uses AiChatService.askWithConversation() for the real production pipeline,
 * including context tracking, entity extraction, and reference resolution.
 */
class TestConversationCommand extends Command
{
    protected $signature = 'ai:test-conversation
                            {script? : Path to a JSON conversation script file}
                            {--user=1 : User ID for the conversation}
                            {--save= : Save this interactive session as a JSON script}';

    protected $description = 'Test multi-turn conversations with context carrying forward';

    public function handle(): int
    {
        $userId = (int) $this->option('user');
        $user = \App\Models\User::find($userId);

        if (!$user) {
            $this->error("User {$userId} not found.");
            return self::FAILURE;
        }

        auth()->login($user);

        $scriptPath = $this->argument('script');

        if ($scriptPath) {
            return $this->runScript($scriptPath, $user);
        }

        return $this->runInteractive($user);
    }

    /**
     * Interactive REPL mode.
     */
    private function runInteractive($user): int
    {
        $this->info('AI Conversation Test (interactive mode)');
        $this->info("User: {$user->name} (ID: {$user->id})");
        $this->comment('Type questions. Context carries forward between turns.');
        $this->comment('Commands: /quit, /context, /save <file>, /reset');
        $this->newLine();

        $conversation = $this->createTestConversation($user);
        $chatService = app(AiChatService::class);
        $history = [];
        $turn = 0;

        while (true) {
            $question = $this->ask("Turn " . (++$turn));

            if ($question === null || $question === '/quit') {
                break;
            }

            if ($question === '/context') {
                $this->showContext($conversation);
                $turn--;
                continue;
            }

            if ($question === '/reset') {
                $conversation = $this->createTestConversation($user);
                $history = [];
                $turn = 0;
                $this->info('Conversation reset.');
                continue;
            }

            if (str_starts_with($question, '/save ')) {
                $savePath = trim(substr($question, 6));
                $this->saveScript($history, $savePath, $user->id);
                $turn--;
                continue;
            }

            if (empty(trim($question))) {
                $turn--;
                continue;
            }

            $this->line('');

            $startTime = microtime(true);

            try {
                $result = $chatService->askWithConversation($question, $conversation, [
                    'user' => $user,
                ]);

                $elapsed = round((microtime(true) - $startTime) * 1000);

                $this->line("<fg=green>Answer:</> {$result['answer']}");

                if (!empty($result['cypher_query'])) {
                    $this->line("<fg=gray>Cypher:</> {$result['cypher_query']}");
                }

                $this->line("<fg=gray>Time:</> {$elapsed}ms");

                $history[] = [
                    'question' => $question,
                    'answer' => $result['answer'],
                    'cypher_query' => $result['cypher_query'] ?? null,
                ];

            } catch (\Throwable $e) {
                $this->error("Error: {$e->getMessage()}");
                $history[] = [
                    'question' => $question,
                    'error' => $e->getMessage(),
                ];
            }

            $this->newLine();
        }

        if (!empty($history) && $this->option('save')) {
            $this->saveScript($history, $this->option('save'), $user->id);
        }

        $conversation->delete();

        return self::SUCCESS;
    }

    /**
     * Script mode — run a predefined conversation from a JSON file.
     */
    private function runScript(string $scriptPath, $user): int
    {
        if (!File::exists($scriptPath)) {
            $this->error("Script file not found: {$scriptPath}");
            return self::FAILURE;
        }

        $script = json_decode(File::get($scriptPath), true);

        if (!$script || empty($script['turns'])) {
            $this->error('Invalid script format. Expected {"turns": [{"question": "..."}]}');
            return self::FAILURE;
        }

        $this->info('AI Conversation Test (script mode)');
        $this->info("Script: " . basename($scriptPath));
        $this->info("User: {$user->name} (ID: {$user->id})");
        $this->info("Turns: " . count($script['turns']));
        $this->newLine();

        $conversation = $this->createTestConversation($user);
        $chatService = app(AiChatService::class);
        $passed = 0;
        $failed = 0;
        $totalTime = 0;

        foreach ($script['turns'] as $i => $turn) {
            $turnNum = $i + 1;
            $question = $turn['question'];

            $this->line("<fg=cyan>Turn {$turnNum}:</> {$question}");

            $startTime = microtime(true);

            try {
                $result = $chatService->askWithConversation($question, $conversation, [
                    'user' => $user,
                ]);

                $elapsed = round((microtime(true) - $startTime) * 1000);
                $totalTime += $elapsed;

                $this->line("  <fg=green>Answer:</> " . $this->truncateText($result['answer'], 200));

                if (!empty($result['cypher_query'])) {
                    $this->line("  <fg=gray>Cypher:</> " . $this->truncateText($result['cypher_query'], 150));
                }

                $assertionPassed = $this->checkAssertions($turn, $result);

                if ($assertionPassed) {
                    $this->line("  <fg=green>PASS</> ({$elapsed}ms)");
                    $passed++;
                } else {
                    $this->line("  <fg=red>FAIL</> ({$elapsed}ms)");
                    $failed++;
                }

            } catch (\Throwable $e) {
                $elapsed = round((microtime(true) - $startTime) * 1000);
                $totalTime += $elapsed;
                $this->line("  <fg=red>ERROR:</> {$e->getMessage()} ({$elapsed}ms)");
                $failed++;
            }

            $this->newLine();
        }

        $total = $passed + $failed;
        $this->info("Results: {$passed}/{$total} passed ({$totalTime}ms total)");

        if ($failed > 0) {
            $this->warn("{$failed} turn(s) failed.");
        }

        $conversation->delete();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Check optional assertions on a turn result.
     */
    private function checkAssertions(array $turn, array $result): bool
    {
        $passed = true;

        // Assert answer contains text
        if (isset($turn['expect_contains'])) {
            $contains = is_array($turn['expect_contains']) ? $turn['expect_contains'] : [$turn['expect_contains']];
            foreach ($contains as $text) {
                if (!str_contains(strtolower($result['answer']), strtolower($text))) {
                    $this->line("    <fg=red>Expected answer to contain:</> \"{$text}\"");
                    $passed = false;
                }
            }
        }

        // Assert answer does NOT contain text
        if (isset($turn['expect_not_contains'])) {
            $notContains = is_array($turn['expect_not_contains']) ? $turn['expect_not_contains'] : [$turn['expect_not_contains']];
            foreach ($notContains as $text) {
                if (str_contains(strtolower($result['answer']), strtolower($text))) {
                    $this->line("    <fg=red>Expected answer NOT to contain:</> \"{$text}\"");
                    $passed = false;
                }
            }
        }

        // Assert Cypher query was generated
        if (isset($turn['expect_cypher']) && $turn['expect_cypher'] && empty($result['cypher_query'])) {
            $this->line("    <fg=red>Expected a Cypher query but none was generated</>");
            $passed = false;
        }

        // Assert Cypher contains pattern
        if (isset($turn['expect_cypher_contains'])) {
            $cypher = $result['cypher_query'] ?? '';
            $patterns = is_array($turn['expect_cypher_contains']) ? $turn['expect_cypher_contains'] : [$turn['expect_cypher_contains']];
            foreach ($patterns as $pattern) {
                if (!str_contains(strtolower($cypher), strtolower($pattern))) {
                    $this->line("    <fg=red>Expected Cypher to contain:</> \"{$pattern}\"");
                    $passed = false;
                }
            }
        }

        return $passed;
    }

    private function createTestConversation($user): AiConversation
    {
        return AiConversation::create([
            'user_id' => $user->id,
            'team_id' => $user->current_team_id,
            'title' => 'Test Conversation ' . now()->format('Y-m-d H:i:s'),
            'status' => 'active',
        ]);
    }

    private function showContext(AiConversation $conversation): void
    {
        $conversation->refresh();
        $snapshot = $conversation->context_snapshot ?? [];

        $this->info('Current conversation context:');
        $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();
    }

    private function saveScript(array $history, string $path, int $userId): void
    {
        $script = [
            'description' => 'Conversation test script',
            'user_id' => $userId,
            'created_at' => now()->toIso8601String(),
            'turns' => array_map(fn($h) => [
                'question' => $h['question'],
            ], $history),
        ];

        File::put($path, json_encode($script, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Script saved to: {$path}");
    }

    private function truncateText(string $text, int $max): string
    {
        $text = str_replace(["\n", "\r"], ' ', $text);
        if (strlen($text) <= $max) {
            return $text;
        }
        return substr($text, 0, $max) . '...';
    }
}
