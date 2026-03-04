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

            // ── Tarea origen (nullable) ────────────────────────────────────
            $table->foreignId('focus_task_id_nullable')
                ->nullable()
                ->constrained('focus_tasks')
                ->nullOnDelete();

            // ── Snapshot de la tarea al momento de la entrada ─────────────
            $table->string('task_title_snapshot')->nullable();
            $table->string('task_icon_snapshot')->nullable();
            $table->string('task_color_snapshot')->nullable();
            $table->unsignedInteger('timer_target_snapshot_seconds')->nullable();
            $table->string('mode_snapshot', 30)->nullable(); // 'timer' | 'stopwatch'

            // ── Ventana de tiempo ─────────────────────────────────────────
            $table->timestamp('started_at_utc');
            $table->timestamp('ended_at_utc')->nullable();
            $table->unsignedInteger('elapsed_seconds');
            $table->string('stop_reason', 50)->nullable(); // e.g. 'manual', 'timer_completed'

            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────
            $table->index(['user_id', 'started_at_utc']);
            $table->index(['focus_task_id_nullable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_time_entries');
    }
};
