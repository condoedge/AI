<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_eval_runs', function (Blueprint $table) {
            // The conversations of a run that has no suite file: pasted on the test page, or --ask.
            $table->json('definition')->nullable()->after('fixture');
        });
    }

    public function down(): void
    {
        Schema::table('ai_eval_runs', function (Blueprint $table) {
            $table->dropColumn('definition');
        });
    }
};
