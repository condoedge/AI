<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove unused extracted_entities column from ai_messages.
 *
 * This column was added but never used - entity extraction is handled
 * via context_snapshot on ai_conversations instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('extracted_entities');
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->json('extracted_entities')->nullable()->after('metadata');
        });
    }
};
