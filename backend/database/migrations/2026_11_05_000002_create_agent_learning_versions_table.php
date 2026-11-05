<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_learning_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('operation_type');
            $table->string('summary');
            $table->jsonb('evidence_memory_ids')->default('[]');
            $table->jsonb('attributes_snapshot')->default('{}');
            $table->unsignedInteger('reverted_from_version')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['agent_id', 'version']);
            $table->index(['agent_id', 'operation_type']);
        });

        $this->addIntegrityConstraints();

        // Seed baseline v1 snapshot for existing agents (such as EXILIO-001)
        $agents = DB::table('agents')->get();
        foreach ($agents as $agent) {
            $attrs = json_decode((string) $agent->attributes, true) ?: [];
            if (!isset($attrs['personality'])) {
                $attrs['personality'] = [];
            }
            if (!isset($attrs['interests'])) {
                $attrs['interests'] = [];
            }
            if (!isset($attrs['autobiographical_memory'])) {
                $attrs['autobiographical_memory'] = [];
            }

            DB::table('agents')
                ->where('id', $agent->id)
                ->update(['attributes' => json_encode($attrs)]);

            DB::table('agent_learning_versions')->insert([
                'id' => (string) Str::uuid(),
                'agent_id' => $agent->id,
                'version' => (int) $agent->personality_version,
                'operation_type' => 'initial',
                'summary' => 'Estado inicial fundador sin sesgos ni relevancia precargada.',
                'evidence_memory_ids' => json_encode([]),
                'attributes_snapshot' => json_encode($attrs),
                'reverted_from_version' => null,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_learning_versions');
    }

    private function addIntegrityConstraints(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE agent_learning_versions ADD CONSTRAINT chk_learning_versions_operation_type CHECK (operation_type IN ('initial', 'learning', 'revert'))");
            DB::statement("ALTER TABLE agent_learning_versions ADD CONSTRAINT chk_learning_versions_revert_rule CHECK ((operation_type = 'revert' AND reverted_from_version IS NOT NULL) OR (operation_type != 'revert' AND reverted_from_version IS NULL))");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("
                CREATE TRIGGER chk_learning_versions_insert_integrity BEFORE INSERT ON agent_learning_versions
                BEGIN
                    SELECT CASE
                        WHEN NEW.operation_type NOT IN ('initial', 'learning', 'revert')
                        THEN RAISE(ABORT, 'Invalid learning operation_type')
                        WHEN (NEW.operation_type = 'revert' AND NEW.reverted_from_version IS NULL)
                          OR (NEW.operation_type != 'revert' AND NEW.reverted_from_version IS NOT NULL)
                        THEN RAISE(ABORT, 'Revert version rule violation')
                    END;
                END;
            ");
        }
    }
};

