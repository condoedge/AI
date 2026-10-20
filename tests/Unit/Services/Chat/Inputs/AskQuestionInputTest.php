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

    public function test_page_context_keeps_its_position_and_only_optional_parameters_follow(): void
    {
        // Reflection guards the additive contract: pageContext stays the 6th parameter,
        // nullable and default null, and everything after it is optional, so no
        // positional caller ever breaks.
        $params = (new \ReflectionMethod(AskQuestionInput::class, '__construct'))->getParameters();
        $page = $params[5];

        $this->assertSame('pageContext', $page->getName());
        $this->assertTrue($page->allowsNull());
        $this->assertTrue($page->isDefaultValueAvailable());
        $this->assertNull($page->getDefaultValue());

        foreach (array_slice($params, 6) as $param) {
            $this->assertTrue($param->isDefaultValueAvailable(), "{$param->getName()} must be optional");
        }
    }

    public function test_the_extra_observer_and_pipeline_options_default_to_nothing(): void
    {
        $input = new AskQuestionInput(question: 'q', conversation: new AiConversation());

        $this->assertNull($input->observer);
        $this->assertSame([], $input->pipelineOptions);
    }
}
