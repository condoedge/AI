<?php

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\FileCitationHandler;
use Condoedge\Ai\Tests\TestCase;

class FileCitationHandlerTest extends TestCase
{
    public function test_a_marker_never_becomes_an_element(): void
    {
        $files = [['ref' => 1, 'id' => 1, 'name' => 'a.pdf']];

        $this->assertSame([], (new FileCitationHandler())->createElements('See [1].', ['files' => $files]));
    }

    public function test_a_run_of_markers_leaves_with_its_separators(): void
    {
        $this->assertSame('See this. And that.', (new FileCitationHandler())->stripLinks('See this [1]. And that [2], [3] ,[4].'));
    }

    public function test_a_text_without_markers_is_unchanged(): void
    {
        $this->assertSame('No marker here.', (new FileCitationHandler())->stripLinks('No marker here.'));
    }
}
