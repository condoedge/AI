<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_eval_runs', function (Blueprint $table) {
            $table->id();
            $table->string('suite');
            $table->string('tag')->nullable();
            $table->json('fixture')->nullable();
            $table->unsignedTinyInteger('status');
            $table->unsignedInteger('cases_total')->default(0);
            $table->unsignedInteger('cases_done')->default(0);
            $table->unsignedInteger('turns_passed')->default(0);
            $table->unsignedInteger('turns_failed')->default(0);
            $table->unsignedInteger('turns_ungraded')->default(0);
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_eval_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_eval_run_id')->constrained()->cascadeOnDelete();
            $table->string('case_id');
            $table->unsignedSmallInteger('repetition')->default(1);
            $table->unsignedSmallInteger('turn')->default(1);
            $table->string('role')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('question');
            $table->longText('answer')->nullable();
            $table->boolean('passed')->nullable();
            $table->json('checks')->nullable();
            $table->json('trace')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['ai_eval_run_id', 'case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_eval_results');
        Schema::dropIfExists('ai_eval_runs');
    }
};
