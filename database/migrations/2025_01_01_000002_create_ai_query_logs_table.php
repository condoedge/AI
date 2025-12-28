<?php
// database/migrations/2025_01_01_000002_create_ai_query_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_query_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('team_id')->nullable()->index();
            $table->foreignId('conversation_id')->nullable()->index();
            $table->text('question');
            $table->text('cypher_query')->nullable();
            $table->string('template_used')->nullable();
            $table->float('confidence_score')->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->integer('result_count')->nullable();
            $table->string('status'); // success, failed, timeout, rejected
            $table->text('error_message')->nullable();
            $table->json('context_stats')->nullable(); // tokens used, entities matched
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['template_used', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_query_logs');
    }
};
