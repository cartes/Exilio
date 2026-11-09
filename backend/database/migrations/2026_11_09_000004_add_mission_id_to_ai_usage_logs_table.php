<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            // Permite que el servidor calcule el gasto real de una misión de investigación.
            $table->uuid('mission_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->dropIndex(['mission_id']);
            $table->dropColumn('mission_id');
        });
    }
};
