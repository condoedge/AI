<?php

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Services\Discovery\InheritanceResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class InheritanceResolverTest extends TestCase
{
    private InheritanceResolver $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new InheritanceResolver();
    }

    /** @test */
    public function it_keeps_single_model_for_table(): void
    {
        $models = [InheritanceTestPerson::class];

        $result = $this->resolver->resolve($models);

        $this->assertCount(1, $result['models']);
        $this->assertEquals(InheritanceTestPerson::class, $result['models'][0]);
        $this->assertEmpty($result['inheritance']);
    }

    /** @test */
    public function it_identifies_parent_model_when_child_extends(): void
    {
        $models = [InheritanceTestPerson::class, InheritanceTestVolunteer::class];

        $result = $this->resolver->resolve($models);

        // Should only have the parent
        $this->assertCount(1, $result['models']);
        $this->assertEquals(InheritanceTestPerson::class, $result['models'][0]);

        // Should have inheritance info
        $this->assertArrayHasKey(InheritanceTestPerson::class, $result['inheritance']);
        $this->assertContains(InheritanceTestVolunteer::class, $result['inheritance'][InheritanceTestPerson::class]['children']);
    }

    /** @test */
    public function it_extracts_child_class_as_alias(): void
    {
        $models = [InheritanceTestPerson::class, InheritanceTestVolunteer::class];

        $result = $this->resolver->resolve($models);

        $aliases = $result['inheritance'][InheritanceTestPerson::class]['child_aliases'];

        $this->assertContains('inheritancetestvolunteer', $aliases);
    }

    /** @test */
    public function it_extracts_scopes_from_child_model(): void
    {
        $models = [InheritanceTestPerson::class, InheritanceTestVolunteerWithScope::class];

        $result = $this->resolver->resolve($models);

        $scopes = $result['inheritance'][InheritanceTestPerson::class]['child_scopes'];

        $this->assertArrayHasKey('active', $scopes);
        $this->assertEquals('active', $scopes['active']['name']);
    }

    /** @test */
    public function it_extracts_cypher_pattern_from_global_scope(): void
    {
        $models = [InheritanceTestPerson::class, InheritanceTestVolunteer::class];

        $result = $this->resolver->resolve($models);

        // Should have global scope with cypher_pattern
        $globalScopes = $result['inheritance'][InheritanceTestPerson::class]['child_global_scopes'];

        $this->assertArrayHasKey('volunteer', $globalScopes);
        $this->assertEquals('n.role_type = 3', $globalScopes['volunteer']['cypher_pattern']);
        $this->assertEquals('global_scope', $globalScopes['volunteer']['type']);
        $this->assertEquals(InheritanceTestVolunteer::class, $globalScopes['volunteer']['source_model']);
    }

    /** @test */
    public function it_merges_inheritance_info_into_config(): void
    {
        $parentConfig = [
            'graph' => ['label' => 'Person'],
            'vector' => ['collection' => 'persons'],
            'metadata' => [
                'aliases' => ['person', 'people'],
                'scopes' => [],
            ],
        ];

        $inheritanceInfo = [
            'children' => [InheritanceTestVolunteer::class],
            'child_aliases' => ['volunteer', 'volunteers'],
            'child_scopes' => [
                'active' => ['name' => 'active', 'source_model' => InheritanceTestVolunteer::class],
            ],
            'child_global_scopes' => [],
        ];

        $merged = $this->resolver->mergeInheritanceInfo($parentConfig, $inheritanceInfo);

        // Aliases should be merged
        $this->assertContains('person', $merged['metadata']['aliases']);
        $this->assertContains('volunteer', $merged['metadata']['aliases']);

        // Scopes should be merged
        $this->assertArrayHasKey('active', $merged['metadata']['scopes']);

        // Child models should be stored
        $this->assertContains(InheritanceTestVolunteer::class, $merged['metadata']['child_models']);
    }

    /** @test */
    public function it_handles_multiple_children(): void
    {
        $models = [InheritanceTestPerson::class, InheritanceTestVolunteer::class, InheritanceTestScout::class];

        $result = $this->resolver->resolve($models);

        // Should only have the parent
        $this->assertCount(1, $result['models']);

        // Should have both children
        $children = $result['inheritance'][InheritanceTestPerson::class]['children'];
        $this->assertCount(2, $children);
        $this->assertContains(InheritanceTestVolunteer::class, $children);
        $this->assertContains(InheritanceTestScout::class, $children);
    }
}

// Test fixtures - Parent
class InheritanceTestPerson extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $table = 'persons';
    protected $fillable = ['name', 'email'];
}

// Child with global scope
class InheritanceTestVolunteer extends InheritanceTestPerson
{
    public function getGlobalScopes()
    {
        return ['volunteer' => fn($q) => $q->where('role_type', 3)];
    }
}

// Child with query scope
class InheritanceTestVolunteerWithScope extends InheritanceTestPerson
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}

// Another child
class InheritanceTestScout extends InheritanceTestPerson
{
    public function getGlobalScopes()
    {
        return ['scout' => fn($q) => $q->where('role_type', 4)];
    }
}
