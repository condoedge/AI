<?php

namespace AiSystem\Tests\Unit\Domain\ValueObjects;

use AiSystem\Domain\ValueObjects\GraphConfig;
use AiSystem\Domain\ValueObjects\RelationshipConfig;
use AiSystem\Tests\TestCase;

class GraphConfigTest extends TestCase
{
    public function test_can_create_graph_config()
    {
        $config = new GraphConfig(
            label: 'Customer',
            properties: ['id', 'name', 'email'],
            relationships: []
        );

        $this->assertEquals('Customer', $config->label);
        $this->assertEquals(['id', 'name', 'email'], $config->properties);
        $this->assertEmpty($config->relationships);
    }

    public function test_can_create_with_relationships()
    {
        $relationship = new RelationshipConfig(
            type: 'MEMBER_OF',
            targetLabel: 'Team',
            foreignKey: 'team_id'
        );

        $config = new GraphConfig(
            label: 'Person',
            properties: ['id', 'name'],
            relationships: [$relationship]
        );

        $this->assertCount(1, $config->relationships);
        $this->assertInstanceOf(RelationshipConfig::class, $config->relationships[0]);
    }

    public function test_throws_exception_for_empty_label()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Graph label cannot be empty');

        new GraphConfig(
            label: '',
            properties: ['id'],
            relationships: []
        );
    }

    public function test_throws_exception_for_empty_properties()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Graph properties cannot be empty');

        new GraphConfig(
            label: 'Customer',
            properties: [],
            relationships: []
        );
    }

    public function test_throws_exception_for_invalid_relationship()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('All relationships must be instances of RelationshipConfig');

        new GraphConfig(
            label: 'Customer',
            properties: ['id'],
            relationships: ['invalid']
        );
    }

    public function test_can_create_from_array()
    {
        $array = [
            'label' => 'Customer',
            'properties' => ['id', 'name'],
            'relationships' => [
                [
                    'type' => 'PURCHASED',
                    'target_label' => 'Order',
                    'foreign_key' => 'order_id',
                ]
            ]
        ];

        $config = GraphConfig::fromArray($array);

        $this->assertEquals('Customer', $config->label);
        $this->assertEquals(['id', 'name'], $config->properties);
        $this->assertCount(1, $config->relationships);
        $this->assertInstanceOf(RelationshipConfig::class, $config->relationships[0]);
    }

    public function test_has_relationship()
    {
        $relationship = new RelationshipConfig(
            type: 'MEMBER_OF',
            targetLabel: 'Team',
            foreignKey: 'team_id'
        );

        $config = new GraphConfig(
            label: 'Person',
            properties: ['id'],
            relationships: [$relationship]
        );

        $this->assertTrue($config->hasRelationship('team_id'));
        $this->assertFalse($config->hasRelationship('other_id'));
    }

    public function test_get_relationship()
    {
        $relationship = new RelationshipConfig(
            type: 'MEMBER_OF',
            targetLabel: 'Team',
            foreignKey: 'team_id'
        );

        $config = new GraphConfig(
            label: 'Person',
            properties: ['id'],
            relationships: [$relationship]
        );

        $found = $config->getRelationship('team_id');
        $this->assertInstanceOf(RelationshipConfig::class, $found);
        $this->assertEquals('MEMBER_OF', $found->type);

        $notFound = $config->getRelationship('other_id');
        $this->assertNull($notFound);
    }
}
