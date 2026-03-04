<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige 4 errores críticos detectados tras revisión:
 *
 * 1. focus_tasks.state: agrega 'idle' al CHECK (el default es 'idle' pero no estaba en la lista)
 * 2. elapsed_seconds: hace nullable en FTE e ITE (puede haber sesión activa sin tiempo cerrado)
 * 3. Constraint única parcial: solo 1 sesión activa (ended_at_utc IS NULL) por usuario
 * 4. mode_snapshot: NOT NULL (requerido para trazabilidad de auditoría)
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // ── 1. state CHECK: incluir 'idle' ──────────────────────────────────
        DB::statement('ALTER TABLE focus_tasks DROP CONSTRAINT IF EXISTS chk_focus_tasks_state');
        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_state
            CHECK (state IN ('idle', 'working', 'paused', 'stopped'))
        ");

        // ── 2. elapsed_seconds nullable ─────────────────────────────────────
        // Sesiones activas (ended_at_utc IS NULL) no tienen elapsed final aún
        DB::statement('ALTER TABLE focus_time_entries ALTER COLUMN elapsed_seconds DROP NOT NULL');
        DB::statement('ALTER TABLE idle_time_entries ALTER COLUMN elapsed_seconds DROP NOT NULL');

        // ── 3. Solo 1 sesión activa por usuario (índices únicos parciales) ──
        // PostgreSQL: unique parcial sobre filas donde ended_at_utc IS NULL
        DB::statement('
            CREATE UNIQUE INDEX idx_fte_one_active_per_user
            ON focus_time_entries (user_id)
            WHERE ended_at_utc IS NULL
        ');
        DB::statement('
            CREATE UNIQUE INDEX idx_ite_one_active_per_user
            ON idle_time_entries (user_id)
            WHERE ended_at_utc IS NULL
        ');

        // ── 4. mode_snapshot NOT NULL ───────────────────────────────────────
        // Backfill existentes (ninguno debería existir en dev, pero por seguridad)
        DB::statement("UPDATE focus_time_entries SET mode_snapshot = 'stopwatch' WHERE mode_snapshot IS NULL");
        DB::statement('ALTER TABLE focus_time_entries ALTER COLUMN mode_snapshot SET NOT NULL');

        // Actualizar CHECK para quitar la cláusula IS NULL (ya no es nullable)
        DB::statement('ALTER TABLE focus_time_entries DROP CONSTRAINT IF EXISTS chk_focus_time_entries_mode_snapshot');
        DB::statement("
            ALTER TABLE focus_time_entries
            ADD CONSTRAINT chk_focus_time_entries_mode_snapshot
            CHECK (mode_snapshot IN ('timer', 'stopwatch'))
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Revertir #4
        DB::statement('ALTER TABLE focus_time_entries DROP CONSTRAINT IF EXISTS chk_focus_time_entries_mode_snapshot');
        DB::statement("
            ALTER TABLE focus_time_entries
            ADD CONSTRAINT chk_focus_time_entries_mode_snapshot
            CHECK (mode_snapshot IS NULL OR mode_snapshot IN ('timer', 'stopwatch'))
        ");
        DB::statement('ALTER TABLE focus_time_entries ALTER COLUMN mode_snapshot DROP NOT NULL');

        // Revertir #3
        DB::statement('DROP INDEX IF EXISTS idx_fte_one_active_per_user');
        DB::statement('DROP INDEX IF EXISTS idx_ite_one_active_per_user');

        // Revertir #2
        DB::statement('ALTER TABLE focus_time_entries ALTER COLUMN elapsed_seconds SET NOT NULL');
        DB::statement('ALTER TABLE idle_time_entries ALTER COLUMN elapsed_seconds SET NOT NULL');

        // Revertir #1
        DB::statement('ALTER TABLE focus_tasks DROP CONSTRAINT IF EXISTS chk_focus_tasks_state');
        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_state
            CHECK (state IN ('working', 'paused', 'stopped'))
        ");
    }
};
