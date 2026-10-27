<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('definition_key');
            $table->foreignId('conversation_id');
            $table->foreignId('user_id');
            $table->foreignId('team_id')->nullable();
            $table->string('status');
            $table->json('draft')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('turns')->default(0);
            $table->unsignedInteger('llm_calls')->default(0);
            $table->unsignedInteger('tokens')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('outcome')->nullable();
            $table->char('last_event_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['team_id', 'created_at']);
        });

        // Append-only audit: hash = sha256(prev_hash . canonical json of the other fields).
        Schema::create('ai_task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('ai_tasks');
            $table->timestamp('at');
            $table->foreignId('actor_user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('kind');
            $table->string('field')->nullable();
            $table->json('value_before')->nullable();
            $table->json('value_after')->nullable();
            $table->json('payload')->nullable();
            $table->char('hash', 64);
            $table->char('prev_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_task_events');
        Schema::dropIfExists('ai_tasks');
    }
};
