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

            // ── Ventana de tiempo ───────────────────────────────────────────
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable(); // null = entrada en curso
            $table->unsignedInteger('duration_seconds')->nullable(); // calculado al cerrar

            $table->timestamps();

            // ── Índices ─────────────────────────────────────────────────────
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idle_time_entries');
    }
};
