<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tools\Builtins;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Tools\ToolDefinition;
use Condoedge\Ai\Tools\ToolRegistry;

/**
 * Registers entity CRUD tools for the LLM to create, read, update, and delete entities in Neo4j.
 */
class EntityCrudTool
{
    public function __construct(
        private readonly GraphStoreInterface $graphStore,
    ) {}

    /**
     * Register all CRUD tools in the registry.
     */
    public function register(ToolRegistry $registry): void
    {
        $registry->register($this->entityRead());
        $registry->register($this->entityCreate());
        $registry->register($this->entityUpdate());
        $registry->register($this->entityDelete());
    }

    private function entityRead(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'entity_read',
            description: 'Read an entity (node) from the graph database by its label and ID. Returns all properties.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'label' => [
                        'type' => 'string',
                        'description' => 'The entity type/label (e.g., Person, Team, Invoice)',
                    ],
                    'id' => [
                        'type' => ['string', 'integer'],
                        'description' => 'The entity ID',
                    ],
                ],
                'required' => ['label', 'id'],
            ],
            handler: function (array $args, array $context): array {
                $label = $args['label'];
                $id = $args['id'];

                $this->validateEntityAccess($label, $context);

                $node = $this->graphStore->getNode($label, $id);

                if ($node === null) {
                    return ['found' => false, 'message' => "No {$label} found with ID {$id}"];
                }

                return ['found' => true, 'entity' => $node];
            },
        );
    }

    private function entityCreate(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'entity_create',
            description: 'Create a new entity (node) in the graph database with the given label and properties.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'label' => [
                        'type' => 'string',
                        'description' => 'The entity type/label (e.g., Person, Team)',
                    ],
                    'properties' => [
                        'type' => 'object',
                        'description' => 'The properties to set on the new entity',
                    ],
                ],
                'required' => ['label', 'properties'],
            ],
            handler: function (array $args, array $context): array {
                $label = $args['label'];
                $properties = $args['properties'];

                $this->validateEntityAccess($label, $context);

                $id = $this->graphStore->createNode($label, $properties);

                return ['created' => true, 'id' => $id, 'label' => $label];
            },
            requiresConfirmation: true,
        );
    }

    private function entityUpdate(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'entity_update',
            description: 'Update properties of an existing entity in the graph database.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'label' => [
                        'type' => 'string',
                        'description' => 'The entity type/label',
                    ],
                    'id' => [
                        'type' => ['string', 'integer'],
                        'description' => 'The entity ID',
                    ],
                    'properties' => [
                        'type' => 'object',
                        'description' => 'The properties to update',
                    ],
                ],
                'required' => ['label', 'id', 'properties'],
            ],
            handler: function (array $args, array $context): array {
                $label = $args['label'];
                $id = $args['id'];
                $properties = $args['properties'];

                $this->validateEntityAccess($label, $context);

                $success = $this->graphStore->updateNode($label, $id, $properties);

                return ['updated' => $success, 'id' => $id, 'label' => $label];
            },
            requiresConfirmation: true,
        );
    }

    private function entityDelete(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'entity_delete',
            description: 'Delete an entity from the graph database by its label and ID.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'label' => [
                        'type' => 'string',
                        'description' => 'The entity type/label',
                    ],
                    'id' => [
                        'type' => ['string', 'integer'],
                        'description' => 'The entity ID',
                    ],
                ],
                'required' => ['label', 'id'],
            ],
            handler: function (array $args, array $context): array {
                $label = $args['label'];
                $id = $args['id'];

                $this->validateEntityAccess($label, $context);

                $success = $this->graphStore->deleteNode($label, $id);

                return ['deleted' => $success, 'id' => $id, 'label' => $label];
            },
            requiresConfirmation: true,
        );
    }

    /**
     * Validate that the agent/user has access to this entity type.
     */
    private function validateEntityAccess(string $label, array $context): void
    {
        $resolvedAgent = $context['resolved_agent'] ?? null;

        if ($resolvedAgent === null) {
            return;
        }

        $allowedEntities = $resolvedAgent->getAllowedEntities();

        if (!empty($allowedEntities) && !in_array($label, $allowedEntities)) {
            throw new \RuntimeException("Agent does not have access to entity type: {$label}");
        }
    }
}
