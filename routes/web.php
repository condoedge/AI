<?php

use Illuminate\Support\Facades\Route;
use AiSystem\Http\Controllers\AiDocsController;

/*
|--------------------------------------------------------------------------
| AI System Documentation Routes
|--------------------------------------------------------------------------
|
| These routes provide visual documentation for the AI Text-to-Query system.
| Can be enabled/disabled via AI_DOCS_ENABLED in .env
|
*/

// Check if documentation is enabled
if (config('ai.documentation.enabled', true)) {

    $prefix = config('ai.documentation.route_prefix', 'ai-docs');
    $middleware = config('ai.documentation.middleware', ['web']);

    Route::prefix($prefix)
        ->middleware($middleware)
        ->name('ai.docs.')
        ->group(function () {

            // Main documentation page
            Route::get('/', [AiDocsController::class, 'index'])
                ->name('index');

            // Architecture overview
            Route::get('/architecture', [AiDocsController::class, 'architecture'])
                ->name('architecture');

            // Entity configurations
            Route::get('/entities', [AiDocsController::class, 'entities'])
                ->name('entities');

            // Entity detail view
            Route::get('/entities/{entity}', [AiDocsController::class, 'entityDetail'])
                ->name('entities.detail');

            // Configuration reference
            Route::get('/configuration', [AiDocsController::class, 'configuration'])
                ->name('configuration');

            // Examples & tutorials
            Route::get('/examples', [AiDocsController::class, 'examples'])
                ->name('examples');

            // API reference
            Route::get('/api-reference', [AiDocsController::class, 'apiReference'])
                ->name('api');

            // Test connection endpoints
            Route::get('/test/neo4j', [AiDocsController::class, 'testNeo4j'])
                ->name('test.neo4j');

            Route::get('/test/qdrant', [AiDocsController::class, 'testQdrant'])
                ->name('test.qdrant');

            Route::get('/test/llm', [AiDocsController::class, 'testLlm'])
                ->name('test.llm');
        });
}
