<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

class ConversationFileTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Don't load the full AI service provider to avoid API key requirements
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up in-memory SQLite database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Run migrations for the AI tables
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    /** @test */
    public function message_stores_referenced_files_in_metadata(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $referencedFiles = [
            ['id' => 1, 'name' => 'document.pdf', 'type' => 'pdf'],
            ['id' => 2, 'name' => 'image.png', 'type' => 'image'],
        ];

        $message = $conversation->addMessage('user', 'Check these files', [
            'referenced_files' => $referencedFiles,
        ]);

        $this->assertEquals($referencedFiles, $message->metadata['referenced_files']);
    }

    /** @test */
    public function message_get_referenced_files_returns_files(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $referencedFiles = [
            ['id' => 1, 'name' => 'document.pdf', 'type' => 'pdf'],
            ['id' => 2, 'name' => 'image.png', 'type' => 'image'],
        ];

        $message = $conversation->addMessage('user', 'Check these files', [
            'referenced_files' => $referencedFiles,
        ]);

        $this->assertEquals($referencedFiles, $message->getReferencedFiles());
    }

    /** @test */
    public function message_get_referenced_files_returns_empty_array_when_none(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $message = $conversation->addMessage('user', 'No files here');

        $this->assertEquals([], $message->getReferencedFiles());
    }

    /** @test */
    public function message_has_file_references_returns_true_when_files_exist(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $referencedFiles = [
            ['id' => 1, 'name' => 'document.pdf', 'type' => 'pdf'],
        ];

        $message = $conversation->addMessage('user', 'Check this file', [
            'referenced_files' => $referencedFiles,
        ]);

        $this->assertTrue($message->hasFileReferences());
    }

    /** @test */
    public function message_has_file_references_returns_false_when_no_files(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $message = $conversation->addMessage('user', 'No files here');

        $this->assertFalse($message->hasFileReferences());
    }

    /** @test */
    public function conversation_tracks_referenced_file_ids_in_context_snapshot(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $referencedFiles = [
            ['id' => 1, 'name' => 'document.pdf', 'type' => 'pdf'],
            ['id' => 2, 'name' => 'image.png', 'type' => 'image'],
        ];

        $conversation->addMessage('user', 'Check these files', [
            'referenced_files' => $referencedFiles,
        ]);

        $conversation->refresh();

        $this->assertEquals([1, 2], $conversation->context_snapshot['referenced_files']);
    }

    /** @test */
    public function conversation_accumulates_file_ids_across_messages(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        // First message with files
        $conversation->addMessage('user', 'Check these files', [
            'referenced_files' => [
                ['id' => 1, 'name' => 'document.pdf', 'type' => 'pdf'],
                ['id' => 2, 'name' => 'image.png', 'type' => 'image'],
            ],
        ]);

        // Second message with different files
        $conversation->addMessage('user', 'And these files too', [
            'referenced_files' => [
                ['id' => 3, 'name' => 'spreadsheet.xlsx', 'type' => 'spreadsheet'],
            ],
        ]);

        $conversation->refresh();

        $this->assertEquals([1, 2, 3], $conversation->context_snapshot['referenced_files']);
    }

    /** @test */
    public function conversation_does_not_duplicate_file_ids(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        // First message with files
        $conversation->addMessage('user', 'Check these files', [
            'referenced_files' => [
                ['id' => 1, 'name' => 'document.pdf', 'type' => 'pdf'],
                ['id' => 2, 'name' => 'image.png', 'type' => 'image'],
            ],
        ]);

        // Second message referencing same file
        $conversation->addMessage('user', 'Look at this file again', [
            'referenced_files' => [
                ['id' => 1, 'name' => 'document.pdf', 'type' => 'pdf'],
                ['id' => 3, 'name' => 'new-file.txt', 'type' => 'text'],
            ],
        ]);

        $conversation->refresh();

        $this->assertEquals([1, 2, 3], $conversation->context_snapshot['referenced_files']);
    }

    /** @test */
    public function referenced_files_merged_with_existing_metadata(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $message = $conversation->addMessage('user', 'Check these files', [
            'metadata' => ['custom_key' => 'custom_value'],
            'referenced_files' => [
                ['id' => 1, 'name' => 'document.pdf', 'type' => 'pdf'],
            ],
        ]);

        $this->assertEquals('custom_value', $message->metadata['custom_key']);
        $this->assertCount(1, $message->metadata['referenced_files']);
    }

    /** @test */
    public function add_message_preserves_other_data_fields(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $message = $conversation->addMessage('assistant', 'Here is your response', [
            'referenced_files' => [
                ['id' => 1, 'name' => 'document.pdf', 'type' => 'pdf'],
            ],
            'response_data' => ['key' => 'value'],
            'context_used' => ['context' => 'data'],
            'cypher_query' => 'MATCH (n) RETURN n',
            'execution_time_ms' => 150,
            'confidence_score' => 0.95,
        ]);

        $this->assertEquals(['key' => 'value'], $message->response_data);
        $this->assertEquals(['context' => 'data'], $message->context_used);
        $this->assertEquals('MATCH (n) RETURN n', $message->cypher_query);
        $this->assertEquals(150, $message->execution_time_ms);
        $this->assertEquals(0.95, $message->confidence_score);
        $this->assertTrue($message->hasFileReferences());
    }
}
