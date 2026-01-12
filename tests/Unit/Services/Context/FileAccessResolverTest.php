<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Services\Context\FileAccessResolver;
use Orchestra\Testbench\TestCase;

class FileAccessResolverTest extends TestCase
{
    private FileAccessResolver $resolver;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Default config setup
        $app['config']->set('ai.file_context.security_enabled', true);
        $app['config']->set('ai.file_context.file_model', null);
        $app['config']->set('ai.file_context.access_scope', 'accessibleBy');
        $app['config']->set('ai.file_context.access_resolver', null);
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new FileAccessResolver();
    }

    // =========================================================================
    // shouldEnforceSecurity tests
    // =========================================================================

    /** @test */
    public function it_returns_true_when_security_is_enabled_in_config(): void
    {
        config(['ai.file_context.security_enabled' => true]);

        $this->assertTrue($this->resolver->shouldEnforceSecurity());
    }

    /** @test */
    public function it_returns_false_when_security_is_disabled_in_config(): void
    {
        config(['ai.file_context.security_enabled' => false]);

        $this->assertFalse($this->resolver->shouldEnforceSecurity());
    }

    // =========================================================================
    // Physical file ID helper tests
    // =========================================================================

    /** @test */
    public function it_correctly_identifies_physical_file_ids(): void
    {
        $this->assertTrue($this->resolver->isPhysicalFile('physical:/path/to/file.md'));
        $this->assertTrue($this->resolver->isPhysicalFile('physical:docs/readme.txt'));
        $this->assertFalse($this->resolver->isPhysicalFile('123'));
        $this->assertFalse($this->resolver->isPhysicalFile('file_123'));
        $this->assertFalse($this->resolver->isPhysicalFile('/path/without/prefix.md'));
    }

    /** @test */
    public function it_creates_physical_file_id_with_prefix(): void
    {
        $path = '/docs/readme.md';
        $id = $this->resolver->makePhysicalFileId($path);

        $this->assertEquals('physical:/docs/readme.md', $id);
    }

    /** @test */
    public function it_extracts_path_from_physical_file_id(): void
    {
        $id = 'physical:/docs/readme.md';
        $path = $this->resolver->getPhysicalFilePath($id);

        $this->assertEquals('/docs/readme.md', $path);
    }

    /** @test */
    public function it_returns_null_for_non_physical_file_path_extraction(): void
    {
        $path = $this->resolver->getPhysicalFilePath('123');

        $this->assertNull($path);
    }

    // =========================================================================
    // getAccessibleFileIds tests
    // =========================================================================

    /** @test */
    public function it_returns_empty_array_when_no_user_and_security_enabled(): void
    {
        config(['ai.file_context.security_enabled' => true]);

        $result = $this->resolver->getAccessibleFileIds(null);

        $this->assertEquals([], $result);
    }

    /** @test */
    public function it_uses_config_closure_resolver_when_provided(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.access_resolver' => function ($u) {
                return [1, 2, 3];
            },
        ]);

        // Recreate resolver to pick up new config
        $resolver = new FileAccessResolver();
        $result = $resolver->getAccessibleFileIds($user);

        $this->assertEquals([1, 2, 3], $result);
    }

    /** @test */
    public function it_passes_user_to_config_closure_resolver(): void
    {
        $user = new \stdClass();
        $user->id = 42;

        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.access_resolver' => function ($u) {
                return [$u->id, $u->id * 2];
            },
        ]);

        $resolver = new FileAccessResolver();
        $result = $resolver->getAccessibleFileIds($user);

        $this->assertEquals([42, 84], $result);
    }

    // =========================================================================
    // filterAccessibleFileIds tests
    // =========================================================================

    /** @test */
    public function it_always_passes_physical_files_regardless_of_security(): void
    {
        config(['ai.file_context.security_enabled' => true]);

        $fileIds = [
            'physical:/docs/readme.md',
            'physical:/docs/api.md',
        ];

        $result = $this->resolver->filterAccessibleFileIds($fileIds, null);

        $this->assertEquals($fileIds, $result);
    }

    /** @test */
    public function it_returns_all_files_when_security_disabled(): void
    {
        config(['ai.file_context.security_enabled' => false]);

        $fileIds = [1, 2, 3, 'physical:/docs/readme.md'];

        $result = $this->resolver->filterAccessibleFileIds($fileIds, null);

        $this->assertEquals($fileIds, $result);
    }

    /** @test */
    public function it_filters_database_files_based_on_access_with_security_enabled(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.access_resolver' => function ($u) {
                return [1, 3]; // User can only access files 1 and 3
            },
        ]);

        $resolver = new FileAccessResolver();

        $fileIds = [1, 2, 3, 4, 'physical:/docs/readme.md'];
        $result = $resolver->filterAccessibleFileIds($fileIds, $user);

        // Should include accessible database files + all physical files
        $this->assertEquals([1, 3, 'physical:/docs/readme.md'], $result);
    }

    /** @test */
    public function it_returns_only_physical_files_when_no_user_and_security_enabled(): void
    {
        config(['ai.file_context.security_enabled' => true]);

        $fileIds = [1, 2, 'physical:/docs/readme.md', 'physical:/docs/api.md'];

        $result = $this->resolver->filterAccessibleFileIds($fileIds, null);

        // Should only return physical files since no user = no database file access
        $this->assertEquals(['physical:/docs/readme.md', 'physical:/docs/api.md'], $result);
    }

    /** @test */
    public function it_handles_mixed_file_ids_correctly(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.access_resolver' => function ($u) {
                return [2, 4];
            },
        ]);

        $resolver = new FileAccessResolver();

        $fileIds = [
            1,
            2,
            'physical:/docs/a.md',
            3,
            4,
            'physical:/docs/b.md',
        ];

        $result = $resolver->filterAccessibleFileIds($fileIds, $user);

        // Should maintain relative order: accessible db files + physical files
        $this->assertEquals([2, 'physical:/docs/a.md', 4, 'physical:/docs/b.md'], $result);
    }

    // =========================================================================
    // canAccessFile tests
    // =========================================================================

    /** @test */
    public function it_always_allows_access_to_physical_files(): void
    {
        config(['ai.file_context.security_enabled' => true]);

        $this->assertTrue($this->resolver->canAccessFile('physical:/docs/readme.md', null));
        $this->assertTrue($this->resolver->canAccessFile('physical:/docs/readme.md', new \stdClass()));
    }

    /** @test */
    public function it_allows_access_to_any_file_when_security_disabled(): void
    {
        config(['ai.file_context.security_enabled' => false]);

        $this->assertTrue($this->resolver->canAccessFile(1, null));
        $this->assertTrue($this->resolver->canAccessFile('123', null));
        $this->assertTrue($this->resolver->canAccessFile('physical:/docs/readme.md', null));
    }

    /** @test */
    public function it_denies_database_file_access_when_no_user_and_security_enabled(): void
    {
        config(['ai.file_context.security_enabled' => true]);

        $this->assertFalse($this->resolver->canAccessFile(1, null));
        $this->assertFalse($this->resolver->canAccessFile('123', null));
    }

    /** @test */
    public function it_checks_access_using_resolver_for_database_files(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.access_resolver' => function ($u) {
                return [1, 3, 5];
            },
        ]);

        $resolver = new FileAccessResolver();

        $this->assertTrue($resolver->canAccessFile(1, $user));
        $this->assertFalse($resolver->canAccessFile(2, $user));
        $this->assertTrue($resolver->canAccessFile(3, $user));
        $this->assertFalse($resolver->canAccessFile(4, $user));
        $this->assertTrue($resolver->canAccessFile(5, $user));
    }

    /** @test */
    public function it_handles_string_database_file_ids(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.access_resolver' => function ($u) {
                return ['abc', 'def'];
            },
        ]);

        $resolver = new FileAccessResolver();

        $this->assertTrue($resolver->canAccessFile('abc', $user));
        $this->assertTrue($resolver->canAccessFile('def', $user));
        $this->assertFalse($resolver->canAccessFile('xyz', $user));
    }

    // =========================================================================
    // Interface implementation test
    // =========================================================================

    /** @test */
    public function it_implements_file_access_resolver_interface(): void
    {
        $this->assertInstanceOf(FileAccessResolverInterface::class, $this->resolver);
    }

    // =========================================================================
    // PHYSICAL_PREFIX constant test
    // =========================================================================

    /** @test */
    public function it_has_physical_prefix_constant(): void
    {
        $this->assertEquals('physical:', FileAccessResolver::PHYSICAL_PREFIX);
    }

    // =========================================================================
    // File access audit logging tests
    // =========================================================================

    /** @test */
    public function test_logs_file_access_attempts(): void
    {
        // Arrange: Set up database for logging
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');

        $user = new \stdClass();
        $user->id = 1;
        $fileId = 123;

        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.log_access' => true,
            'ai.file_context.access_resolver' => function ($u) {
                return [123]; // User can access file 123
            },
        ]);

        $resolver = new FileAccessResolver();

        // Act
        $resolver->canAccessFile($fileId, $user);

        // Assert: Access attempt was logged
        $this->assertDatabaseHas('ai_file_access_logs', [
            'user_id' => $user->id,
            'file_id' => (string) $fileId,
        ]);
    }

    /** @test */
    public function test_logs_unauthorized_access_attempts(): void
    {
        // Arrange: Set up database for logging
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');

        $user = new \stdClass();
        $user->id = 1;
        $unauthorizedFileId = 999;

        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.log_access' => true,
            'ai.file_context.access_resolver' => function ($u) {
                return [1, 2, 3]; // User can only access files 1, 2, 3
            },
        ]);

        $resolver = new FileAccessResolver();

        // Act
        $canAccess = $resolver->canAccessFile($unauthorizedFileId, $user);

        // Assert: Unauthorized attempt logged with denied status
        $this->assertFalse($canAccess);
        $this->assertDatabaseHas('ai_file_access_logs', [
            'user_id' => $user->id,
            'file_id' => (string) $unauthorizedFileId,
            'access_granted' => false,
        ]);
    }

    /** @test */
    public function test_logs_physical_file_access(): void
    {
        // Arrange: Set up database for logging
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');

        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.log_access' => true,
        ]);

        $resolver = new FileAccessResolver();
        $physicalFileId = 'physical:/docs/readme.md';

        // Act
        $canAccess = $resolver->canAccessFile($physicalFileId, null);

        // Assert: Physical file access logged as granted
        $this->assertTrue($canAccess);
        $this->assertDatabaseHas('ai_file_access_logs', [
            'file_id' => $physicalFileId,
            'access_granted' => true,
            'access_method' => 'physical',
        ]);
    }

    /** @test */
    public function test_does_not_log_when_logging_disabled(): void
    {
        // Arrange: Set up database for logging
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');

        $user = new \stdClass();
        $user->id = 1;
        $fileId = 123;

        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.log_access' => false, // Logging disabled
            'ai.file_context.access_resolver' => function ($u) {
                return [123];
            },
        ]);

        $resolver = new FileAccessResolver();

        // Act
        $resolver->canAccessFile($fileId, $user);

        // Assert: No log entry created
        $this->assertDatabaseMissing('ai_file_access_logs', [
            'user_id' => $user->id,
            'file_id' => (string) $fileId,
        ]);
    }
}
