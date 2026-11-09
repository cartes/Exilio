<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('research_scouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('topic', 120);
            $table->string('query', 500);
            // proposed | approved | running | completed | failed | rejected | cancelled
            $table->string('status', 20)->default('proposed');
            // agent_proposal | auditor
            $table->string('origin', 20)->default('agent_proposal');
            $table->text('proposal_reason')->nullable();
            $table->unsignedSmallInteger('max_pages')->default(5);
            $table->unsignedSmallInteger('pages_fetched')->default(0);
            $table->jsonb('run_log')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['agent_id', 'status']);
        });

        Schema::create('source_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('scout_id')->constrained('research_scouts')->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('title', 300)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_hash', 64);
            $table->text('text');
            $table->timestampTz('fetched_at')->useCurrent();

            $table->index('scout_id');
        });

        Schema::create('research_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('scout_id')->constrained('research_scouts')->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignUuid('source_document_id')->constrained('source_documents')->cascadeOnDelete();
            // Fragmento textual extraído de la fuente (extracto, no interpretación).
            $table->text('excerpt');
            $table->string('excerpt_hash', 64);
            $table->double('relevance')->default(0);
            // pending | approved | rejected
            $table->string('status', 20)->default('pending');
            $table->uuid('memory_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['scout_id', 'status']);
            $table->index(['agent_id', 'excerpt_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_findings');
        Schema::dropIfExists('source_documents');
        Schema::dropIfExists('research_scouts');
    }
};
