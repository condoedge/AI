<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Exceptions\LlmInvalidJsonException;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 1.17 — QU output budget + truncation recovery.
 *
 * The QU reply grew (clarify keys, corpus translations, register expansions,
 * search tags) past the old 500-token cap; a reply cut at max_tokens is
 * unterminated JSON, which silently degraded the WHOLE turn to an empty QU
 * result. CONTRACT: max_tokens comes from ai.understanding.max_tokens
 * (default 1200); a truncated reply (provider finish_reason=length, or an
 * unterminated long reply) is logged with its tail and retried ONCE with
 * double the budget. Non-truncation parse failures are not retried.
 */
class QueryUnderstandingTruncationRetryTest extends TestCase
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    private array $logged = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function logger(): AiLogger
    {
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        foreach (['warning', 'error'] as $level) {
            $logger->shouldReceive($level)->andReturnUsing(function ($service, $message, $context = []) use ($level) {
                $this->logged[] = ['level' => $level, 'message' => $message, 'context' => $context];
            })->byDefault();
        }

        return $logger;
    }

    private const VALID = ['entity_types' => [], 'search_query' => 'team members', 'intent' => 'list', 'language' => 'fr'];

    /**
     * @param array<int, \Throwable|array> $script   One entry per chatJson call.
     * @param array<int, array>            $options  Captured options per call.
     */
    private function scripted(array $script, ?array &$options, array $config = []): QueryUnderstandingService
    {
        $options = [];
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')
            ->times(count($script))
            ->andReturnUsing(function ($messages, $opts) use (&$script, &$options) {
                $options[] = $opts;
                $next = array_shift($script);
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return $next;
            });

        return new QueryUnderstandingService($llm, $this->logger(), $config);
    }

    private function truncatedReply(): string
    {
        return '{"entity_types": [], "search_query": "team members", "search_tags": ["membres", "équipe", ' . str_repeat('"tag", ', 300) . '"ta';
    }

    /** @test */
    public function a_provider_reported_truncation_is_retried_once_with_double_budget_and_parses(): void
    {
        $service = $this->scripted([
            new LlmInvalidJsonException('OpenAI response is not valid JSON: Syntax error', $this->truncatedReply(), true),
            self::VALID,
        ], $options, ['max_tokens' => 1200]);

        $result = $service->analyze('Liste des membres', []);

        $this->assertSame('team members', $result->searchQuery, 'the retried reply must parse into the result');
        $this->assertSame('list', $result->intent);
        $this->assertSame(1200, $options[0]['max_tokens']);
        $this->assertSame(2400, $options[1]['max_tokens'], 'the retry doubles max_tokens');

        $warnings = array_values(array_filter($this->logged, static fn($l) => $l['level'] === 'warning'));
        $this->assertCount(1, $warnings, 'the truncation is logged as a warning');
        $this->assertStringEndsWith('"ta', (string) ($warnings[0]['context']['tail'] ?? ''), 'the warning carries the reply tail');
    }

    /** @test */
    public function an_unterminated_long_reply_is_treated_as_truncation_when_the_provider_does_not_flag_it(): void
    {
        $service = $this->scripted([
            new LlmInvalidJsonException('response is not valid JSON: Syntax error', $this->truncatedReply(), false),
            self::VALID,
        ], $options, ['max_tokens' => 500]);

        $result = $service->analyze('Liste des membres', []);

        $this->assertSame('team members', $result->searchQuery);
        $this->assertSame(1000, $options[1]['max_tokens']);
    }

    /** @test */
    public function a_short_malformed_reply_is_not_retried(): void
    {
        $service = $this->scripted([
            new LlmInvalidJsonException('response is not valid JSON: Syntax error', 'Sure! Here is the JSON you asked for', false),
        ], $options, ['max_tokens' => 1200]);

        $result = $service->analyze('Liste des membres', []);

        $this->assertSame('', $result->searchQuery, 'degrades to the empty result (existing fail-safe)');
        $this->assertCount(1, $options);
    }

    /** @test */
    public function a_retry_that_is_still_truncated_degrades_to_the_empty_result(): void
    {
        $service = $this->scripted([
            new LlmInvalidJsonException('not valid JSON', $this->truncatedReply(), true),
            new LlmInvalidJsonException('not valid JSON', $this->truncatedReply(), true),
        ], $options, ['max_tokens' => 1200]);

        $result = $service->analyze('Liste des membres', []);

        $this->assertSame('', $result->searchQuery);
        $this->assertCount(2, $options, 'exactly ONE retry');
    }

    /** @test */
    public function max_tokens_defaults_to_the_understanding_config_key(): void
    {
        $service = $this->scripted([self::VALID], $options);
        $service->analyze('q', []);
        $this->assertSame(1200, $options[0]['max_tokens'], 'package default 1200');

        config(['ai.understanding.max_tokens' => 900]);
        $service = $this->scripted([self::VALID], $options);
        $service->analyze('q', []);
        $this->assertSame(900, $options[0]['max_tokens']);
    }

    /** @test */
    public function the_package_config_declares_the_budget_under_understanding(): void
    {
        $config = require __DIR__ . '/../../../config/ai.php';

        $this->assertSame(1200, $config['understanding']['max_tokens'] ?? null);
        $this->assertArrayHasKey('clarify_enabled', $config['understanding']);
    }

    /** @test */
    public function the_prompt_closes_with_the_json_only_reminder_after_the_conversation_block(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $captured = null;
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use (&$captured) {
            $captured = $messages;
            return self::VALID;
        });
        $service = new QueryUnderstandingService($llm, $this->logger(), []);

        $service->analyze('exporte-la', [], [
            'last_subject' => 'membres',
            'last_result_count' => 10,
            'recent_exchanges' => [['role' => 'user', 'question' => 'Liste des membres', 'answer_summary' => null]],
        ]);

        $system = rtrim((string) ($captured[0]['content'] ?? ''));
        $lastLine = substr($system, (int) strrpos($system, "\n") + 1);

        $this->assertGreaterThan(
            (int) strpos($system, 'CONVERSATION SO FAR (authoritative'),
            (int) strrpos($system, $lastLine),
            'the reminder comes AFTER the conversation block'
        );
        $this->assertStringContainsString('ONLY the JSON object', $lastLine);
    }
}
