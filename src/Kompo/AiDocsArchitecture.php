<?php

namespace AiSystem\Kompo;

use Kompo\Form;

/**
 * AI System Documentation - Architecture Overview
 *
 * Extends Kompo\Form to provide detailed system architecture documentation
 * including module descriptions, diagrams, and data flow examples.
 */
class AiDocsArchitecture extends Form
{
    /**
     * Render the architecture documentation page
     *
     * @return array Array of Kompo components
     */
    public function render()
    {
        return [
            _Link('← Back to Index')->href(route('ai.docs.index'))->class('text-blue-600 mb-4'),
            _Html('System Architecture')->class('text-4xl font-bold mb-8'),
            $this->overviewDiagram(),
            $this->moduleDescriptions(),
            $this->dataFlow(),
        ];
    }

    /**
     * Render the architecture overview diagram
     */
    protected function overviewDiagram()
    {
        return _Card(
            _Html('Architecture Overview')->class('text-2xl font-bold mb-4'),
            _Html('
                <div class="bg-gray-50 p-6 rounded font-mono text-sm">
                    <pre>
User Question (Natural Language)
    ↓
┌─────────────────────────────────────────────┐
│ Chat Orchestrator (Module 6)                │
│ Coordinates entire flow                     │
└─────────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────────┐
│ Context Retrieval Service (Module 2)        │
│ ┌────────────┐  ┌────────────┐             │
│ │  Qdrant    │  │   Neo4j    │             │
│ │  Vector    │  │   Graph    │             │
│ │  Search    │  │   Schema   │             │
│ └────────────┘  └────────────┘             │
└─────────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────────┐
│ Query Generation Service (Module 3)         │
│ LLM generates Cypher query                  │
│ (Switchable: OpenAI or Anthropic)          │
└─────────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────────┐
│ Query Execution Service (Module 4)          │
│ Execute on Neo4j safely                     │
└─────────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────────┐
│ Response Generation Service (Module 5)      │
│ LLM converts data to human language         │
└─────────────────────────────────────────────┘
    ↓
Human-readable Response + Query Used
                    </pre>
                </div>
            '),
        )->class('p-6 mb-8');
    }

    /**
     * Render descriptions of all system modules
     */
    protected function moduleDescriptions()
    {
        $modules = [
            [
                'number' => 'Module 1',
                'name' => 'Data Ingestion',
                'description' => 'Loads your domain entities into Neo4j (graph) and Qdrant (vectors). Supports config-driven or manual mapping.',
            ],
            [
                'number' => 'Module 2',
                'name' => 'Context Retrieval (RAG)',
                'description' => 'Retrieves relevant context using vector similarity search and graph schema information.',
            ],
            [
                'number' => 'Module 3',
                'name' => 'Query Generation',
                'description' => 'LLM converts natural language + context into executable Cypher queries.',
            ],
            [
                'number' => 'Module 4',
                'name' => 'Query Execution',
                'description' => 'Safely executes generated queries on Neo4j with validation and error handling.',
            ],
            [
                'number' => 'Module 5',
                'name' => 'Response Generation',
                'description' => 'LLM converts raw query results into natural language explanations.',
            ],
            [
                'number' => 'Module 6',
                'name' => 'Chat Orchestrator',
                'description' => 'Main coordinator that ties all modules together into a seamless chat experience.',
            ],
            [
                'number' => 'Module 7',
                'name' => 'Kompo Interface',
                'description' => 'User-facing chat component with real-time messaging and query visualization.',
            ],
        ];

        $moduleCards = array_map(function ($module) {
            return $this->moduleCard($module['number'], $module['name'], $module['description']);
        }, $modules);

        return _Card(
            _Html('System Modules')->class('text-2xl font-bold mb-4'),
            _Rows(...$moduleCards)->class('space-y-4'),
        )->class('p-6 mb-8');
    }

    /**
     * Render a single module description card
     */
    protected function moduleCard($number, $name, $description)
    {
        return _Rows(
            _Html("<strong>{$number}: {$name}</strong>")->class('text-lg mb-2'),
            _Html($description)->class('text-sm text-gray-600'),
        )->class('p-4 border-l-4 border-blue-500 bg-blue-50');
    }

    /**
     * Render a detailed data flow example
     */
    protected function dataFlow()
    {
        return _Card(
            _Html('Data Flow Example')->class('text-2xl font-bold mb-4'),
            _Html('
                <div class="prose max-w-none">
                    <h3>Example: "Show me teams with most active members"</h3>

                    <ol class="space-y-4">
                        <li>
                            <strong>User asks question</strong><br>
                            Natural language input: "Show me teams with most active members"
                        </li>

                        <li>
                            <strong>Generate embedding</strong><br>
                            Question → Vector [0.2, 0.8, 0.1, ...]
                        </li>

                        <li>
                            <strong>Search Qdrant</strong><br>
                            Find similar past questions and their queries
                        </li>

                        <li>
                            <strong>Get Neo4j schema</strong><br>
                            Discover: Team nodes, Person nodes, MEMBER_OF relationships
                        </li>

                        <li>
                            <strong>LLM generates Cypher</strong><br>
                            <code>MATCH (t:Team)&lt;-[:MEMBER_OF {status:\'active\'}]-(p:Person)<br>
                            RETURN t.name, count(p) as members<br>
                            ORDER BY members DESC</code>
                        </li>

                        <li>
                            <strong>Execute query</strong><br>
                            Results: [{name: "Alpha Team", members: 45}, ...]
                        </li>

                        <li>
                            <strong>Generate explanation</strong><br>
                            "Your most active team is Alpha Team with 45 members..."
                        </li>
                    </ol>
                </div>
            ')->class('text-sm'),
        )->class('p-6');
    }
}
