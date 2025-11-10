<?php

namespace AiSystem\Kompo;

use Kompo\Form;

/**
 * AI System Documentation - Main Index Page
 *
 * Extends Kompo\Form to provide a visual documentation landing page
 * for the AI Text-to-Query system.
 */
class AiDocsIndex extends Form
{
    /**
     * Render the main documentation index page
     *
     * @return array Array of Kompo components
     */
    public function render()
    {
        return [
            $this->header(),
            $this->quickLinks(),
            $this->systemStatus(),
            $this->gettingStarted(),
        ];
    }

    /**
     * Render the page header
     */
    protected function header()
    {
        return _Rows(
            _Html('AI Text-to-Query System')->class('text-4xl font-bold mb-4'),
            _Html('Visual documentation and interactive reference for the AI-powered natural language query system.')->class('text-gray-600 mb-8'),
        );
    }

    /**
     * Render quick navigation links to other documentation pages
     */
    protected function quickLinks()
    {
        return _Columns(
            $this->quickLinkCard(
                'Architecture',
                'Learn about the system architecture, modules, and how everything fits together.',
                route('ai.docs.architecture')
            ),
            $this->quickLinkCard(
                'Entity Configuration',
                'Browse and manage entity mappings for Neo4j and Qdrant storage.',
                route('ai.docs.entities')
            ),
            $this->quickLinkCard(
                'Configuration',
                'Review system configuration, API keys, and service settings.',
                route('ai.docs.configuration')
            ),
            $this->quickLinkCard(
                'Examples',
                'Step-by-step tutorials and code examples for common use cases.',
                route('ai.docs.examples')
            ),
        )->class('grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8');
    }

    /**
     * Render a single quick link card
     */
    protected function quickLinkCard($title, $description, $url)
    {
        return _Card(
            _Html($title)->class('text-xl font-bold mb-2'),
            _Html($description)->class('text-sm text-gray-600 mb-4'),
            _Link('View Details')->href($url)->class('text-blue-600 hover:text-blue-800')
        )->class('p-6 hover:shadow-lg transition');
    }

    /**
     * Render system status cards showing connectivity status
     */
    protected function systemStatus()
    {
        return _Card(
            _Html('System Status')->class('text-2xl font-bold mb-4'),
            _Columns(
                $this->statusCard('Neo4j', route('ai.docs.test.neo4j'), config('ai.neo4j.enabled')),
                $this->statusCard('Qdrant', route('ai.docs.test.qdrant'), config('ai.qdrant.enabled')),
                $this->statusCard('LLM Provider', route('ai.docs.test.llm'), true),
            )->class('grid grid-cols-1 md:grid-cols-3 gap-4'),
        )->class('p-6 mb-8');
    }

    /**
     * Render a single service status card
     */
    protected function statusCard($name, $testUrl, $enabled)
    {
        $statusColor = $enabled ? 'green' : 'gray';
        $statusText = $enabled ? 'Enabled' : 'Disabled';

        return _Rows(
            _Html($name)->class('font-semibold mb-2'),
            _Html("<span class='inline-block px-2 py-1 text-xs rounded bg-{$statusColor}-100 text-{$statusColor}-800'>{$statusText}</span>"),
            _Link('Test Connection')->href($testUrl)->class('text-sm text-blue-600 hover:text-blue-800 mt-2')->attr(['target' => '_blank']),
        )->class('p-4 border rounded');
    }

    /**
     * Render getting started guide
     */
    protected function gettingStarted()
    {
        return _Card(
            _Html('Getting Started')->class('text-2xl font-bold mb-4'),
            _Html('
                <div class="prose max-w-none">
                    <h3>1. Configure Your Entities</h3>
                    <p>Define how your domain models map to Neo4j (graph) and Qdrant (vector) storage in <code>config/entities.php</code></p>

                    <h3>2. Implement Nodeable Interface</h3>
                    <p>Add the <code>Nodeable</code> interface to your models and use the <code>HasNodeableConfig</code> trait for automatic configuration loading.</p>

                    <h3>3. Ingest Your Data</h3>
                    <p>Use the <code>DataIngestionService</code> to load your existing data into Neo4j and Qdrant.</p>

                    <h3>4. Start Querying</h3>
                    <p>Use the <code>ChatOrchestrator</code> to accept natural language questions and generate responses.</p>
                </div>
            ')->class('text-sm'),
        )->class('p-6');
    }
}
