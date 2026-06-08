<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Services\Context\FileAccessResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * Verifies FileAccessResolver resolves the File model from
 * config('ai.file_context.file_model') so the authorization scope runs against
 * the same class that is indexed (instead of the hardcoded utils FileModel facade).
 */
class FileAccessResolverModelResolutionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('ai.file_context.security_enabled', true);
        $app['config']->set('ai.file_context.access_resolver', null);
    }

    public function setUp(): void
    {
        parent::setUp();

        Schema::create('files', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
        });

        DB::table('files')->insert([
            ['id' => 1, 'user_id' => 7],
            ['id' => 2, 'user_id' => 9],
            ['id' => 3, 'user_id' => 7],
        ]);

        config(['ai.file_context.file_model' => AccessibleByFakeFile::class]);
    }

    public function tearDown(): void
    {
        Schema::dropIfExists('files');
        parent::tearDown();
    }

    /** @test */
    public function it_uses_the_configured_file_model_scope_for_accessible_ids(): void
    {
        $user = new \stdClass();
        $user->id = 7;

        $resolver = new FileAccessResolver();

        $this->assertEqualsCanonicalizing([1, 3], $resolver->getAccessibleFileIds($user));
    }

    /** @test */
    public function it_filters_db_files_through_the_configured_model_scope(): void
    {
        $user = new \stdClass();
        $user->id = 9;

        $resolver = new FileAccessResolver();

        // Only file 2 belongs to user 9; physical files always pass.
        $result = $resolver->filterAccessibleFileIds([1, 2, 3, 'physical:/docs/x.md'], $user);

        $this->assertEqualsCanonicalizing([2, 'physical:/docs/x.md'], $result);
    }

    /** @test */
    public function it_invokes_the_configured_access_scope_method_name_not_a_hardcoded_literal(): void
    {
        // The host configures a non-default scope name. The resolver must call
        // that exact scope on the model rather than the hardcoded 'accessibleBy'.
        // The custom scope selects ONLY id 1, while the user_id fallback would
        // yield [1, 3]; fallback filters are disabled so a wrong (fallback) path
        // returns [] not [1] — proving the configured scope name is honored.
        config(['ai.file_context.file_model' => CustomScopeFakeFile::class]);
        config(['ai.file_context.access_scope' => 'visibleToUser']);
        config(['ai.file_context.fallback_filters.use_user_filter' => false]);
        config(['ai.file_context.fallback_filters.use_team_filter' => false]);

        $user = new \stdClass();
        $user->id = 7;

        $resolver = new FileAccessResolver();

        $this->assertEqualsCanonicalizing([1], $resolver->getAccessibleFileIds($user));
    }
}

/**
 * Minimal Eloquent model standing in for App\Models\File, exposing a
 * scopeAccessibleBy that filters by user_id.
 */
class AccessibleByFakeFile extends Model
{
    protected $table = 'files';
    public $timestamps = false;
    protected $guarded = [];

    public function scopeAccessibleBy($query, $user)
    {
        return $query->where('user_id', $user->id ?? $user->getKey());
    }
}

/**
 * Minimal Eloquent model exposing a non-default scope name (scopeVisibleToUser)
 * to prove the resolver honors config('ai.file_context.access_scope').
 */
class CustomScopeFakeFile extends Model
{
    protected $table = 'files';
    public $timestamps = false;
    protected $guarded = [];

    public function scopeVisibleToUser($query, $user)
    {
        // Deliberately distinct from a user_id filter: selects only id 1 so the
        // test can prove this exact scope ran (not 'accessibleBy', not fallback).
        return $query->where('id', 1);
    }
}
