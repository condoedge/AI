<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\SchemaInspector;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;

/**
 * Schema Inspector Tests
 *
 * Tests the SchemaInspector service for database schema introspection.
 *
 * @package Condoedge\Ai\Tests\Unit\Services\Discovery
 */
class SchemaInspectorTest extends TestCase
{
    private SchemaInspector $inspector;

    public function setUp(): void
    {
        parent::setUp();

        $this->inspector = new SchemaInspector();

        // Clear all caches before each test
        Cache::flush();
    }

    /** @test */
    public function it_detects_foreign_keys_from_mysql_constraints(): void
    {
        // Mock database driver
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('test_db');
        $connection->shouldIgnoreMissing(); // Ignore other method calls

        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('select')
            ->once()
            ->with(
                Mockery::on(function ($query) {
                    return str_contains($query, 'INFORMATION_SCHEMA.KEY_COLUMN_USAGE');
                }),
                ['test_db', 'orders']
            )
            ->andReturn([
                (object) [
                    'COLUMN_NAME' => 'customer_id',
                    'REFERENCED_TABLE_NAME' => 'customers',
                    'REFERENCED_COLUMN_NAME' => 'id',
                ],
                (object) [
                    'COLUMN_NAME' => 'product_id',
                    'REFERENCED_TABLE_NAME' => 'products',
                    'REFERENCED_COLUMN_NAME' => 'id',
                ],
            ]);

        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('orders')
            ->andReturn(['id', 'customer_id', 'product_id', 'total']);

        $foreignKeys = $this->inspector->getForeignKeys('orders');

        $this->assertIsArray($foreignKeys);
        $this->assertArrayHasKey('customer_id', $foreignKeys);
        $this->assertArrayHasKey('product_id', $foreignKeys);
        $this->assertEquals('customers', $foreignKeys['customer_id']['table']);
        $this->assertEquals('id', $foreignKeys['customer_id']['column']);
        $this->assertEquals('products', $foreignKeys['product_id']['table']);
        $this->assertEquals('id', $foreignKeys['product_id']['column']);
    }

    /** @test */
    public function it_detects_foreign_keys_from_naming_convention(): void
    {
        // Mock database driver
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('test_db');
        $connection->shouldIgnoreMissing();

        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('select')->andReturn([]); // No actual constraints

        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('posts')
            ->andReturn(['id', 'user_id', 'category_id', 'title', 'content']);

        Schema::shouldReceive('hasTable')
            ->with('users')
            ->andReturn(true);

        Schema::shouldReceive('hasTable')
            ->with('categories')
            ->andReturn(true);

        $foreignKeys = $this->inspector->getForeignKeys('posts');

        $this->assertIsArray($foreignKeys);
        $this->assertArrayHasKey('user_id', $foreignKeys);
        $this->assertArrayHasKey('category_id', $foreignKeys);
        $this->assertEquals('users', $foreignKeys['user_id']['table']);
        $this->assertEquals('categories', $foreignKeys['category_id']['table']);
    }

    /** @test */
    public function it_ignores_foreign_key_pattern_when_table_does_not_exist(): void
    {
        // Mock database driver
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('test_db');
        $connection->shouldIgnoreMissing();

        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('select')->andReturn([]); // No actual constraints

        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('posts')
            ->andReturn(['id', 'nonexistent_table_id', 'title']);

        Schema::shouldReceive('hasTable')
            ->with('nonexistent_tables')
            ->andReturn(false);

        $foreignKeys = $this->inspector->getForeignKeys('posts');

        $this->assertIsArray($foreignKeys);
        $this->assertArrayNotHasKey('nonexistent_table_id', $foreignKeys);
    }

    /** @test */
    public function it_detects_text_columns_by_type(): void
    {
        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('articles')
            ->andReturn(['id', 'title', 'body', 'summary', 'tags']);

        Schema::shouldReceive('getColumnType')
            ->with('articles', 'id')
            ->andReturn('integer');

        Schema::shouldReceive('getColumnType')
            ->with('articles', 'title')
            ->andReturn('string');

        Schema::shouldReceive('getColumnType')
            ->with('articles', 'body')
            ->andReturn('text');

        Schema::shouldReceive('getColumnType')
            ->with('articles', 'summary')
            ->andReturn('text');

        Schema::shouldReceive('getColumnType')
            ->with('articles', 'tags')
            ->andReturn('string');

        $textColumns = $this->inspector->getTextColumns('articles');

        $this->assertIsArray($textColumns);
        $this->assertContains('body', $textColumns);
        $this->assertContains('summary', $textColumns);
        $this->assertNotContains('id', $textColumns);
        $this->assertNotContains('title', $textColumns);
    }

    /** @test */
    public function it_detects_text_columns_by_name_pattern(): void
    {
        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('products')
            ->andReturn(['id', 'name', 'description', 'product_details', 'notes']);

        Schema::shouldReceive('getColumnType')
            ->with('products', 'id')
            ->andReturn('integer');

        Schema::shouldReceive('getColumnType')
            ->with('products', 'name')
            ->andReturn('string');

        Schema::shouldReceive('getColumnType')
            ->with('products', 'description')
            ->andReturn('string');

        Schema::shouldReceive('getColumnType')
            ->with('products', 'product_details')
            ->andReturn('string');

        Schema::shouldReceive('getColumnType')
            ->with('products', 'notes')
            ->andReturn('string');

        $textColumns = $this->inspector->getTextColumns('products');

        $this->assertIsArray($textColumns);
        $this->assertContains('description', $textColumns);
        $this->assertContains('product_details', $textColumns); // Contains 'details'
        $this->assertContains('notes', $textColumns);
        $this->assertNotContains('name', $textColumns);
    }

    /** @test */
    public function it_detects_longtext_and_mediumtext_columns(): void
    {
        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('documents')
            ->andReturn(['id', 'content', 'metadata']);

        Schema::shouldReceive('getColumnType')
            ->with('documents', 'id')
            ->andReturn('integer');

        Schema::shouldReceive('getColumnType')
            ->with('documents', 'content')
            ->andReturn('longtext');

        Schema::shouldReceive('getColumnType')
            ->with('documents', 'metadata')
            ->andReturn('mediumtext');

        $textColumns = $this->inspector->getTextColumns('documents');

        $this->assertIsArray($textColumns);
        $this->assertContains('content', $textColumns);
        $this->assertContains('metadata', $textColumns);
    }

    /** @test */
    public function it_gets_indexed_columns_from_mysql(): void
    {
        // Mock database driver
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('test_db');

        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('select')
            ->once()
            ->with(
                Mockery::on(function ($query) {
                    return str_contains($query, 'INFORMATION_SCHEMA.STATISTICS');
                }),
                ['test_db', 'users']
            )
            ->andReturn([
                (object) [
                    'INDEX_NAME' => 'PRIMARY',
                    'COLUMN_NAME' => 'id',
                    'NON_UNIQUE' => 0,
                ],
                (object) [
                    'INDEX_NAME' => 'users_email_unique',
                    'COLUMN_NAME' => 'email',
                    'NON_UNIQUE' => 0,
                ],
                (object) [
                    'INDEX_NAME' => 'users_status_index',
                    'COLUMN_NAME' => 'status',
                    'NON_UNIQUE' => 1,
                ],
            ]);

        $indexedColumns = $this->inspector->getIndexedColumns('users');

        $this->assertIsArray($indexedColumns);
        $this->assertContains('email', $indexedColumns);
        $this->assertContains('status', $indexedColumns);
        $this->assertNotContains('id', $indexedColumns); // PRIMARY key excluded
    }

    /** @test */
    public function it_gets_all_column_types(): void
    {
        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('users')
            ->andReturn(['id', 'name', 'email', 'bio', 'active', 'created_at']);

        Schema::shouldReceive('getColumnType')
            ->with('users', 'id')
            ->andReturn('integer');

        Schema::shouldReceive('getColumnType')
            ->with('users', 'name')
            ->andReturn('string');

        Schema::shouldReceive('getColumnType')
            ->with('users', 'email')
            ->andReturn('string');

        Schema::shouldReceive('getColumnType')
            ->with('users', 'bio')
            ->andReturn('text');

        Schema::shouldReceive('getColumnType')
            ->with('users', 'active')
            ->andReturn('boolean');

        Schema::shouldReceive('getColumnType')
            ->with('users', 'created_at')
            ->andReturn('datetime');

        $columnTypes = $this->inspector->getColumnTypes('users');

        $this->assertIsArray($columnTypes);
        $this->assertArrayHasKey('id', $columnTypes);
        $this->assertArrayHasKey('name', $columnTypes);
        $this->assertArrayHasKey('bio', $columnTypes);
        $this->assertEquals('integer', $columnTypes['id']);
        $this->assertEquals('string', $columnTypes['name']);
        $this->assertEquals('text', $columnTypes['bio']);
        $this->assertEquals('boolean', $columnTypes['active']);
        $this->assertEquals('datetime', $columnTypes['created_at']);
    }

    /** @test */
    public function it_caches_foreign_keys(): void
    {
        // Mock database driver
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('test_db');
        $connection->shouldIgnoreMissing();

        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('select')
            ->once() // Should only be called once due to caching
            ->andReturn([
                (object) [
                    'COLUMN_NAME' => 'customer_id',
                    'REFERENCED_TABLE_NAME' => 'customers',
                    'REFERENCED_COLUMN_NAME' => 'id',
                ],
            ]);

        Schema::shouldReceive('getColumnListing')
            ->once() // Should only be called once due to caching
            ->with('orders')
            ->andReturn(['id', 'customer_id']);

        // First call - should hit database
        $foreignKeys1 = $this->inspector->getForeignKeys('orders');

        // Second call - should hit cache
        $foreignKeys2 = $this->inspector->getForeignKeys('orders');

        $this->assertEquals($foreignKeys1, $foreignKeys2);
        $this->assertArrayHasKey('customer_id', $foreignKeys2);
    }

    /** @test */
    public function it_caches_text_columns(): void
    {
        Schema::shouldReceive('getColumnListing')
            ->once() // Should only be called once due to caching
            ->with('articles')
            ->andReturn(['id', 'body']);

        Schema::shouldReceive('getColumnType')
            ->once()
            ->with('articles', 'id')
            ->andReturn('integer');

        Schema::shouldReceive('getColumnType')
            ->once()
            ->with('articles', 'body')
            ->andReturn('text');

        // First call - should hit database
        $textColumns1 = $this->inspector->getTextColumns('articles');

        // Second call - should hit cache
        $textColumns2 = $this->inspector->getTextColumns('articles');

        $this->assertEquals($textColumns1, $textColumns2);
        $this->assertContains('body', $textColumns2);
    }

    /** @test */
    public function it_clears_cache_for_specific_table(): void
    {
        Schema::shouldReceive('getColumnListing')
            ->twice() // Should be called twice (before and after cache clear)
            ->with('users')
            ->andReturn(['id', 'name']);

        Schema::shouldReceive('getColumnType')
            ->twice()
            ->with('users', 'id')
            ->andReturn('integer');

        Schema::shouldReceive('getColumnType')
            ->twice()
            ->with('users', 'name')
            ->andReturn('string');

        // First call - cache miss
        $types1 = $this->inspector->getColumnTypes('users');

        // Clear cache
        $this->inspector->clearCache('users');

        // Second call - should hit database again
        $types2 = $this->inspector->getColumnTypes('users');

        $this->assertEquals($types1, $types2);
    }

    /** @test */
    public function it_detects_sqlite_foreign_keys(): void
    {
        // Mock database driver
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldIgnoreMissing();

        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('select')
            ->once()
            ->with("PRAGMA foreign_key_list(orders)")
            ->andReturn([
                (object) [
                    'from' => 'customer_id',
                    'table' => 'customers',
                    'to' => 'id',
                ],
            ]);

        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('orders')
            ->andReturn(['id', 'customer_id']);

        $foreignKeys = $this->inspector->getForeignKeys('orders');

        $this->assertIsArray($foreignKeys);
        $this->assertArrayHasKey('customer_id', $foreignKeys);
        $this->assertEquals('customers', $foreignKeys['customer_id']['table']);
        $this->assertEquals('id', $foreignKeys['customer_id']['column']);
    }

    /** @test */
    public function it_detects_sqlite_indexes(): void
    {
        // Mock database driver
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');

        DB::shouldReceive('connection')->andReturn($connection);

        // Mock index list
        DB::shouldReceive('select')
            ->with("PRAGMA index_list(users)")
            ->andReturn([
                (object) [
                    'name' => 'users_email_unique',
                    'unique' => 1,
                    'origin' => 'u',
                ],
                (object) [
                    'name' => 'users_status_index',
                    'unique' => 0,
                    'origin' => 'c',
                ],
            ]);

        // Mock index info for email
        DB::shouldReceive('select')
            ->with("PRAGMA index_info(users_email_unique)")
            ->andReturn([
                (object) ['name' => 'email'],
            ]);

        // Mock index info for status
        DB::shouldReceive('select')
            ->with("PRAGMA index_info(users_status_index)")
            ->andReturn([
                (object) ['name' => 'status'],
            ]);

        $indexedColumns = $this->inspector->getIndexedColumns('users');

        $this->assertIsArray($indexedColumns);
        $this->assertContains('email', $indexedColumns);
        $this->assertContains('status', $indexedColumns);
    }

    /** @test */
    public function it_excludes_primary_key_from_indexed_columns(): void
    {
        // Mock database driver
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');

        DB::shouldReceive('connection')->andReturn($connection);

        // Mock index list with primary key
        DB::shouldReceive('select')
            ->with("PRAGMA index_list(users)")
            ->andReturn([
                (object) [
                    'name' => 'sqlite_autoindex_users_1',
                    'unique' => 1,
                    'origin' => 'pk', // Primary key
                ],
                (object) [
                    'name' => 'users_email_unique',
                    'unique' => 1,
                    'origin' => 'u',
                ],
            ]);

        // Mock index info for primary key
        DB::shouldReceive('select')
            ->with("PRAGMA index_info(sqlite_autoindex_users_1)")
            ->andReturn([
                (object) ['name' => 'id'],
            ]);

        // Mock index info for email
        DB::shouldReceive('select')
            ->with("PRAGMA index_info(users_email_unique)")
            ->andReturn([
                (object) ['name' => 'email'],
            ]);

        $indexedColumns = $this->inspector->getIndexedColumns('users');

        $this->assertIsArray($indexedColumns);
        $this->assertContains('email', $indexedColumns);
        $this->assertNotContains('id', $indexedColumns); // Primary key excluded
    }

    /** @test */
    public function it_handles_composite_indexes(): void
    {
        // Mock database driver
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('test_db');

        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('select')
            ->once()
            ->andReturn([
                (object) [
                    'INDEX_NAME' => 'users_name_email_index',
                    'COLUMN_NAME' => 'name',
                    'NON_UNIQUE' => 1,
                ],
                (object) [
                    'INDEX_NAME' => 'users_name_email_index',
                    'COLUMN_NAME' => 'email',
                    'NON_UNIQUE' => 1,
                ],
            ]);

        $indexedColumns = $this->inspector->getIndexedColumns('users');

        $this->assertIsArray($indexedColumns);
        $this->assertContains('name', $indexedColumns);
        $this->assertContains('email', $indexedColumns);
    }

    /** @test */
    public function it_returns_empty_array_for_unsupported_database_driver(): void
    {
        // Mock unsupported database driver
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('oracle');
        $connection->shouldIgnoreMissing();

        DB::shouldReceive('connection')->andReturn($connection);

        Schema::shouldReceive('getColumnListing')
            ->with('users')
            ->andReturn(['id', 'user_id']);

        Schema::shouldReceive('hasTable')
            ->with('users')
            ->andReturn(true);

        $foreignKeys = $this->inspector->getForeignKeys('users');

        // Should still detect *_id pattern
        $this->assertIsArray($foreignKeys);
        $this->assertArrayHasKey('user_id', $foreignKeys);
    }
}
