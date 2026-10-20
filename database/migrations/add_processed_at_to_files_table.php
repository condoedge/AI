<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('files') || Schema::hasColumn('files', 'processed_at')) {
            return;
        }

        Schema::table('files', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('updated_at')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('files') || !Schema::hasColumn('files', 'processed_at')) {
            return;
        }

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('processed_at');
        });
    }
};
