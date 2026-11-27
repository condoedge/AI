<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_eval_results', function (Blueprint $table) {
            // void / infra (App\Ai\Eval\TurnLabel): the turn did not measure the answer, run it again.
            $table->string('label', 16)->nullable()->after('passed');
        });
    }

    public function down(): void
    {
        Schema::table('ai_eval_results', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
