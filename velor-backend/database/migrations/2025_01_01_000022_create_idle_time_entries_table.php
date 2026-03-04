<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('idle_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // ── Ventana de tiempo ─────────────────────────────────────────
            $table->timestamp('started_at_utc');
            $table->timestamp('ended_at_utc')->nullable();
            $table->unsignedInteger('elapsed_seconds');
            $table->string('reason', 100)->nullable(); // e.g. 'user_idle', 'break', 'switch'

            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────
            $table->index(['user_id', 'started_at_utc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idle_time_entries');
    }
};
