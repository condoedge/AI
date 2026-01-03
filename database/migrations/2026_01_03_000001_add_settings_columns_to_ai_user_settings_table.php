<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_user_settings', function (Blueprint $table) {
            // Boolean toggle settings
            $table->boolean('show_avatars')->default(true);
            $table->boolean('show_timestamps')->default(false);
            $table->boolean('show_metrics')->default(false);
            $table->boolean('show_suggestions')->default(true);
            $table->boolean('enable_copy')->default(true);
            $table->boolean('enable_feedback')->default(true);
            $table->boolean('enable_regenerate')->default(true);
            $table->boolean('enable_edit')->default(true);

            // String setting
            $table->string('response_style')->default('friendly');

            // Remove JSON column (no longer needed)
            $table->dropColumn('chat_settings');
        });
    }

    public function down(): void
    {
        Schema::table('ai_user_settings', function (Blueprint $table) {
            // Restore JSON column
            $table->json('chat_settings')->nullable();

            // Remove direct columns
            $table->dropColumn([
                'show_avatars',
                'show_timestamps',
                'show_metrics',
                'show_suggestions',
                'enable_copy',
                'enable_feedback',
                'enable_regenerate',
                'enable_edit',
                'response_style',
            ]);
        });
    }
};
