<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('focus_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // ── Identidad de la tarea ──────────────────────────────────────
            $table->string('name');
            $table->string('icon_tag')->nullable();
            $table->string('color_tag')->nullable();
            $table->string('alarm_time_local')->nullable(); // e.g. "08:30"

            // ── Timer ──────────────────────────────────────────────────────
            $table->unsignedInteger('timer_initial_seconds')->nullable();
            $table->unsignedInteger('timer_remaining_seconds')->nullable();
            $table->timestamp('timer_started_at_utc')->nullable();
            $table->timestamp('timer_ended_at_utc')->nullable();

            // ── Stopwatch ─────────────────────────────────────────────────
            $table->unsignedInteger('stopwatch_elapsed_seconds')->default(0);
            $table->timestamp('stopwatch_started_at_utc')->nullable();
            $table->timestamp('stopwatch_ended_at_utc')->nullable();

            // ── Totales y estado ──────────────────────────────────────────
            $table->unsignedInteger('total_tracked_seconds')->default(0);
            $table->string('active_mode', 30)->default('stopwatch'); // 'timer' | 'stopwatch'
            $table->string('state', 30)->default('idle');            // 'idle' | 'running' | 'paused'
            $table->unsignedInteger('version')->default(0);          // optimistic locking

            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────
            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_tasks');
    }
};
