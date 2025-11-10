<?php

namespace AiSystem\Tests\Unit\Domain\ValueObjects;

use AiSystem\Domain\ValueObjects\VectorConfig;
use AiSystem\Tests\TestCase;

class VectorConfigTest extends TestCase
{
    public function test_can_create_vector_config()
    {
        $config = new VectorConfig(
            collection: 'customers',
            embedFields: ['name', 'description'],
            metadata: ['id', 'email']
        );

        $this->assertEquals('customers', $config->collection);
        $this->assertEquals(['name', 'description'], $config->embedFields);
        $this->assertEquals(['id', 'email'], $config->metadata);
    }

    public function test_throws_exception_for_empty_collection()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Vector collection name cannot be empty');

        new VectorConfig(
            collection: '',
            embedFields: ['name'],
            metadata: []
        );
    }

    public function test_throws_exception_for_empty_embed_fields()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Embed fields cannot be empty');

        new VectorConfig(
            collection: 'customers',
            embedFields: [],
            metadata: []
        );
    }

    public function test_can_create_from_array()
    {
        $array = [
            'collection' => 'people',
            'embed_fields' => ['first_name', 'last_name', 'bio'],
            'metadata' => ['id', 'email']
        ];

        $config = VectorConfig::fromArray($array);

        $this->assertEquals('people', $config->collection);
        $this->assertEquals(['first_name', 'last_name', 'bio'], $config->embedFields);
        $this->assertEquals(['id', 'email'], $config->metadata);
    }

    public function test_can_create_from_array_with_camel_case()
    {
        $array = [
            'collection' => 'people',
            'embedFields' => ['name'],
            'metadata' => []
        ];

        $config = VectorConfig::fromArray($array);

        $this->assertEquals(['name'], $config->embedFields);
    }

    public function test_get_separator()
    {
        $config = new VectorConfig(
            collection: 'test',
            embedFields: ['field1'],
            metadata: []
        );

        $this->assertEquals(' ', $config->getSeparator());
    }
}
