<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('provider')->default('gemini');
            $table->string('model');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->double('estimated_cost_usd')->default(0.0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['agent_id', 'created_at']);
        });

        Schema::create('agent_chat_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('role'); // 'user' or 'agent'
            $table->text('content');
            $table->string('provider')->nullable();
            $table->jsonb('grounding_snapshot')->default('{}');
            $table->unsignedInteger('tokens_used')->default(0);
            $table->double('cost_usd')->default(0.0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_chat_messages');
        Schema::dropIfExists('ai_usage_logs');
    }
};

