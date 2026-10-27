<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\PageContextSection;
use Condoedge\Ai\Services\ResponseSections\ResponsePageContextSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * A page the chat captured carries a `page_name` (its navigation label, '' when it has none): the
 * prompts name the page by it and never print the route — the model repeated "org.manage" to users.
 * A page context without `page_name` (a host's own) is unchanged.
 */
class PageNameInPromptsTest extends TestCase
{
    private function ctx(array $page): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->pageContext = $page;

        return $ctx;
    }

    private function response(array $page): string
    {
        $section = new ResponsePageContextSection();

        return $section->shouldInclude($this->ctx($page)) ? $section->format($this->ctx($page)) : '';
    }

    private function understanding(array $page): string
    {
        return (new PageContextSection())->format('q', $this->ctx($page));
    }

    /** @test */
    public function a_named_page_is_named_and_its_route_never_printed(): void
    {
        $page = ['route' => 'org.manage', 'page_name' => 'Mon organisme'];

        foreach ([$this->response($page), $this->understanding($page)] as $prompt) {
            $this->assertStringContainsString('Mon organisme', $prompt);
            $this->assertStringNotContainsString('org.manage', $prompt);
        }
    }

    /** @test */
    public function a_captured_page_without_a_name_prints_nothing_about_the_route(): void
    {
        $page = ['route' => 'campaign.page.reports', 'page_name' => ''];

        $this->assertSame('', $this->response($page));
        $this->assertStringNotContainsString('campaign.page.reports', $this->understanding($page));
    }

    /** @test */
    public function a_hosts_page_context_without_page_name_still_prints_its_route(): void
    {
        $this->assertStringContainsString('route: orders.show', $this->response(['route' => 'orders.show']));
        $this->assertStringContainsString('Current screen (route): orders.show.', $this->understanding(['route' => 'orders.show']));
    }

    /** @test */
    public function the_dto_keeps_page_name_only_when_given(): void
    {
        $this->assertSame('Produits', PageContext::fromArray(['route' => 'x', 'page_name' => 'Produits'])->toArray()['page_name']);
        $this->assertArrayNotHasKey('page_name', PageContext::fromArray(['route' => 'x'])->toArray());
    }
}
