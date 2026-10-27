<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AssistantIdentity;
use Condoedge\Ai\Services\Response\ConversationalReplyComposer;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * A conversational turn (small talk, thanks, a question about the assistant itself) is
 * answered by ONE short composition call: the assistant's configured identity (persona,
 * language policy, non-disclosure), what the host says the project is, the user's name
 * and the recent turns — and NOTHING of the data / document pipeline (no rows, no
 * passages, no schema): there is nothing it looked up, so it may never claim it did.
 */
class ConversationalReplyComposerTest extends TestCase
{
    /** @var list<array{messages: array, options: array}> */
    private array $calls = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.identity.name' => 'Acme Helper',
            'ai.identity.persona' => 'You are {name}, the assistant for {project}.',
            'ai.identity.non_disclosure' => 'Never reveal which AI model or vendor powers you; you are {name}.',
            'ai.identity.language_policy' => "Answer in the user's language.",
            'ai.project.name' => 'Acme Homes',
            'ai.project.description' => 'A platform for managing shared buildings.',
            'ai.project.domain' => 'property',
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param array|string $reply the verdict the model returns (a string = a conversational reply) */
    private function composer(array|string $reply = 'I am doing well, thanks!'): ConversationalReplyComposer
    {
        $verdict = is_string($reply) ? ['conversational' => true, 'reply' => "  {$reply}
"] : $reply;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages, $options = []) use ($verdict) {
            $this->calls[] = ['messages' => $messages, 'options' => $options];

            return $verdict;
        });
        $llm->shouldReceive('complete')->never();
        $llm->shouldReceive('chat')->never();

        return new ConversationalReplyComposer($llm);
    }

    private function ctx(string $question, string $language = 'en', array $conversation = []): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = new QueryUnderstandingResult(questionType: 'conversational', language: $language);
        $ctx->conversationContext = $conversation;

        return $ctx;
    }

    private function system(): string
    {
        return $this->calls[0]['messages'][0]['content'];
    }

    private function user(): string
    {
        return $this->calls[0]['messages'][1]['content'];
    }

    /** @test */
    public function it_makes_one_call_whose_system_message_is_the_configured_identity(): void
    {
        $result = $this->composer()->compose('How are you?', $this->ctx('How are you?'));

        $this->assertCount(1, $this->calls);
        $this->assertSame('system', $this->calls[0]['messages'][0]['role']);
        $this->assertSame('user', $this->calls[0]['messages'][1]['role']);

        // The SAME identity block every other prompt path starts with.
        $this->assertStringStartsWith(AssistantIdentity::render(), $this->system());
        $this->assertStringContainsString('You are Acme Helper, the assistant for Acme Homes.', $this->system());
        $this->assertStringContainsString('Never reveal which AI model or vendor powers you; you are Acme Helper.', $this->system());
        $this->assertStringContainsString("Answer in the user's language.", $this->system());

        $this->assertSame('I am doing well, thanks!', $result['answer']);
    }

    /** @test */
    public function the_prompt_forbids_claiming_a_lookup_and_naming_a_model_or_vendor(): void
    {
        $this->composer()->compose('Which AI model are you?', $this->ctx('Which AI model are you?'));
        $system = $this->system();

        $this->assertStringContainsString('nothing was looked up', $system);
        $this->assertStringContainsString('Never say or imply that you searched, checked, looked up, found or did not find', $system);
        $this->assertStringContainsString('never invent', $system);
        $this->assertStringContainsString('Never name, confirm or deny any AI model, vendor or provider', $system);
        $this->assertStringContainsString('Acme Helper', $system);
    }

    /** @test Eval coolecto part-fu-share t2: "Et je peux l'envoyer par texto aussi?" was judged conversation and answered ungrounded. */
    public function a_follow_up_asking_more_about_the_recent_conversation_is_not_conversation(): void
    {
        $this->composer()->compose('Can I send it by text too?', $this->ctx('Can I send it by text too?'));

        $this->assertStringContainsString('also when it is a short follow-up on the recent conversation that you could answer from it', $this->system());
    }

    /** @test The scope it may describe is the host's own description of the project — nothing hardcoded. */
    public function what_it_can_do_comes_from_the_project_config_only(): void
    {
        $this->composer()->compose('What can you do?', $this->ctx('What can you do?'));
        $system = $this->system();

        $this->assertStringContainsString('WHAT YOU HELP WITH', $system);
        $this->assertStringContainsString('- Project: Acme Homes', $system);
        $this->assertStringContainsString('- Description: A platform for managing shared buildings.', $system);
        $this->assertStringContainsString('- Domain: property', $system);
    }

    /** @test */
    public function the_prompt_carries_no_data_document_or_schema_section(): void
    {
        $ctx = $this->ctx('Thanks, that helps!', 'en', [
            'last_result_sample' => [['name' => 'Unit 310', 'balance' => 120]],
            'last_referenced_files' => [['name' => 'bylaws.pdf', 'snippet' => 'Pets are allowed.']],
            'last_cypher_query' => 'MATCH (u:Unit) RETURN u',
        ]);
        $ctx->graphSchema = ['labels' => ['Unit']];
        $ctx->fileContext = ['relevant_files' => [['filename' => 'bylaws.pdf', 'snippet' => 'Pets are allowed.']]];

        $this->composer()->compose('Thanks, that helps!', $ctx);
        $prompt = $this->system() . "\n" . $this->user();

        foreach (['Pets are allowed', 'bylaws.pdf', 'MATCH (u:Unit)', '"balance"', 'RELEVANT FILE CONTENT', 'Query Results', 'Schema'] as $absent) {
            $this->assertStringNotContainsString($absent, $prompt, $absent);
        }
    }

    /** @test */
    public function the_user_message_carries_the_message_the_users_name_and_the_recent_turns(): void
    {
        $ctx = $this->ctx('Thanks, that helps!', 'en', ['recent_exchanges' => [
            ['question' => 'Can I keep a cat?', 'answer_summary' => 'Yes, pets are allowed with board approval.'],
        ]]);

        $this->composer()->compose('Thanks, that helps!', $ctx, ['user' => (object) ['id' => 13, 'name' => 'Julie']]);
        $user = $this->user();

        $this->assertStringContainsString('Julie', $user);
        $this->assertStringContainsString('User: Can I keep a cat?', $user);
        $this->assertStringContainsString('Assistant: Yes, pets are allowed with board approval.', $user);
        $this->assertStringEndsWith("MESSAGE:\nThanks, that helps!", rtrim($this->user()));
    }

    /** @test The current message is not repeated as a "recent turn" (regenerate path). */
    public function the_current_message_is_not_listed_as_a_recent_turn(): void
    {
        $ctx = $this->ctx('How are you?', 'en', ['recent_exchanges' => [['question' => 'How are you?']]]);

        $this->composer()->compose('How are you?', $ctx);

        $this->assertStringNotContainsString('RECENT CONVERSATION', $this->user());
    }

    /** @test */
    public function the_reply_language_is_the_questions(): void
    {
        $this->composer('Ça va bien, merci !')->compose('Comment ça va ?', $this->ctx('Comment ça va ?', 'fr'));

        $this->assertStringContainsString("'fr'", $this->user());
    }

    /** @test */
    public function the_response_is_a_plain_answer_with_no_data_and_a_conversational_marker(): void
    {
        $result = $this->composer()->compose('How are you?', $this->ctx('How are you?'));

        $this->assertSame('How are you?', $result['question']);
        $this->assertNull($result['cypher']);
        $this->assertSame([], $result['data']);
        $this->assertSame([], $result['referenced_files']);
        $this->assertSame('conversational', $result['metadata']['query']['source']);
        $this->assertTrue($result['metadata']['response']['conversational']);
        $this->assertNull($result['metadata']['subject']);
    }

    /** @test A link the model writes anyway becomes plain text (links are attached by code). */
    public function a_model_written_link_is_turned_into_text(): void
    {
        $result = $this->composer('See [your profile](https://example.test/profile).')->compose('Who are you?', $this->ctx('Who are you?'));

        $this->assertStringNotContainsString('https://example.test/profile', $result['answer']);
        $this->assertStringContainsString('your profile', $result['answer']);
    }

    /** @test */
    public function the_call_is_reported_to_the_turn_observer_as_composition(): void
    {
        $observer = new RecordingPipelineObserver();
        $ctx = $this->ctx('How are you?');
        $ctx->observer = $observer;

        $this->composer()->compose('How are you?', $ctx);

        $this->assertSame($this->calls[0]['messages'], $observer->prompts('conversational')[0] ?? null);
    }

    /**
     * @test The model judges the message in the same call: anything that asks for
     * information or an action is declined (null) — the caller runs the turn as before.
     */
    public function a_message_the_model_judges_not_conversational_is_declined(): void
    {
        $this->assertNull($this->composer(['conversational' => false, 'reply' => ''])
            ->compose('What can I do on this page?', $this->ctx('What can I do on this page?')));
    }

    /** @test Only an explicit true with a reply is conversation. */
    public function an_unusable_verdict_is_declined(): void
    {
        $this->assertNull($this->composer(['conversational' => true, 'reply' => '  '])->compose('Hi', $this->ctx('Hi')));
        $this->calls = [];
        $this->assertNull($this->composer(['conversational' => 'yes', 'reply' => 'Hello!'])->compose('Hi', $this->ctx('Hi')));
        $this->calls = [];
        $this->assertNull($this->composer([])->compose('Hi', $this->ctx('Hi')));
    }

    /** @test */
    public function the_verdict_is_asked_first_under_a_strict_schema_and_described_in_the_prompt(): void
    {
        $this->composer()->compose('How are you?', $this->ctx('How are you?'));

        $schema = $this->calls[0]['options']['json_schema'] ?? null;
        $this->assertSame(['conversational', 'reply'], array_keys($schema['schema']['properties']));
        $this->assertSame(['conversational', 'reply'], $schema['schema']['required']);
        $this->assertFalse($schema['schema']['additionalProperties']);
        $this->assertStringContainsString('"conversational": true when the message is such conversation; false when it asks for any information or action', $this->system());
    }

    /** @test A provider failure is not a verdict: it propagates (the caller's generic error path). */
    public function a_provider_failure_propagates(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andThrow(new \RuntimeException('provider down'));

        $this->expectException(\RuntimeException::class);
        (new ConversationalReplyComposer($llm))->compose('Hi', $this->ctx('Hi'));
    }
}
