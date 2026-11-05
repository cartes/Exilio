<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('type');
            $table->string('epistemic_status');
            $table->string('origin');
            $table->text('content');
            $table->double('confidence')->nullable();
            $table->string('evidence_ref')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            if (DB::getDriverName() === 'pgsql') {
                $table->uuid('supersedes_id')->nullable();
            } else {
                $table->foreignUuid('supersedes_id')->nullable()->constrained('memories')->nullOnDelete();
            }
            $table->timestampTz('superseded_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['agent_id', 'type']);
            $table->index(['agent_id', 'epistemic_status']);
            $table->index(['agent_id', 'superseded_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('memories', function (Blueprint $table): void {
                $table->foreign('supersedes_id')->references('id')->on('memories')->nullOnDelete();
            });
        }

        Schema::create('memory_tombstones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('memory_id');
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        $this->addIntegrityConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_tombstones');
        Schema::dropIfExists('memories');
    }

    private function addIntegrityConstraints(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE memories ADD CONSTRAINT chk_memories_valid_type CHECK (type IN ('episodic', 'semantic', 'social', 'procedural', 'oneiric'))");
            DB::statement("ALTER TABLE memories ADD CONSTRAINT chk_memories_valid_epistemic_status CHECK (epistemic_status IN ('fact', 'hypothesis', 'dream'))");
            DB::statement("ALTER TABLE memories ADD CONSTRAINT chk_memories_valid_origin CHECK (origin IN ('user_input', 'system_event', 'manual_entry', 'agent_inference'))");
            DB::statement("ALTER TABLE memories ADD CONSTRAINT chk_memories_oneiric_rule CHECK ((type = 'oneiric' AND epistemic_status = 'dream') OR (type != 'oneiric' AND epistemic_status IN ('fact', 'hypothesis')))");
            DB::statement("ALTER TABLE memories ADD CONSTRAINT chk_memories_fact_origin_rule CHECK ((epistemic_status != 'fact') OR (origin IN ('user_input', 'system_event', 'manual_entry')))");
            DB::statement("ALTER TABLE memories ADD CONSTRAINT chk_memories_confidence_rule CHECK ((epistemic_status = 'hypothesis' AND confidence IS NOT NULL AND confidence >= 0.0 AND confidence <= 1.0) OR (epistemic_status != 'hypothesis' AND confidence IS NULL))");
        } elseif ($driver === 'sqlite') {
            // Enforce constraints in SQLite using BEFORE INSERT / UPDATE triggers
            DB::unprepared("
                CREATE TRIGGER chk_memories_insert_integrity BEFORE INSERT ON memories
                BEGIN
                    SELECT CASE
                        WHEN NEW.type NOT IN ('episodic', 'semantic', 'social', 'procedural', 'oneiric')
                        THEN RAISE(ABORT, 'Invalid memory type')
                        WHEN NEW.epistemic_status NOT IN ('fact', 'hypothesis', 'dream')
                        THEN RAISE(ABORT, 'Invalid epistemic status')
                        WHEN NEW.origin NOT IN ('user_input', 'system_event', 'manual_entry', 'agent_inference')
                        THEN RAISE(ABORT, 'Invalid memory origin')
                        WHEN (NEW.type = 'oneiric' AND NEW.epistemic_status != 'dream') OR (NEW.type != 'oneiric' AND NEW.epistemic_status = 'dream')
                        THEN RAISE(ABORT, 'Oneiric rule violation')
                        WHEN NEW.epistemic_status = 'fact' AND NEW.origin NOT IN ('user_input', 'system_event', 'manual_entry')
                        THEN RAISE(ABORT, 'Fact origin rule violation')
                        WHEN NEW.epistemic_status = 'hypothesis' AND (NEW.confidence IS NULL OR NEW.confidence < 0.0 OR NEW.confidence > 1.0)
                        THEN RAISE(ABORT, 'Hypothesis confidence rule violation')
                        WHEN NEW.epistemic_status != 'hypothesis' AND NEW.confidence IS NOT NULL
                        THEN RAISE(ABORT, 'Non-hypothesis confidence must be null')
                    END;
                END;
            ");
            DB::unprepared("
                CREATE TRIGGER chk_memories_update_integrity BEFORE UPDATE ON memories
                BEGIN
                    SELECT CASE
                        WHEN NEW.type NOT IN ('episodic', 'semantic', 'social', 'procedural', 'oneiric')
                        THEN RAISE(ABORT, 'Invalid memory type')
                        WHEN NEW.epistemic_status NOT IN ('fact', 'hypothesis', 'dream')
                        THEN RAISE(ABORT, 'Invalid epistemic status')
                        WHEN NEW.origin NOT IN ('user_input', 'system_event', 'manual_entry', 'agent_inference')
                        THEN RAISE(ABORT, 'Invalid memory origin')
                        WHEN (NEW.type = 'oneiric' AND NEW.epistemic_status != 'dream') OR (NEW.type != 'oneiric' AND NEW.epistemic_status = 'dream')
                        THEN RAISE(ABORT, 'Oneiric rule violation')
                        WHEN NEW.epistemic_status = 'fact' AND NEW.origin NOT IN ('user_input', 'system_event', 'manual_entry')
                        THEN RAISE(ABORT, 'Fact origin rule violation')
                        WHEN NEW.epistemic_status = 'hypothesis' AND (NEW.confidence IS NULL OR NEW.confidence < 0.0 OR NEW.confidence > 1.0)
                        THEN RAISE(ABORT, 'Hypothesis confidence rule violation')
                        WHEN NEW.epistemic_status != 'hypothesis' AND NEW.confidence IS NOT NULL
                        THEN RAISE(ABORT, 'Non-hypothesis confidence must be null')
                    END;
                END;
            ");
        }
    }
};