<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat\Inputs;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use PHPUnit\Framework\TestCase;

/**
 * AskQuestionInput carries one new nullable, default-null pageContext field so the
 * page-context thread stays purely additive: every existing call site that does not pass
 * it keeps working unchanged.
 */
class AskQuestionInputTest extends TestCase
{
    public function test_page_context_defaults_to_null(): void
    {
        // The existing 5-arg call shape must keep producing a null pageContext.
        $input = new AskQuestionInput(
            question: 'How many units?',
            conversation: new AiConversation(),
            style: 'friendly',
            user: (object) ['id' => 1],
            allowAnonymous: false,
        );

        $this->assertNull($input->pageContext);
    }

    public function test_page_context_is_carried_when_provided(): void
    {
        $page = new PageContext(entityType: 'Unit', entityId: 402, label: 'Unit 402');

        $input = new AskQuestionInput(
            question: 'Payment history?',
            conversation: new AiConversation(),
            pageContext: $page,
        );

        $this->assertInstanceOf(PageContext::class, $input->pageContext);
        $this->assertSame($page, $input->pageContext);
        $this->assertSame('Unit', $input->pageContext->entityType);
    }

    public function test_page_context_is_the_last_promoted_parameter_and_nullable(): void
    {
        // Reflection guards the additive contract: pageContext must be the trailing
        // parameter, nullable, and default null so it never breaks positional callers.
        $params = (new \ReflectionMethod(AskQuestionInput::class, '__construct'))->getParameters();
        $last = end($params);

        $this->assertSame('pageContext', $last->getName());
        $this->assertTrue($last->allowsNull());
        $this->assertTrue($last->isDefaultValueAvailable());
        $this->assertNull($last->getDefaultValue());
    }
}
