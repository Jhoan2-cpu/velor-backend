<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Keep only strict HH:MM values before enforcing the CHECK.
        DB::statement("
            UPDATE focus_tasks
            SET alarm_time_local = NULL
            WHERE alarm_time_local IS NOT NULL
              AND alarm_time_local !~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'chk_focus_tasks_alarm_time_local_hhmm'
                      AND conrelid = 'focus_tasks'::regclass
                ) THEN
                    ALTER TABLE focus_tasks
                    ADD CONSTRAINT chk_focus_tasks_alarm_time_local_hhmm
                    CHECK (
                        alarm_time_local IS NULL
                        OR alarm_time_local ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'
                    );
                END IF;
            END
            $$;
        ");

        // Support heavy historical queries for closed sessions.
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_focus_time_entries_user_ended_closed
            ON focus_time_entries (user_id, ended_at_utc DESC)
            WHERE ended_at_utc IS NOT NULL
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_idle_time_entries_user_ended_closed
            ON idle_time_entries (user_id, ended_at_utc DESC)
            WHERE ended_at_utc IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_focus_time_entries_user_ended_closed');
        DB::statement('DROP INDEX IF EXISTS idx_idle_time_entries_user_ended_closed');
        DB::statement('ALTER TABLE focus_tasks DROP CONSTRAINT IF EXISTS chk_focus_tasks_alarm_time_local_hhmm');
    }
};
