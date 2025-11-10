<?php

declare(strict_types=1);

namespace AiSystem\Tests\Unit\Services;

use AiSystem\Tests\TestCase;
use AiSystem\Services\PatternLibrary;

class PatternLibraryMinimalTest extends TestCase
{
    /** @test */
    public function it_can_be_instantiated()
    {
        $library = new PatternLibrary([]);

        $this->assertInstanceOf(PatternLibrary::class, $library);
    }
}
