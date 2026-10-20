<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\ActionKind;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\BuiltIn\AskFollowUpAction;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use PHPUnit\Framework\TestCase;

/**
 * The follow-up question chip ("ask" chip): a built-in, read-only action whose click SENDS
 * its question as the user's next message. Pinned here (pure PHPUnit, no Kompo):
 *
 *  - the typed contract: key, schema {question}, non-mutating, any authenticated user;
 *  - the ONE sanitizer every follow-up question goes through (single line, markdown and
 *    control characters stripped, length-capped, empty = no chip);
 *  - the registry resolves it for dispatch but never LISTS it: no prompt section or
 *    advertisement surface offers it to a model.
 */
class AskFollowUpActionTest extends TestCase
{
    /** @test */
    public function it_is_a_read_only_action_with_one_question_param(): void
    {
        $action = new AskFollowUpAction();

        $this->assertSame('ask_follow_up', $action->key());
        $this->assertSame(AskFollowUpAction::KEY, $action->key());
        $this->assertFalse($action->mutates());
        $this->assertSame(['question'], array_keys($action->inputSchema()));
    }

    /** @test */
    public function any_authenticated_user_may_use_it_and_a_guest_may_not(): void
    {
        $action = new AskFollowUpAction();

        $this->assertTrue($action->authorize((object) ['id' => 40], ['question' => 'What about pets?']));
        $this->assertTrue($action->authorize((object) ['id' => 40], []), 'advertisable before any question exists');
        $this->assertFalse($action->authorize(null, ['question' => 'What about pets?']));
    }

    /** @test */
    public function handling_returns_the_question_and_its_label_as_a_follow_up_intent(): void
    {
        $result = (new AskFollowUpAction())->handle(
            ['question' => 'the balance of my unit'],
            ActionContext::fromArray(['text' => 'The balance of my unit'])
        );

        $this->assertFalse($result->isError);
        $this->assertSame(ActionKind::FollowUp, $result->kind);
        $this->assertSame(['question' => 'the balance of my unit', 'label' => 'The balance of my unit'], $result->payload);
    }

    /** @test */
    public function the_label_defaults_to_the_question(): void
    {
        $result = (new AskFollowUpAction())->handle(['question' => 'Who pays for the roof?'], new ActionContext());

        $this->assertSame(['question' => 'Who pays for the roof?', 'label' => 'Who pays for the roof?'], $result->payload);
    }

    /** @test */
    public function an_empty_question_is_an_error_that_renders_nothing(): void
    {
        foreach ([[], ['question' => ''], ['question' => " \n\t "], ['question' => '**``**']] as $input) {
            $this->assertTrue((new AskFollowUpAction())->handle($input, new ActionContext())->isError);
        }
    }

    /**
     * @test The persisted record is data, never authority: handle() re-sanitizes what it is given.
     */
    public function handling_sanitizes_a_stored_question_again(): void
    {
        $result = (new AskFollowUpAction())->handle(
            ['question' => "**Who** pays\nfor the `roof`?"],
            ActionContext::fromArray(['text' => "# Who pays\r\nfor the roof?"])
        );

        $this->assertSame(['question' => 'Who pays for the roof?', 'label' => 'Who pays for the roof?'], $result->payload);
    }

    /**
     * @test
     * @dataProvider sanitized
     */
    public function the_sanitizer_yields_one_plain_line(string $raw, string $expected): void
    {
        $this->assertSame($expected, AskFollowUpAction::sanitize($raw));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function sanitized(): array
    {
        return [
            'plain' => ['What about pets?', 'What about pets?'],
            'surrounding space' => ["  What about pets? \n", 'What about pets?'],
            'line breaks and tabs' => ["What\tabout\r\npets?", 'What about pets?'],
            'markdown emphasis and code' => ['**What** about `pets`?', 'What about pets?'],
            'heading marker' => ['## What about pets?', 'What about pets?'],
            'list marker' => ['- What about pets?', 'What about pets?'],
            'numbered marker' => ['2. What about pets?', 'What about pets?'],
            'markdown link keeps its text' => ['What about [pets](https://evil.example/x)?', 'What about pets?'],
            'table pipes' => ['| What about pets? |', 'What about pets?'],
            'html-ish markup' => ['What about <b>pets</b>?', 'What about pets?'],
            'hostile markup' => ['"</script><img src=x onerror=alert(1)>', ''],
            'wrapping quotes' => ['"What about pets?"', 'What about pets?'],
            'guillemets' => ['« Et les animaux ? »', 'Et les animaux ?'],
            'control and zero-width characters' => ["What\u{0000} about\u{200B} pets\u{0007}?", 'What about pets ?'],
            'accents kept' => ['Qui paie les réparations de l’immeuble ?', 'Qui paie les réparations de l’immeuble ?'],
            'apostrophes and parentheses kept' => ["What's the owner's share (in %)?", "What's the owner's share (in %)?"],
            'only markup' => ['**`#`**', ''],
            'empty' => ['', ''],
        ];
    }

    /** @test */
    public function the_sanitizer_caps_the_length_on_a_word_boundary(): void
    {
        $long = trim(str_repeat('word ', 80)) . '?';

        $clean = AskFollowUpAction::sanitize($long);

        $this->assertLessThanOrEqual(AskFollowUpAction::MAX_LENGTH, mb_strlen($clean));
        $this->assertStringEndsWith('word…', $clean);
        $this->assertStringNotContainsString("\n", $clean);
    }

    /** @test */
    public function the_invocation_factory_sanitizes_and_drops_an_empty_question(): void
    {
        $invocation = AskFollowUpAction::invocation(" **Who** pays\nfor the roof? ");

        $this->assertInstanceOf(ActionInvocation::class, $invocation);
        $this->assertSame('ask_follow_up', $invocation->key);
        $this->assertNull($invocation->entityType);
        $this->assertSame(['question' => 'Who pays for the roof?'], $invocation->params);
        $this->assertSame('Who pays for the roof?', $invocation->text, 'the label is the question itself');

        $labelled = AskFollowUpAction::invocation('the balance of my unit', 'The balance of my unit');
        $this->assertSame('the balance of my unit', $labelled->params['question']);
        $this->assertSame('The balance of my unit', $labelled->text);

        $this->assertNull(AskFollowUpAction::invocation(''));
        $this->assertNull(AskFollowUpAction::invocation(" \n "));
        $this->assertNull(AskFollowUpAction::invocation('`*`'));
    }

    /** @test Two different questions are two invocations (the fingerprint includes the params). */
    public function two_questions_have_two_fingerprints(): void
    {
        $a = AskFollowUpAction::invocation('Who pays for the roof?');
        $b = AskFollowUpAction::invocation('Who pays for the windows?');
        $again = AskFollowUpAction::invocation('  Who pays for the roof? ');

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
        $this->assertSame($a->fingerprint(), $again->fingerprint());
    }

    /** @test */
    public function the_registry_resolves_it_for_dispatch_but_never_lists_it(): void
    {
        $registry = new AiActionRegistry();

        $this->assertInstanceOf(AskFollowUpAction::class, $registry->getGeneric('ask_follow_up'));
        $this->assertNotContains(
            'ask_follow_up',
            array_map(static fn ($action) => $action->key(), $registry->genericActions()),
            'never listed: prompt sections and advertisement surfaces read genericActions()'
        );
        $this->assertNotContains(
            'ask_follow_up',
            array_map(static fn ($action) => $action->key(), $registry->relevantActions(null)['generic'])
        );
    }

    /** @test */
    public function a_host_action_registered_under_the_same_key_wins(): void
    {
        $registry = new AiActionRegistry();
        $host = new CapturingAction('ask_follow_up', fn () => 'host');
        $registry->registerGeneric($host);

        $this->assertSame($host, $registry->getGeneric('ask_follow_up'));
    }

    /** @test */
    public function without_built_ins_the_registry_does_not_know_it(): void
    {
        $this->assertNull((new AiActionRegistry(false))->getGeneric('ask_follow_up'));
    }

    /** @test The shared dispatcher: schema-filtered params, authorize, then the follow-up intent. */
    public function dispatch_filters_params_and_authorizes(): void
    {
        $registry = new AiActionRegistry();
        $dispatcher = new ActionDispatcher($registry);
        $action = $registry->getGeneric('ask_follow_up');

        $result = $dispatcher->dispatch($action, ['question' => 'Who pays for the roof?', 'evil' => 'x'], [
            'user' => (object) ['id' => 40],
            'text' => 'Who pays for the roof?',
        ]);
        $this->assertSame(ActionKind::FollowUp, $result->kind);
        $this->assertSame('Who pays for the roof?', $result->payload['question']);

        $denied = $dispatcher->dispatch($action, ['question' => 'Who pays for the roof?'], ['user' => null]);
        $this->assertTrue($denied->isError, 'a guest gets no chip');
    }
}
