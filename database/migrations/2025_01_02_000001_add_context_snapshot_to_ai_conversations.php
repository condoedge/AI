<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->json('context_snapshot')->nullable()->after('metadata');
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->json('extracted_entities')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn('context_snapshot');
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('extracted_entities');
        });
    }
};
