<?php

namespace AiSystem\Kompo;

use Kompo\Query;

/**
 * AI System Documentation - Entity Configurations Browser
 *
 * Extends Kompo\Query to provide a browsable list of all configured entities
 * with their Neo4j and Qdrant mapping configurations.
 */
class AiDocsEntities extends Query
{
    /**
     * Set the wrapper class for grid layout
     */
    public $itemsWrapperClass = 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4';

    /**
     * Query method to load entity configurations
     *
     * This method loads all entities from the config file and transforms
     * them into objects for the card() method to render.
     *
     * @return \Illuminate\Support\Collection
     */
    public function query()
    {
        $entities = config('ai.entities', []);

        return collect($entities)->map(function ($config, $name) {
            return (object) [
                'name' => $name,
                'has_graph' => isset($config['graph']),
                'has_vector' => isset($config['vector']),
                'graph_label' => $config['graph']['label'] ?? 'N/A',
                'vector_collection' => $config['vector']['collection'] ?? 'N/A',
                'relationship_count' => count($config['graph']['relationships'] ?? []),
            ];
        })->values();
    }

    /**
     * Render the page header and container for cards
     *
     * @return array Array of Kompo components
     */
    public function render()
    {
        return [
            _Link('← Back to Index')->href(route('ai.docs.index'))->class('text-blue-600 mb-4'),
            _Html('Entity Configurations')->class('text-4xl font-bold mb-4'),
            _Html('Browse all configured entities and their Neo4j/Qdrant mappings.')->class('text-gray-600 mb-8'),
            _Rows()->class('vlCollapsible'),
        ];
    }

    /**
     * Render each entity as a card
     *
     * This method is called for each item returned by query()
     *
     * @param object $item Entity data object
     * @return \Kompo\Komponent
     */
    public function card($item)
    {
        return _CardLink(
            _Html($item->name)->class('text-xl font-bold mb-2'),
            $this->renderBadges($item),
            $this->renderEntityInfo($item),
            _Html('View Details →')->class('text-blue-600 text-sm mt-3'),
        )->href(route('ai.docs.entities.detail', ['entity' => $item->name]))
          ->class('p-6 hover:shadow-lg transition border rounded');
    }

    /**
     * Render storage type badges for an entity
     */
    protected function renderBadges($item)
    {
        $badges = [];

        if ($item->has_graph) {
            $badges[] = "<span class='inline-block px-2 py-1 text-xs rounded bg-blue-100 text-blue-800'>Neo4j</span>";
        }

        if ($item->has_vector) {
            $badges[] = "<span class='inline-block px-2 py-1 text-xs rounded bg-purple-100 text-purple-800'>Qdrant</span>";
        }

        return _Html(implode(' ', $badges))->class('mb-3');
    }

    /**
     * Render entity configuration summary
     */
    protected function renderEntityInfo($item)
    {
        return _Rows(
            _Html("<strong>Graph Label:</strong> {$item->graph_label}")->class('text-sm'),
            _Html("<strong>Vector Collection:</strong> {$item->vector_collection}")->class('text-sm'),
            _Html("<strong>Relationships:</strong> {$item->relationship_count}")->class('text-sm'),
        )->class('space-y-1 text-gray-600');
    }
}
