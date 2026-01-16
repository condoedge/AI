<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Contracts;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Tests\TestCase;

class AiAuthAdapterInterfaceTest extends TestCase
{
    /** @test */
    public function interface_exists_with_required_methods(): void
    {
        $this->assertTrue(interface_exists(AiAuthAdapterInterface::class));

        $reflection = new \ReflectionClass(AiAuthAdapterInterface::class);

        $this->assertTrue($reflection->hasMethod('getTeamsWithPermission'));
        $this->assertTrue($reflection->hasMethod('hasGlobalCountPermission'));
        $this->assertTrue($reflection->hasMethod('isEnabled'));
    }
}
