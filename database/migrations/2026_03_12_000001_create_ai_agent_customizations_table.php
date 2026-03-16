<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_customizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('agent_id');
            $table->text('custom_instructions')->nullable();
            $table->json('custom_business_rules')->nullable();
            $table->json('custom_example_questions')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'team_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_customizations');
    }
};
