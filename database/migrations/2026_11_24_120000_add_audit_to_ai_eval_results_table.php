<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_eval_results', function (Blueprint $table) {
            $table->json('audit')->nullable()->after('checks');
        });
    }

    public function down(): void
    {
        Schema::table('ai_eval_results', function (Blueprint $table) {
            $table->dropColumn('audit');
        });
    }
};
