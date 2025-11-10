<?php

namespace AiSystem\Kompo;

use Kompo\Form;

/**
 * AI System Documentation - Entity Detail View
 *
 * Extends Kompo\Form to provide detailed configuration information
 * for a specific entity including graph structure, vector configuration,
 * and usage examples.
 */
class AiDocsEntityDetail extends Form
{
    /**
     * The entity name from the route parameter
     */
    public $entityName;

    /**
     * The entity configuration loaded from config
     */
    public $config;

    /**
     * Hook that runs after the component is created
     *
     * Loads the entity configuration and validates it exists
     */
    public function created()
    {
        $this->entityName = request()->route('entity');
        $entities = config('ai.entities', []);

        if (!isset($entities[$this->entityName])) {
            abort(404, "Entity not found");
        }

        $this->config = $entities[$this->entityName];
    }

    /**
     * Render the entity detail page
     *
     * @return array Array of Kompo components
     */
    public function render()
    {
        return [
            _Link('← Back to Entities')->href(route('ai.docs.entities'))->class('text-blue-600 mb-4'),
            _Html($this->entityName)->class('text-4xl font-bold mb-2'),
            _Html('Entity configuration details')->class('text-gray-600 mb-8'),
            $this->graphConfig(),
            $this->vectorConfig(),
            $this->usageExample(),
        ];
    }

    /**
     * Render Neo4j graph configuration section
     */
    protected function graphConfig()
    {
        if (!isset($this->config['graph'])) {
            return _Card(
                _Html('Neo4j Graph Configuration')->class('text-2xl font-bold mb-4'),
                _Html('<em class="text-gray-500">This entity is not configured for graph storage.</em>'),
            )->class('p-6 mb-6');
        }

        $graph = $this->config['graph'];

        return _Card(
            _Html('Neo4j Graph Configuration')->class('text-2xl font-bold mb-4'),
            _Rows(
                _Html("<strong>Node Label:</strong> <code class='bg-gray-100 px-2 py-1 rounded'>{$graph['label']}</code>"),
                _Html('<strong>Properties:</strong>')->class('mt-4'),
                _Html('<code class="bg-gray-100 px-2 py-1 rounded">' . implode(', ', $graph['properties']) . '</code>'),
                _Html('<strong>Relationships:</strong>')->class('mt-4'),
                $this->renderRelationships($graph['relationships'] ?? []),
            ),
        )->class('p-6 mb-6');
    }

    /**
     * Render the list of relationships
     */
    protected function renderRelationships($relationships)
    {
        if (empty($relationships)) {
            return _Html('<em class="text-gray-500">No relationships configured</em>');
        }

        $relationshipCards = array_map(function ($rel) {
            return _Html("
                <div class='p-3 bg-blue-50 border-l-4 border-blue-500 mb-2'>
                    <strong>{$rel['type']}</strong> → {$rel['target_label']}<br>
                    <span class='text-sm text-gray-600'>Foreign Key: {$rel['foreign_key']}</span>
                </div>
            ");
        }, $relationships);

        return _Rows(...$relationshipCards);
    }

    /**
     * Render Qdrant vector configuration section
     */
    protected function vectorConfig()
    {
        if (!isset($this->config['vector'])) {
            return _Card(
                _Html('Qdrant Vector Configuration')->class('text-2xl font-bold mb-4'),
                _Html('<em class="text-gray-500">This entity is not configured for vector search.</em>'),
            )->class('p-6 mb-6');
        }

        $vector = $this->config['vector'];

        return _Card(
            _Html('Qdrant Vector Configuration')->class('text-2xl font-bold mb-4'),
            _Rows(
                _Html("<strong>Collection:</strong> <code class='bg-gray-100 px-2 py-1 rounded'>{$vector['collection']}</code>"),
                _Html('<strong>Embed Fields:</strong> (Combined for embedding)')->class('mt-4'),
                _Html('<code class="bg-gray-100 px-2 py-1 rounded">' . implode(', ', $vector['embed_fields']) . '</code>'),
                _Html('<strong>Metadata:</strong> (Stored for filtering)')->class('mt-4'),
                _Html('<code class="bg-gray-100 px-2 py-1 rounded">' . implode(', ', $vector['metadata']) . '</code>'),
            ),
        )->class('p-6 mb-6');
    }

    /**
     * Render usage example code snippet
     */
    protected function usageExample()
    {
        $codeExample = $this->generateCodeExample();

        return _Card(
            _Html('Usage Example')->class('text-2xl font-bold mb-4'),
            _Html("
                <div class='bg-gray-50 p-4 rounded font-mono text-sm'>
                    <pre><code>{$codeExample}</code></pre>
                </div>
            "),
        )->class('p-6');
    }

    /**
     * Generate the code example for this entity
     */
    protected function generateCodeExample()
    {
        return "use AiSystem\Domain\Contracts\Nodeable;
use AiSystem\Domain\Traits\HasNodeableConfig;

class {$this->entityName} implements Nodeable
{
    use HasNodeableConfig;

    public int \$id;
    public string \$name;
    // ... other properties

    // Configuration is loaded automatically from config/entities.php!
}

// Usage:
\$entity = new {$this->entityName}();
\$graphConfig = \$entity->getGraphConfig();
\$vectorConfig = \$entity->getVectorConfig();

// Ingest into Neo4j + Qdrant:
\$ingestionService->ingest(\$entity);";
    }
}
