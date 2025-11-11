<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\PatternLibrary;

class PatternLibraryMinimalTest extends TestCase
{
    /** @test */
    public function it_can_be_instantiated()
    {
        $library = new PatternLibrary([]);

        $this->assertInstanceOf(PatternLibrary::class, $library);
    }
}
