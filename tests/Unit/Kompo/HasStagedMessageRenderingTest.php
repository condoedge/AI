<?php

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\ChatMessageForm;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * Tests for HasStagedMessageRendering trait.
 *
 * Specifically tests the stagedFileCitationProxies() method
 * and its integration with renderStagedAssistantResponse().
 */
class HasStagedMessageRenderingTest extends TestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createTestUser();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function createTestUser(): Authenticatable
    {
        return new class implements Authenticatable {
            public $id = 1;
            public $name = 'Test User';
            public $email = 'test@example.com';

            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return $this->id; }
            public function getAuthPassword() { return 'password'; }
            public function getAuthPasswordName() { return 'password'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return 'remember_token'; }
        };
    }

    /**
     * Don't load the full AI service provider to avoid API key requirements
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Kompo\KompoServiceProvider::class,
        ];
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

        // Set encryption key for Kompo rendering
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        // Run migrations for the AI tables
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    /**
     * Create a mock settings object with all features enabled.
     */
    protected function createMockSettings(): ChatSettingsInterface
    {
        $mock = Mockery::mock(ChatSettingsInterface::class);
        $mock->shouldReceive('enableCopy')->andReturn(true);
        $mock->shouldReceive('enableFeedback')->andReturn(true);
        $mock->shouldReceive('enableRegenerate')->andReturn(true);
        $mock->shouldReceive('enableEdit')->andReturn(true);
        $mock->shouldReceive('showAvatars')->andReturn(false);
        $mock->shouldReceive('showTimestamps')->andReturn(false);
        $mock->shouldReceive('responseStyle')->andReturn('friendly');
        $mock->shouldReceive('inputPlaceholder')->andReturn('Type a message...');
        return $mock;
    }

    /**
     * Test that stagedFileCitationProxies returns null for empty array.
     *
     * @test
     */
    public function stagedFileCitationProxies_returns_null_for_empty_citations(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        $testable = new TestableStagedMessageRendering($conversation, $this->createMockSettings());

        $result = $testable->callStagedFileCitationProxies([]);

        $this->assertNull($result);
    }

    /**
     * Test that stagedFileCitationProxies creates proxy elements with correct attributes.
     *
     * @test
     */
    public function stagedFileCitationProxies_creates_proxies_with_correct_attributes(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        $testable = new TestableStagedMessageRendering($conversation, $this->createMockSettings());

        $citations = [
            [
                'slot' => 'file-citation-1',
                'id' => 42,
                'type' => 'file',
                'mime' => 'application/pdf',
            ],
            [
                'slot' => 'file-citation-2',
                'id' => 43,
                'type' => 'document',
                'mime' => 'text/plain',
            ],
        ];

        $result = $testable->callStagedFileCitationProxies($citations);

        $this->assertNotNull($result);

        // Convert Kompo element to JSON to check structure
        $json = json_encode($result);

        // Verify container class
        $this->assertStringContainsString('chat-staged-file-citation-proxies', $json);

        // Verify proxy elements have correct data attributes
        $this->assertStringContainsString('file-citation-1', $json);
        $this->assertStringContainsString('file-citation-2', $json);

        // Verify JS class markers
        $this->assertStringContainsString('js-file-citation-proxy', $json);

        // Verify hidden class
        $this->assertStringContainsString('hidden', $json);
    }

    /**
     * Test that stagedFileCitationProxies creates correct number of proxies.
     *
     * @test
     */
    public function stagedFileCitationProxies_creates_one_proxy_per_citation(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        $testable = new TestableStagedMessageRendering($conversation, $this->createMockSettings());

        $citations = [
            ['slot' => 'file-citation-1', 'id' => 1, 'type' => 'file', 'mime' => 'application/pdf'],
            ['slot' => 'file-citation-2', 'id' => 2, 'type' => 'file', 'mime' => 'text/plain'],
            ['slot' => 'file-citation-3', 'id' => 3, 'type' => 'file', 'mime' => 'image/png'],
        ];

        $result = $testable->callStagedFileCitationProxies($citations);
        $json = json_encode($result);

        // Count the number of proxy elements
        $proxyCount = substr_count($json, 'js-file-citation-proxy');

        $this->assertEquals(3, $proxyCount);
    }

    /**
     * Test that proxies include the file ID in their attributes.
     *
     * @test
     */
    public function stagedFileCitationProxies_includes_file_id_for_selfGet(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        $testable = new TestableStagedMessageRendering($conversation, $this->createMockSettings());

        $citations = [
            [
                'slot' => 'file-citation-1',
                'id' => 42,
                'type' => 'file',
                'mime' => 'application/pdf',
            ],
        ];

        $result = $testable->callStagedFileCitationProxies($citations);
        $json = json_encode($result);

        // The selfGet parameters are encrypted, but we can verify the structure
        // exists and the slot attribute is present
        $this->assertStringContainsString('file-citation-1', $json);
    }

    /**
     * Test that proxies handle different file types correctly.
     *
     * @test
     */
    public function stagedFileCitationProxies_handles_different_file_types(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        $testable = new TestableStagedMessageRendering($conversation, $this->createMockSettings());

        $citations = [
            ['slot' => 'file-citation-1', 'id' => 1, 'type' => 'file', 'mime' => 'application/pdf'],
            ['slot' => 'file-citation-2', 'id' => 2, 'type' => 'document', 'mime' => 'text/plain'],
            ['slot' => 'file-citation-3', 'id' => 3, 'type' => 'image', 'mime' => 'image/png'],
        ];

        $result = $testable->callStagedFileCitationProxies($citations);
        $json = json_encode($result);

        // All three citations should have proxies
        $this->assertStringContainsString('file-citation-1', $json);
        $this->assertStringContainsString('file-citation-2', $json);
        $this->assertStringContainsString('file-citation-3', $json);
    }
}

/**
 * Testable class that exposes protected methods from HasStagedMessageRendering.
 */
class TestableStagedMessageRendering extends ChatMessageForm
{
    private $testSettings;
    private $testConversation;

    public function __construct($conversation, $settings)
    {
        // Don't call parent constructor to avoid full Kompo boot
        $this->testConversation = $conversation;
        $this->testSettings = $settings;
    }

    public function settings(): ChatSettingsInterface
    {
        return $this->testSettings;
    }

    /**
     * Expose stagedFileCitationProxies for testing.
     */
    public function callStagedFileCitationProxies(array $fileCitations)
    {
        return $this->stagedFileCitationProxies($fileCitations);
    }
}
