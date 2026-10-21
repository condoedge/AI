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
 * Eval p23-B: two turns lost their route because ONE field of the Query-Understanding
 * reply came back with another value on unchanged instructions (`navigation_target`,
 * `digest_request`). Measured live (it9, gpt-4o-mini, 275 plain-JSON replies): the keys
 * were never left out — the VALUE moved; and with the reply constrained to a strict
 * schema the routing fields stopped moving on the turns that flipped.
 *
 * CONTRACT:
 *  - the QU call asks for its reply under a strict JSON Schema (chatJson's optional
 *    `json_schema` option — a provider without structured outputs ignores it): every key
 *    the prompt documents is a REQUIRED property, in the prompt's order, with the enum of
 *    values the prompt allows; optional values are nullable, never absent;
 *  - a key the reply still leaves out (a provider that ignored the schema) is NOT
 *    guessed silently: the result names it (`missingKeys`, `missing_keys` in the trace).
 */
class QueryUnderstandingStrictSchemaTest extends TestCase
{
    /** @var list<array{messages: array, options: array}> */
    private array $calls = [];

    /** @var list<array{level: string, message: string, context: array}> */
    private array $logged = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private const FULL_REPLY = [
        'entity_types' => [],
        'person_names' => [],
        'property_concepts' => [],
        'search_query' => 'add new members',
        'subject_label' => 'Add new members',
        'possessive_words' => [],
        'possessive_forms' => [],
        'possessive_scope' => null,
        'intent' => 'other',
        'question_type' => 'knowledge_query',
        'clarified_question' => 'Where are new members added to the team?',
        'search_tags' => ['members'],
        'export_format' => null,
        'language' => 'en',
        'navigation_target' => 'own_team',
        'follow_up_reference' => false,
    ];

    /** @param list<\Throwable|array> $script one entry per chatJson call */
    private function service(array $script, array $config = []): QueryUnderstandingService
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->times(count($script))->andReturnUsing(function ($messages, $options) use (&$script) {
            $this->calls[] = ['messages' => $messages, 'options' => $options];
            $next = array_shift($script);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });

        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        foreach (['info', 'debug', 'warning', 'error'] as $level) {
            $logger->shouldReceive($level)->andReturnUsing(function ($service, $message, $context = []) use ($level) {
                $this->logged[] = ['level' => $level, 'message' => $message, 'context' => $context];
            })->byDefault();
        }

        return new QueryUnderstandingService($llm, $logger, $config);
    }

    private function enableDigest(): void
    {
        config(['ai.attention_sources' => [
            'open_tasks' => ['label' => 'Open tasks', 'cypher' => 'MATCH (t:Task) RETURN t'],
        ]]);
    }

    /** The keys the prompt documents ("- "key": …"), in the prompt's order. */
    private function documentedKeys(string $system): array
    {
        preg_match_all('/^- "([a-z_]+)":/m', $system, $m);

        return $m[1];
    }

    /** @test */
    public function the_call_asks_for_a_strict_schema_of_exactly_the_keys_the_prompt_documents(): void
    {
        $this->service([self::FULL_REPLY])->analyze('Where can I add new members?', []);

        $schema = $this->calls[0]['options']['json_schema'] ?? null;
        $this->assertIsArray($schema);
        $this->assertSame('query_understanding', $schema['name']);

        $object = $schema['schema'];
        $this->assertSame('object', $object['type']);
        $this->assertFalse($object['additionalProperties']);

        $documented = $this->documentedKeys($this->calls[0]['messages'][0]['content']);
        $this->assertNotEmpty($documented);
        $this->assertNotContains('digest_request', $documented, 'no attention source: the digest key is not asked for');
        // Same keys, same ORDER (a strict reply is written in the schema's property order).
        $this->assertSame($documented, array_keys($object['properties']));
        // Strict mode: every property is required — an optional value is null, never absent.
        $this->assertSame($documented, $object['required']);
    }

    /** @test */
    public function the_digest_key_is_in_the_schema_exactly_when_the_prompt_asks_for_it(): void
    {
        $this->enableDigest();

        $this->service([self::FULL_REPLY + ['digest_request' => false]])->analyze('What should I take care of next?', []);

        $object = $this->calls[0]['options']['json_schema']['schema'];
        $documented = $this->documentedKeys($this->calls[0]['messages'][0]['content']);
        $this->assertContains('digest_request', $documented);
        $this->assertSame($documented, array_keys($object['properties']));
        $this->assertSame($documented, $object['required']);
        $this->assertSame(['type' => 'boolean'], $object['properties']['digest_request']);
    }

    /** @test The routing fields can only hold the values the prompt allows; "no value" is an explicit null. */
    public function the_routing_fields_are_typed_with_their_allowed_values(): void
    {
        $this->service([self::FULL_REPLY])->analyze('Where can I add new members?', []);

        $properties = $this->calls[0]['options']['json_schema']['schema']['properties'];

        $this->assertSame(['type' => ['string', 'null'], 'enum' => ['own_profile', 'own_team', null]], $properties['navigation_target']);
        $this->assertSame(['type' => ['string', 'null'], 'enum' => ['pdf', 'excel', null]], $properties['export_format']);
        $this->assertSame(['type' => ['string', 'null'], 'enum' => ['personal', 'collective', null]], $properties['possessive_scope']);
        $this->assertSame(['type' => 'boolean'], $properties['follow_up_reference']);
        $this->assertSame(['data_query', 'knowledge_query', 'hybrid', 'conversational'], $properties['question_type']['enum']);
        $this->assertSame(
            ['count', 'list', 'lookup', 'aggregate', 'compare', 'file_listing', 'page_explanation', 'other'],
            $properties['intent']['enum']
        );
        $this->assertSame(['subject', 'possessive', 'object'], $properties['possessive_forms']['items']['enum']);
        foreach (['entity_types', 'person_names', 'property_concepts', 'possessive_words', 'search_tags'] as $list) {
            $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], $properties[$list], $list);
        }
        foreach (['search_query', 'subject_label', 'clarified_question', 'language'] as $text) {
            $this->assertSame(['type' => 'string'], $properties[$text], $text);
        }
    }

    /** @test */
    public function the_truncation_retry_asks_for_the_same_schema(): void
    {
        $cut = '{"entity_types": [], "search_query": "team members", "search_tags": [' . str_repeat('"tag", ', 300) . '"ta';

        $result = $this->service([
            new LlmInvalidJsonException('OpenAI response is not valid JSON: Syntax error', $cut, true),
            self::FULL_REPLY,
        ], ['max_tokens' => 1200])->analyze('Where can I add new members?', []);

        $this->assertSame('own_team', $result->navigationTarget);
        $this->assertSame(2400, $this->calls[1]['options']['max_tokens']);
        $this->assertSame($this->calls[0]['options']['json_schema'], $this->calls[1]['options']['json_schema']);
    }

    /** @test A host can turn the schema off (ai.understanding.strict_schema): the call is the plain JSON one. */
    public function the_schema_can_be_turned_off(): void
    {
        $this->service([self::FULL_REPLY], ['strict_schema' => false])->analyze('Where can I add new members?', []);
        $this->assertArrayNotHasKey('json_schema', $this->calls[0]['options']);

        config(['ai.understanding.strict_schema' => false]);
        $this->service([self::FULL_REPLY])->analyze('Where can I add new members?', []);
        $this->assertArrayNotHasKey('json_schema', $this->calls[1]['options']);
    }

    /**
     * @test A reply that leaves keys out (a provider that ignored the schema) still parses — each
     * missing field takes its neutral default, as before — but the result SAYS which keys the
     * model did not write, in the trace and in the log: "null" is no longer silently "not judged".
     */
    public function keys_the_reply_leaves_out_are_named_on_the_result(): void
    {
        $this->enableDigest();
        $reply = self::FULL_REPLY;
        unset($reply['navigation_target'], $reply['export_format']);   // digest_request is absent too

        $result = $this->service([$reply])->analyze('Where can I add new members?', []);

        $this->assertNull($result->navigationTarget);
        $this->assertFalse($result->digestRequest);
        $this->assertSame(['export_format', 'navigation_target', 'digest_request'], $result->missingKeys);
        $this->assertSame(['export_format', 'navigation_target', 'digest_request'], $result->toArray()['missing_keys'] ?? null);

        $complete = array_values(array_filter($this->logged, static fn (array $l): bool => $l['message'] === 'Analysis complete'));
        $this->assertSame(['export_format', 'navigation_target', 'digest_request'], $complete[0]['context']['missing_keys'] ?? null);
    }

    /** @test A complete reply names nothing: an explicit null or false IS the model's judgment. */
    public function a_complete_reply_has_no_missing_key(): void
    {
        $this->enableDigest();

        $result = $this->service([self::FULL_REPLY + ['digest_request' => false]])->analyze('Where can I add new members?', []);

        $this->assertSame([], $result->missingKeys);
        $this->assertArrayNotHasKey('missing_keys', $result->toArray());
        $complete = array_values(array_filter($this->logged, static fn (array $l): bool => $l['message'] === 'Analysis complete'));
        $this->assertArrayNotHasKey('missing_keys', $complete[0]['context']);
    }

    /** @test The copies of a result keep what the reply left out (clarify guard, probes, follow-up). */
    public function the_missing_keys_survive_the_results_copies(): void
    {
        $reply = self::FULL_REPLY;
        unset($reply['navigation_target']);

        $result = $this->service([$reply])->analyze('Where can I add new members?', []);

        $this->assertSame(['navigation_target'], $result->asFollowUpOnPreviousResult()->missingKeys);
        $this->assertSame(['navigation_target'], $result->withDocumentProbes(['en' => ['a probe']])->missingKeys);
        $this->assertSame(['navigation_target'], $result->withClarification(['a', 'b'], 'Which one?')->missingKeys);
    }
}
