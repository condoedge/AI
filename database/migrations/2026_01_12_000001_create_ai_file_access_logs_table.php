<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_file_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_id'); // Can be int or physical:path
            $table->boolean('access_granted');
            $table->string('access_method')->nullable(); // closure, macro, fallback
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['file_id', 'access_granted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_file_access_logs');
    }
};
