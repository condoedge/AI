<?php

namespace Condoedge\Ai\Tests\Unit\Config;

use Condoedge\Ai\Tests\TestCase;

class FileContextConfigTest extends TestCase
{
    /** @test */
    public function it_has_file_context_config_section(): void
    {
        $config = config('ai.file_context');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('security_enabled', $config);
        $this->assertArrayHasKey('physical_paths', $config);
        $this->assertArrayHasKey('supported_extensions', $config);
        $this->assertArrayHasKey('base_path', $config);
        $this->assertArrayHasKey('physical_collection', $config);
        $this->assertArrayHasKey('max_references', $config);
        $this->assertArrayHasKey('min_relevance_score', $config);
        $this->assertArrayHasKey('include_snippets', $config);
        $this->assertArrayHasKey('snippet_length', $config);
        $this->assertArrayHasKey('file_model', $config);
        $this->assertArrayHasKey('access_scope', $config);
        $this->assertArrayHasKey('access_resolver', $config);
    }

    /** @test */
    public function physical_paths_supports_glob_patterns(): void
    {
        config(['ai.file_context.physical_paths' => [
            'docs/**/*.mdx',
            'guides/*.md',
        ]]);

        $paths = config('ai.file_context.physical_paths');

        $this->assertCount(2, $paths);
        $this->assertContains('docs/**/*.mdx', $paths);
        $this->assertContains('guides/*.md', $paths);
    }

    /** @test */
    public function security_can_be_disabled_globally(): void
    {
        config(['ai.file_context.security_enabled' => false]);

        $this->assertFalse(config('ai.file_context.security_enabled'));
    }

    /** @test */
    public function file_model_is_configurable(): void
    {
        $this->assertEquals('App\\Models\\File', config('ai.file_context.file_model'));

        config(['ai.file_context.file_model' => 'App\\Models\\CustomFile']);

        $this->assertEquals('App\\Models\\CustomFile', config('ai.file_context.file_model'));
    }

    /** @test */
    public function access_scope_is_configurable(): void
    {
        $this->assertEquals('accessibleBy', config('ai.file_context.access_scope'));

        config(['ai.file_context.access_scope' => 'customScope']);

        $this->assertEquals('customScope', config('ai.file_context.access_scope'));
    }

    /** @test */
    public function default_values_are_correct(): void
    {
        $config = config('ai.file_context');

        $this->assertTrue($config['enabled']);
        $this->assertTrue($config['security_enabled']);
        $this->assertIsArray($config['physical_paths']);
        $this->assertEmpty($config['physical_paths']);
        $this->assertEquals(['md', 'mdx', 'txt', 'rst'], $config['supported_extensions']);
        $this->assertEquals('documentation_chunks', $config['physical_collection']);
        $this->assertEquals(5, $config['max_references']);
        $this->assertEquals(0.7, $config['min_relevance_score']);
        $this->assertTrue($config['include_snippets']);
        $this->assertEquals(200, $config['snippet_length']);
        $this->assertNull($config['access_resolver']);
    }
}
