<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_eval_runs', function (Blueprint $table) {
            $table->json('tested')->nullable()->after('fixture');
            $table->unsignedBigInteger('rerun_of_id')->nullable()->after('started_by');
            $table->timestamp('last_case_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_eval_runs', function (Blueprint $table) {
            $table->dropColumn(['tested', 'rerun_of_id', 'last_case_at']);
        });
    }
};
