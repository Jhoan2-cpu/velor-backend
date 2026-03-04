<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('focus_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // task es nullable: se puede enfocar sin tarea asignada
            $table->foreignId('task_id')
                ->nullable()
                ->constrained('focus_tasks')
                ->nullOnDelete();

            // ── Ventana de tiempo ───────────────────────────────────────────
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable(); // null = sesión en curso
            $table->unsignedInteger('duration_seconds')->nullable(); // calculado al cerrar

            $table->timestamps();

            // ── Índices ─────────────────────────────────────────────────────
            $table->index(['user_id', 'started_at']);
            $table->index(['task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_time_entries');
    }
};
