<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Añade constraints de integridad que el Schema Builder de Laravel
 * no soporta directamente: CHECK (enum), unique en user_settings.user_id,
 * corrección del DEFAULT de version, e índices especializados.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── 1. USER_SETTINGS: user_id UNIQUE (relación 1:1 real) ──────────
        Schema::table('user_settings', function (Blueprint $table) {
            $table->unique('user_id', 'user_settings_user_id_unique');
        });

        // ── 2. FOCUS_TASKS: version DEFAULT 1 (nativo en PostgreSQL) ──────
        DB::statement('ALTER TABLE focus_tasks ALTER COLUMN version SET DEFAULT 1');
        DB::statement('UPDATE focus_tasks SET version = 1 WHERE version = 0');

        // ── 3. CHECK constraints (enums) ───────────────────────────────────

        // focus_tasks.active_mode
        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_active_mode
            CHECK (active_mode IN ('timer', 'stopwatch'))
        ");

        // focus_tasks.state
        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_state
            CHECK (state IN ('working', 'paused', 'stopped'))
        ");

        // focus_time_entries.mode_snapshot
        DB::statement("
            ALTER TABLE focus_time_entries
            ADD CONSTRAINT chk_focus_time_entries_mode_snapshot
            CHECK (mode_snapshot IS NULL OR mode_snapshot IN ('timer', 'stopwatch'))
        ");

        // focus_time_entries.stop_reason
        DB::statement("
            ALTER TABLE focus_time_entries
            ADD CONSTRAINT chk_focus_time_entries_stop_reason
            CHECK (stop_reason IS NULL OR stop_reason IN (
                'manual', 'timer_completed', 'task_switch', 'session_end', 'idle_detected'
            ))
        ");

        // idle_time_entries.reason
        DB::statement("
            ALTER TABLE idle_time_entries
            ADD CONSTRAINT chk_idle_time_entries_reason
            CHECK (reason IS NULL OR reason IN (
                'user_idle', 'break', 'task_switch', 'session_end'
            ))
        ");

        // ── 4. Índices recomendados ────────────────────────────────────────

        // focus_tasks(user_id, updated_at) — para sync delta del frontend
        DB::statement('
            CREATE INDEX idx_focus_tasks_user_updated
            ON focus_tasks (user_id, updated_at DESC)
        ');

        // focus_time_entries(user_id, started_at_utc DESC) — paginación cronológica
        DB::statement('
            CREATE INDEX idx_focus_time_entries_user_started
            ON focus_time_entries (user_id, started_at_utc DESC)
        ');

        // idle_time_entries(user_id, started_at_utc DESC)
        DB::statement('
            CREATE INDEX idx_idle_time_entries_user_started
            ON idle_time_entries (user_id, started_at_utc DESC)
        ');
    }

    public function down(): void
    {
        // Revertir índices
        DB::statement('DROP INDEX IF EXISTS idx_focus_tasks_user_updated');
        DB::statement('DROP INDEX IF EXISTS idx_focus_time_entries_user_started');
        DB::statement('DROP INDEX IF EXISTS idx_idle_time_entries_user_started');

        // Revertir CHECK constraints
        DB::statement('ALTER TABLE focus_tasks DROP CONSTRAINT IF EXISTS chk_focus_tasks_active_mode');
        DB::statement('ALTER TABLE focus_tasks DROP CONSTRAINT IF EXISTS chk_focus_tasks_state');
        DB::statement('ALTER TABLE focus_time_entries DROP CONSTRAINT IF EXISTS chk_focus_time_entries_mode_snapshot');
        DB::statement('ALTER TABLE focus_time_entries DROP CONSTRAINT IF EXISTS chk_focus_time_entries_stop_reason');
        DB::statement('ALTER TABLE idle_time_entries DROP CONSTRAINT IF EXISTS chk_idle_time_entries_reason');

        // Revertir version default
        DB::statement('ALTER TABLE focus_tasks ALTER COLUMN version SET DEFAULT 0');

        // Revertir UNIQUE
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropUnique('user_settings_user_id_unique');
        });
    }
};
