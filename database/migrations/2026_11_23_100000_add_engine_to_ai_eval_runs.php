<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ai_eval_runs', function (Blueprint $table) {
            $table->string('engine')->nullable()->after('tag');
        });
    }

    public function down()
    {
        Schema::table('ai_eval_runs', function (Blueprint $table) {
            $table->dropColumn('engine');
        });
    }
};
