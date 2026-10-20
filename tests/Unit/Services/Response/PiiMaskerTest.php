<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Services\Response\PiiMasker;
use Condoedge\Ai\Tests\TestCase;

/**
 * PII masking for the LLM-facing prompt (owner decision, Phase 8 batch A):
 * the fields listed in ai.access_control.identifying_fields (+ entity-config
 * security.sensible_columns) never reach the response LLM as real values —
 * they become stable per-turn tokens ([email#1]) that code substitutes back.
 */
class PiiMaskerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.access_control.mask_identifying_fields' => true,
            'ai.access_control.identifying_fields' => [
                '*' => ['email', 'phone'],
                'Contact' => ['date_of_birth'],
            ],
        ]);
    }

    /** @test */
    public function configured_columns_are_masked_with_stable_per_value_tokens(): void
    {
        $masker = PiiMasker::fromConfig();

        $masked = $masker->maskRows([
            ['name' => 'Karen', 'email' => 'karen@x.test', 'phone' => '514-555-0101'],
            ['name' => 'Luc', 'email' => 'luc@x.test', 'phone' => null],
            ['name' => 'Karen again', 'email' => 'KAREN@x.test'],
        ]);

        $this->assertSame('Karen', $masked[0]['name']);
        $this->assertSame('[email#1]', $masked[0]['email']);
        $this->assertSame('[phone#1]', $masked[0]['phone']);
        $this->assertSame('[email#2]', $masked[1]['email']);
        $this->assertNull($masked[1]['phone'], 'null values stay null (nothing to hide)');
        $this->assertSame('[email#1]', $masked[2]['email'], 'the same value (case-insensitive) keeps its token');
    }

    /** @test */
    public function column_matching_is_by_name_segment_not_substring(): void
    {
        $masker = PiiMasker::fromConfig();

        $this->assertSame('email', $masker->maskedKind('member_email'));
        $this->assertSame('email', $masker->maskedKind('contactEmail'));
        $this->assertSame('email', $masker->maskedKind('emails'));
        $this->assertSame('phone', $masker->maskedKind('Phone'));
        $this->assertNull($masker->maskedKind('telephone'), 'segment match only — no substring hits');
        $this->assertNull($masker->maskedKind('name'));
    }

    /** @test P-2: the field must END the column name — counts/statuses/timestamps are not PII. */
    public function only_a_column_ending_with_the_field_is_masked(): void
    {
        $masker = PiiMasker::fromConfig();

        foreach (['phone_count', 'email_status', 'email_sent_at', 'emailVerifiedAt', 'phone_type'] as $column) {
            $this->assertNull($masker->maskedKind($column), "{$column} is not PII");
        }
        foreach (['email' => 'email', 'member_email' => 'email', 'contactEmail' => 'email', 'emails' => 'email', 'mobile_phone' => 'phone'] as $column => $kind) {
            $this->assertSame($kind, $masker->maskedKind($column));
        }
    }

    /** @test P-2 */
    public function numeric_values_are_never_masked_and_counts_never_collapse_into_one_token(): void
    {
        $masker = PiiMasker::fromConfig();

        $masked = $masker->maskRows([
            ['name' => 'A', 'phone' => 5145550101, 'phone_count' => 2],
            ['name' => 'B', 'phone' => '514-555-0102', 'phone_count' => 2],
        ]);

        $this->assertSame(5145550101, $masked[0]['phone']);
        $this->assertSame(2, $masked[0]['phone_count']);
        $this->assertSame(2, $masked[1]['phone_count']);
        $this->assertSame('[phone#1]', $masked[1]['phone']);
    }

    /** @test P-2: maskText respects word boundaries. */
    public function mask_text_only_replaces_whole_values(): void
    {
        $masker = PiiMasker::fromConfig();
        $masker->maskRows([['phone' => '555-0101']]);

        $this->assertSame('Call [phone#1] now', $masker->maskText('Call 555-0101 now'));
        $this->assertSame('Ref 1555-01019', $masker->maskText('Ref 1555-01019'));
    }

    /** @test P-1: the previous turn's answer text and sample are masked for QU / query-gen. */
    public function the_conversation_is_masked_with_one_turn_map(): void
    {
        config(['entities' => [
            'App\\Models\\Person' => ['graph' => ['label' => 'Person'], 'security' => ['sensible_columns' => ['ssn']]],
        ]]);
        $conversation = [
            'last_entity_types' => ['Person'],
            'last_result_sample' => [['name' => 'Karen', 'email' => 'karen@x.test', 'phone' => '514-555-0101', 'ssn' => '123-456-789']],
            'last_answer_summary' => 'Karen: karen@x.test, 514-555-0101',
            'recent_exchanges' => [
                ['role' => 'user', 'question' => 'Members with emails?', 'answer_summary' => null],
                ['role' => 'assistant', 'question' => null, 'answer_summary' => "| Karen | karen@x.test | 514-555-0101 |\n| Zoe | zoe@other.test |"],
            ],
        ];

        $masker = PiiMasker::forConversation($conversation);
        $masked = $masker->maskConversation($conversation);

        $flat = json_encode($masked);
        foreach (['karen@x.test', 'zoe@other.test', '514-555-0101', '123-456-789'] as $secret) {
            $this->assertStringNotContainsString($secret, $flat, "{$secret} leaked");
        }
        $this->assertSame('[email#1]', $masked['last_result_sample'][0]['email']);
        $this->assertStringContainsString('| Karen | [email#1] | [phone#1] |', $masked['recent_exchanges'][1]['answer_summary']);
        $this->assertSame('[ssn#1]', $masked['last_result_sample'][0]['ssn'], 'sensible_columns of last_entity_types apply');
        $this->assertSame('Members with emails?', $masked['recent_exchanges'][0]['question']);
    }

    /** @test P-1: a conversation without PII comes back unchanged. */
    public function a_pii_free_conversation_is_returned_unchanged(): void
    {
        $conversation = ['last_subject' => 'team', 'last_result_sample' => [['name' => 'Ana']]];

        $this->assertSame($conversation, PiiMasker::forConversation($conversation)->maskConversation($conversation));
    }

    /** @test P-1: a token in generated cypher becomes a bound parameter — never an inlined literal. */
    public function cypher_tokens_are_bound_as_parameters_carrying_the_real_value(): void
    {
        $masker = PiiMasker::fromConfig();
        $masker->maskRows([['email' => "o'brien@x.test"]]);

        [$cypher, $params] = $masker->bindCypher(
            "MATCH (u:User) WHERE u.email = '[email#1]' OR u.note CONTAINS 'mail [email#1] here' RETURN u"
        );

        $this->assertStringNotContainsString('[email#1]', $cypher);
        $this->assertStringNotContainsString("o'brien", $cypher, 'the literal is never inlined');
        $this->assertCount(1, $params);
        $name = array_key_first($params);
        $this->assertMatchesRegularExpression('/^pii_[a-f0-9]{12}$/', $name);
        $this->assertSame("o'brien@x.test", $params[$name]);
        $this->assertStringContainsString("u.email = \${$name}", $cypher);
        $this->assertStringContainsString("'mail ' + \${$name} + ' here'", $cypher);
    }

    /** @test Review #1: an odd backslash run before a token must not break quote parity. */
    public function a_token_after_an_odd_backslash_run_leaves_the_literal_untouched(): void
    {
        $masker = new PiiMasker(['email']);
        $masker->maskRows([['email' => 'a@b.co']]);
        $in = "MATCH (p) WHERE p.email = 'x\\[email#1]' AND p.team_id IN \$ai_team_ids RETURN p";

        [$cypher, $params] = $masker->bindCypher($in);

        $this->assertSame($in, $cypher, 'the literal (and the scoping clause after it) is untouched');
        $this->assertSame([], $params);
    }

    /** @test Review #2: comments and backtick identifiers never shift literal pairing. */
    public function comments_and_backticks_are_skipped_when_binding(): void
    {
        $masker = new PiiMasker(['email']);
        $masker->maskRows([['email' => 'a@b.co']]);

        [$cypher, $params] = $masker->bindCypher("MATCH (p) // owner's mail\nWHERE p.email = '[email#1]' AND p.n = 'z' RETURN p");
        $name = array_key_first($params);
        $this->assertSame("MATCH (p) // owner's mail\nWHERE p.email = \${$name} AND p.n = 'z' RETURN p", $cypher);
        $this->assertSame('a@b.co', $params[$name]);

        [$cypher] = $masker->bindCypher("MATCH (p) /* it's */ WHERE p.email = '[email#1]' RETURN p");
        $this->assertStringContainsString("/* it's */ WHERE p.email = \$pii_", $cypher);

        $ticks = 'MATCH (p:`[email#1]`) WHERE p.`[email#1]` = 1 RETURN {`[email#1]`: 1}';
        [$cypher, $params] = $masker->bindCypher($ticks);
        $this->assertSame($ticks, $cypher, 'backtick identifiers are never rewritten');
        $this->assertSame([], $params);

        [$cypher] = $masker->bindCypher("MATCH (p) WHERE p.note = 'it\\'s [email#1] \"q\"' RETURN p");
        $this->assertMatchesRegularExpression("/p\\.note = 'it\\\\'s ' \\+ \\\$pii_[a-f0-9]{12} \\+ ' \"q\"'/", $cypher);
    }

    /** @test P-1: an extended previous query keeps its earlier pii parameters. */
    public function previous_turn_pii_parameters_referenced_by_the_cypher_are_carried_over(): void
    {
        [$cypher, $params] = PiiMasker::fromConfig()->bindCypher(
            'MATCH (u:User) WHERE u.email = $pii_0123456789ab RETURN u.name',
            ['pii_0123456789ab' => 'karen@x.test', 'pii_ffffffffffff' => 'other@x.test']
        );

        $this->assertSame('MATCH (u:User) WHERE u.email = $pii_0123456789ab RETURN u.name', $cypher);
        $this->assertSame(['pii_0123456789ab' => 'karen@x.test'], $params);
    }

    /** @test */
    public function entity_specific_fields_apply_only_for_labels_in_the_turn(): void
    {
        $this->assertNull(PiiMasker::fromConfig()->maskedKind('date_of_birth'));
        $this->assertSame('date_of_birth', PiiMasker::fromConfig(['Contact'])->maskedKind('date_of_birth'));
    }

    /** @test */
    public function entity_config_sensible_columns_are_masked_too(): void
    {
        config(['entities' => [
            'App\\Models\\Person' => ['graph' => ['label' => 'Person'], 'security' => ['sensible_columns' => ['ssn']]],
        ]]);

        $this->assertSame('ssn', PiiMasker::fromConfig(['Person'])->maskedKind('ssn'));
    }

    /** @test */
    public function nested_lists_and_maps_are_masked_recursively(): void
    {
        $masker = PiiMasker::fromConfig();

        $masked = $masker->maskRows([[
            'team' => 'Board',
            'members' => [
                ['name' => 'Karen', 'email' => 'karen@x.test'],
                ['name' => 'Luc', 'email' => 'luc@x.test'],
            ],
            'emails' => ['karen@x.test', 'zoe@x.test'],
        ]]);

        $this->assertSame('[email#1]', $masked[0]['members'][0]['email']);
        $this->assertSame('[email#2]', $masked[0]['members'][1]['email']);
        $this->assertSame(['[email#1]', '[email#3]'], $masked[0]['emails']);
    }

    /** @test */
    public function an_email_shaped_value_under_an_unlisted_column_is_still_masked(): void
    {
        // Format check (a valid email address) tied to the configured 'email' field —
        // an LLM-chosen alias like `contact` must not smuggle the address through.
        $masker = PiiMasker::fromConfig();

        $masked = $masker->maskRows([['name' => 'Karen', 'contact' => 'karen@x.test']]);

        $this->assertSame('[email#1]', $masked[0]['contact']);
    }

    /** @test */
    public function unmask_restores_real_values_and_mask_text_replaces_known_values(): void
    {
        $masker = PiiMasker::fromConfig();
        $masker->maskRows([['email' => 'karen@x.test']]);

        $this->assertSame('Write to karen@x.test.', $masker->unmask('Write to [email#1].'));
        $this->assertSame('Write to [email#1].', $masker->maskText('Write to Karen@X.test.'));
        $this->assertSame('[email#1]', $masker->tokenForValue('karen@x.test'));
        $this->assertNull($masker->tokenForValue('nobody@x.test'));
    }

    /** @test */
    public function mask_field_tokenizes_a_value_only_when_the_field_is_configured(): void
    {
        $masker = PiiMasker::fromConfig();

        $this->assertSame('[email#1]', $masker->maskField('email', 'me@x.test'));
        $this->assertSame('Karen', $masker->maskField('name', 'Karen'));
    }

    /** @test */
    public function the_kill_switch_disables_masking(): void
    {
        config(['ai.access_control.mask_identifying_fields' => false]);
        $masker = PiiMasker::fromConfig();

        $rows = [['email' => 'karen@x.test']];
        $this->assertSame($rows, $masker->maskRows($rows));
        $this->assertSame('karen@x.test', $masker->maskField('email', 'karen@x.test'));
    }

    /** @test */
    public function the_turn_masker_is_memoized_on_the_context_and_reads_cypher_labels(): void
    {
        $ctx = new PipelineContext();
        $ctx->queryResult = new QueryGenerationResult(cypher: 'MATCH (c:Contact) RETURN c.date_of_birth AS dob_date_of_birth');

        $masker = PiiMasker::for($ctx);

        $this->assertSame($masker, PiiMasker::for($ctx));
        $this->assertSame('date_of_birth', $masker->maskedKind('dob_date_of_birth'));
    }
}
