<?php

namespace AiSystem\Tests\Unit\Domain\ValueObjects;

use AiSystem\Domain\ValueObjects\RelationshipConfig;
use AiSystem\Tests\TestCase;

class RelationshipConfigTest extends TestCase
{
    public function test_can_create_relationship_config()
    {
        $config = new RelationshipConfig(
            type: 'MEMBER_OF',
            targetLabel: 'Team',
            foreignKey: 'team_id',
            properties: ['since' => 'created_at']
        );

        $this->assertEquals('MEMBER_OF', $config->type);
        $this->assertEquals('Team', $config->targetLabel);
        $this->assertEquals('team_id', $config->foreignKey);
        $this->assertEquals(['since' => 'created_at'], $config->properties);
    }

    public function test_throws_exception_for_empty_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Relationship type cannot be empty');

        new RelationshipConfig(
            type: '',
            targetLabel: 'Team',
            foreignKey: 'team_id'
        );
    }

    public function test_throws_exception_for_empty_target_label()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target label cannot be empty');

        new RelationshipConfig(
            type: 'MEMBER_OF',
            targetLabel: '',
            foreignKey: 'team_id'
        );
    }

    public function test_throws_exception_for_empty_foreign_key()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Foreign key cannot be empty');

        new RelationshipConfig(
            type: 'MEMBER_OF',
            targetLabel: 'Team',
            foreignKey: ''
        );
    }

    public function test_can_create_from_array()
    {
        $array = [
            'type' => 'PURCHASED',
            'target_label' => 'Order',
            'foreign_key' => 'order_id',
            'properties' => ['date' => 'purchase_date']
        ];

        $config = RelationshipConfig::fromArray($array);

        $this->assertEquals('PURCHASED', $config->type);
        $this->assertEquals('Order', $config->targetLabel);
        $this->assertEquals('order_id', $config->foreignKey);
        $this->assertEquals(['date' => 'purchase_date'], $config->properties);
    }

    public function test_can_create_from_array_with_camel_case()
    {
        $array = [
            'type' => 'BELONGS_TO',
            'targetLabel' => 'Category',
            'foreignKey' => 'category_id',
        ];

        $config = RelationshipConfig::fromArray($array);

        $this->assertEquals('Category', $config->targetLabel);
        $this->assertEquals('category_id', $config->foreignKey);
    }

    public function test_has_properties()
    {
        $withProps = new RelationshipConfig(
            type: 'MEMBER_OF',
            targetLabel: 'Team',
            foreignKey: 'team_id',
            properties: ['since' => 'created_at']
        );

        $withoutProps = new RelationshipConfig(
            type: 'MEMBER_OF',
            targetLabel: 'Team',
            foreignKey: 'team_id'
        );

        $this->assertTrue($withProps->hasProperties());
        $this->assertFalse($withoutProps->hasProperties());
    }
}
