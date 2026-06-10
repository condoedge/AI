<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\RelationshipsSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * The '_polymorphic' sentinel is a sync-layer implementation detail and must
 * NEVER leak into a prompt: a generated Cypher template like
 * 'MATCH (x:File)-[:PARENT]->(y:_polymorphic)' can only ever match nothing.
 * Polymorphic targets render as an unlabeled node instead.
 */
class RelationshipsSectionTest extends TestCase
{
    public function test_polymorphic_relationship_renders_unlabeled_target(): void
    {
        config(['entities' => [
            'App\\Models\\File' => [
                'graph' => [
                    'label' => 'File',
                    'relationships' => [
                        [
                            'type' => 'PARENT',
                            'target_label' => '_polymorphic',
                            'foreign_key' => 'parent_id',
                            'direction' => 'outgoing',
                            'description' => 'Owning record of the file',
                        ],
                    ],
                ],
            ],
        ]]);

        $context = new PipelineContext();
        $context->graphSchema = ['all_relationship_types' => ['PARENT']];
        $context->selectionInfo = ['selected_entities' => ['File']];

        $output = (new RelationshipsSection())->format('q', $context, []);

        $this->assertStringNotContainsString('_polymorphic', $output);
        $this->assertStringContainsString('(File)-[:PARENT]->(x)', $output);
        $this->assertStringContainsString('polymorphic', $output);
        $this->assertStringContainsString('MATCH (x:File)-[:PARENT]->(y)', $output);
    }

    public function test_incoming_polymorphic_relationship_renders_unlabeled_source(): void
    {
        config(['entities' => [
            'App\\Models\\Note' => [
                'graph' => [
                    'label' => 'Note',
                    'relationships' => [
                        [
                            'type' => 'ABOUT',
                            'target_label' => '_polymorphic',
                            'foreign_key' => 'about_id',
                            'direction' => 'incoming',
                        ],
                    ],
                ],
            ],
        ]]);

        $context = new PipelineContext();
        $context->graphSchema = ['all_relationship_types' => ['ABOUT']];
        $context->selectionInfo = ['selected_entities' => ['Note']];

        $output = (new RelationshipsSection())->format('q', $context, []);

        $this->assertStringNotContainsString('_polymorphic', $output);
        $this->assertStringContainsString('(Note)<-[:ABOUT]-(x)', $output);
        $this->assertStringContainsString('MATCH (x:Note)<-[:ABOUT]-(y)', $output);
    }

    public function test_concrete_relationship_rendering_is_unchanged(): void
    {
        config(['entities' => [
            'App\\Models\\User' => [
                'graph' => [
                    'label' => 'User',
                    'relationships' => [
                        [
                            'type' => 'MEMBER_OF',
                            'target_label' => 'Team',
                            'foreign_key' => 'team_id',
                            'direction' => 'outgoing',
                        ],
                    ],
                ],
            ],
        ]]);

        $context = new PipelineContext();
        $context->graphSchema = ['all_relationship_types' => ['MEMBER_OF']];
        $context->selectionInfo = ['selected_entities' => ['User']];

        $output = (new RelationshipsSection())->format('q', $context, []);

        $this->assertStringContainsString('(User)-[:MEMBER_OF]->(Team)', $output);
        $this->assertStringContainsString('MATCH (x:User)-[:MEMBER_OF]->(y:Team)', $output);
    }
}
