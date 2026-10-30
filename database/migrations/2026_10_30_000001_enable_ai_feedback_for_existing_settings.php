<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rating answers is on by default. A settings row was created from the former default (off), so its
 * value says nothing about what the user wanted: every row starts on, and the user can turn it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_user_settings') && Schema::hasColumn('ai_user_settings', 'enable_feedback')) {
            DB::table('ai_user_settings')->update(['enable_feedback' => true]);
        }
    }

    public function down(): void
    {
        // The former values are not recorded.
    }
};
