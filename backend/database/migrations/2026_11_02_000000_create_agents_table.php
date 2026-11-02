<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('status')->default('active');
            $table->unsignedInteger('personality_version')->default(1);
            $table->jsonb('attributes')->default('{}');
            $table->timestampsTz();
        });

        DB::table('agents')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'EXILIO-001',
            'status' => 'active',
            'personality_version' => 1,
            'attributes' => json_encode(['personality' => [], 'autobiographical_memory' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
