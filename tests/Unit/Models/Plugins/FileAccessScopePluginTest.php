<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Models\Plugins;

use Condoedge\Ai\Models\Plugins\FileAccessScopePlugin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * Verifies the built-in accessibleBy macro fails closed when the host has not
 * defined its own scopeAccessibleBy, and defers to a host scope when present.
 *
 * The macro is registered globally on the Eloquent query Builder, which means
 * Builder::__call resolves the global macro BEFORE a model named scope. The
 * macro must therefore detect and delegate to a host scopeAccessibleBy itself,
 * and otherwise deny by default (team-grain OR-filtering is opt-in only).
 */
class FileAccessScopePluginTest extends TestCase
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
    }

    public function setUp(): void
    {
        parent::setUp();

        // Register the macro exactly as the service provider does at boot.
        (new FileAccessScopePlugin(PluginUnconfiguredFile::class))->onBoot();

        Schema::create('files', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('team_id')->nullable();
        });

        DB::table('files')->insert([
            ['id' => 1, 'user_id' => 7, 'team_id' => 100],
            ['id' => 2, 'user_id' => 9, 'team_id' => 100],
            ['id' => 3, 'user_id' => 7, 'team_id' => 200],
        ]);
    }

    public function tearDown(): void
    {
        Schema::dropIfExists('files');
        parent::tearDown();
    }

    /** @test */
    public function it_denies_all_files_by_default_when_host_has_no_scope_accessible_by(): void
    {
        // Default config: team-grain opt-in is OFF. With no host scopeAccessibleBy,
        // the macro must fail closed (return no rows), NOT expose team-wide files.
        $user = new \stdClass();
        $user->id = 7;

        $ids = PluginUnconfiguredFile::query()->accessibleBy($user)->pluck('id')->toArray();

        $this->assertSame([], $ids);
    }

    /** @test */
    public function it_defers_to_a_host_defined_scope_accessible_by(): void
    {
        // The host model defines its own scopeAccessibleBy. Because the global
        // macro shadows named scopes in Builder::__call, the macro must delegate
        // to the host scope rather than win silently.
        $user = new \stdClass();
        $user->id = 7;

        $ids = PluginHostScopedFile::query()->accessibleBy($user)->pluck('id')->toArray();

        // Host scope selects only id 2, proving the host rule actually ran.
        $this->assertSame([2], $ids);
    }
}

/**
 * Stand-in File model with NO scopeAccessibleBy — represents an unconfigured host.
 */
class PluginUnconfiguredFile extends Model
{
    protected $table = 'files';
    public $timestamps = false;
    protected $guarded = [];
}

/**
 * Stand-in File model that defines its own scopeAccessibleBy (configured host).
 * The rule is intentionally arbitrary (id = 2) to prove it ran.
 */
class PluginHostScopedFile extends Model
{
    protected $table = 'files';
    public $timestamps = false;
    protected $guarded = [];

    public function scopeAccessibleBy($query, $user)
    {
        return $query->where('id', 2);
    }
}
