<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use Mockery;
use PHPUnit\Framework\TestCase;

class ActionLinkHandlerContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function action_links_receive_visible_answer_and_clean_document_context(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction(
            'export_pdf',
            fn($input, $context) => 'answer:' . $context->answer . '|document:' . $context->documentMarkdown,
            ['mode' => ['type' => 'string', 'default' => 'table']],
        ));

        $handler = new ActionLinkHandler(
            Mockery::mock(EntityAutoDiscovery::class),
            new ActionDispatcher($registry),
        );

        $elements = $handler->createElements(
            '[Download PDF](action://export_pdf?mode=document)',
            [
                'answer' => 'I prepared the PDF.',
                'document_markdown' => '# Clean document',
            ]
        );

        $this->assertSame(['answer:I prepared the PDF.|document:# Clean document'], $elements);
    }
}
