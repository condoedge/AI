<?php

// tests/Unit/Services/Chat/AmbiguityDetectorTest.php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Services\Chat\AmbiguityDetector;
use Condoedge\Ai\Tests\TestCase;

class AmbiguityDetectorTest extends TestCase
{
    private AmbiguityDetector $detector;

    public function setUp(): void
    {
        parent::setUp();
        $this->detector = new AmbiguityDetector();
    }

    /** @test */
    public function it_detects_vague_questions(): void
    {
        $result = $this->detector->analyze('Show me data');

        $this->assertTrue($result['is_ambiguous']);
        $this->assertNotEmpty($result['clarification_questions']);
    }

    /** @test */
    public function it_passes_specific_questions(): void
    {
        $result = $this->detector->analyze('How many active customers do we have?');

        $this->assertFalse($result['is_ambiguous']);
    }

    /** @test */
    public function it_suggests_entity_clarification(): void
    {
        $result = $this->detector->analyze(
            'Show me the list',
            ['Customer', 'Order', 'Product'] // Available entities
        );

        $this->assertTrue($result['is_ambiguous']);
        $this->assertStringContainsString('Customer', $result['clarification_questions'][0]);
    }
}
