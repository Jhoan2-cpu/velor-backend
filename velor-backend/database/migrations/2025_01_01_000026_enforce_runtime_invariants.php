<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Normalize historical values before adding stricter constraints.
        DB::statement('UPDATE focus_tasks SET timer_initial_seconds = 0 WHERE timer_initial_seconds < 0');
        DB::statement('UPDATE focus_tasks SET timer_remaining_seconds = 0 WHERE timer_remaining_seconds < 0');
        DB::statement('UPDATE focus_tasks SET stopwatch_elapsed_seconds = 0 WHERE stopwatch_elapsed_seconds < 0');
        DB::statement('UPDATE focus_tasks SET total_tracked_seconds = 0 WHERE total_tracked_seconds < 0');
        DB::statement('UPDATE focus_tasks SET version = 1 WHERE version < 1');

        DB::statement('UPDATE focus_time_entries SET timer_target_snapshot_seconds = 0 WHERE timer_target_snapshot_seconds < 0');
        DB::statement('UPDATE focus_time_entries SET ended_at_utc = started_at_utc WHERE ended_at_utc IS NOT NULL AND ended_at_utc < started_at_utc');
        DB::statement('UPDATE focus_time_entries SET elapsed_seconds = NULL WHERE ended_at_utc IS NULL');
        DB::statement("
            UPDATE focus_time_entries
            SET elapsed_seconds = GREATEST(
                COALESCE(elapsed_seconds, EXTRACT(EPOCH FROM (ended_at_utc - started_at_utc))::int),
                0
            )
            WHERE ended_at_utc IS NOT NULL
        ");

        DB::statement('UPDATE idle_time_entries SET ended_at_utc = started_at_utc WHERE ended_at_utc IS NOT NULL AND ended_at_utc < started_at_utc');
        DB::statement('UPDATE idle_time_entries SET elapsed_seconds = NULL WHERE ended_at_utc IS NULL');
        DB::statement("
            UPDATE idle_time_entries
            SET elapsed_seconds = GREATEST(
                COALESCE(elapsed_seconds, EXTRACT(EPOCH FROM (ended_at_utc - started_at_utc))::int),
                0
            )
            WHERE ended_at_utc IS NOT NULL
        ");

        // Non-negative and min-value constraints for domain counters.
        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_focus_tasks_timer_initial_seconds_non_negative'
                      AND conrelid = 'focus_tasks'::regclass
                ) THEN
                    ALTER TABLE focus_tasks
                    ADD CONSTRAINT chk_focus_tasks_timer_initial_seconds_non_negative
                    CHECK (timer_initial_seconds IS NULL OR timer_initial_seconds >= 0);
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_focus_tasks_timer_remaining_seconds_non_negative'
                      AND conrelid = 'focus_tasks'::regclass
                ) THEN
                    ALTER TABLE focus_tasks
                    ADD CONSTRAINT chk_focus_tasks_timer_remaining_seconds_non_negative
                    CHECK (timer_remaining_seconds IS NULL OR timer_remaining_seconds >= 0);
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_focus_tasks_stopwatch_elapsed_seconds_non_negative'
                      AND conrelid = 'focus_tasks'::regclass
                ) THEN
                    ALTER TABLE focus_tasks
                    ADD CONSTRAINT chk_focus_tasks_stopwatch_elapsed_seconds_non_negative
                    CHECK (stopwatch_elapsed_seconds >= 0);
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_focus_tasks_total_tracked_seconds_non_negative'
                      AND conrelid = 'focus_tasks'::regclass
                ) THEN
                    ALTER TABLE focus_tasks
                    ADD CONSTRAINT chk_focus_tasks_total_tracked_seconds_non_negative
                    CHECK (total_tracked_seconds >= 0);
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_focus_tasks_version_min_1'
                      AND conrelid = 'focus_tasks'::regclass
                ) THEN
                    ALTER TABLE focus_tasks
                    ADD CONSTRAINT chk_focus_tasks_version_min_1
                    CHECK (version >= 1);
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_fte_timer_target_snapshot_seconds_non_negative'
                      AND conrelid = 'focus_time_entries'::regclass
                ) THEN
                    ALTER TABLE focus_time_entries
                    ADD CONSTRAINT chk_fte_timer_target_snapshot_seconds_non_negative
                    CHECK (timer_target_snapshot_seconds IS NULL OR timer_target_snapshot_seconds >= 0);
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_fte_elapsed_nullness_matches_end'
                      AND conrelid = 'focus_time_entries'::regclass
                ) THEN
                    ALTER TABLE focus_time_entries
                    ADD CONSTRAINT chk_fte_elapsed_nullness_matches_end
                    CHECK (
                        (ended_at_utc IS NULL AND elapsed_seconds IS NULL)
                        OR
                        (ended_at_utc IS NOT NULL AND elapsed_seconds IS NOT NULL)
                    );
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_fte_elapsed_non_negative_when_closed'
                      AND conrelid = 'focus_time_entries'::regclass
                ) THEN
                    ALTER TABLE focus_time_entries
                    ADD CONSTRAINT chk_fte_elapsed_non_negative_when_closed
                    CHECK (ended_at_utc IS NULL OR elapsed_seconds >= 0);
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_fte_end_after_start'
                      AND conrelid = 'focus_time_entries'::regclass
                ) THEN
                    ALTER TABLE focus_time_entries
                    ADD CONSTRAINT chk_fte_end_after_start
                    CHECK (ended_at_utc IS NULL OR ended_at_utc >= started_at_utc);
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_ite_elapsed_nullness_matches_end'
                      AND conrelid = 'idle_time_entries'::regclass
                ) THEN
                    ALTER TABLE idle_time_entries
                    ADD CONSTRAINT chk_ite_elapsed_nullness_matches_end
                    CHECK (
                        (ended_at_utc IS NULL AND elapsed_seconds IS NULL)
                        OR
                        (ended_at_utc IS NOT NULL AND elapsed_seconds IS NOT NULL)
                    );
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_ite_elapsed_non_negative_when_closed'
                      AND conrelid = 'idle_time_entries'::regclass
                ) THEN
                    ALTER TABLE idle_time_entries
                    ADD CONSTRAINT chk_ite_elapsed_non_negative_when_closed
                    CHECK (ended_at_utc IS NULL OR elapsed_seconds >= 0);
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_ite_end_after_start'
                      AND conrelid = 'idle_time_entries'::regclass
                ) THEN
                    ALTER TABLE idle_time_entries
                    ADD CONSTRAINT chk_ite_end_after_start
                    CHECK (ended_at_utc IS NULL OR ended_at_utc >= started_at_utc);
                END IF;
            END
            $$;
        ");

        // Cross-table active session invariant: no active row in both tables at the same time for the same user.
        DB::statement("
            CREATE OR REPLACE FUNCTION enforce_no_cross_active_fte()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.ended_at_utc IS NULL THEN
                    IF EXISTS (
                        SELECT 1
                        FROM idle_time_entries ite
                        WHERE ite.user_id = NEW.user_id
                          AND ite.ended_at_utc IS NULL
                    ) THEN
                        RAISE EXCEPTION 'Active idle session already exists for user %', NEW.user_id
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION enforce_no_cross_active_ite()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.ended_at_utc IS NULL THEN
                    IF EXISTS (
                        SELECT 1
                        FROM focus_time_entries fte
                        WHERE fte.user_id = NEW.user_id
                          AND fte.ended_at_utc IS NULL
                    ) THEN
                        RAISE EXCEPTION 'Active focus session already exists for user %', NEW.user_id
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_fte_prevent_cross_active ON focus_time_entries');
        DB::statement("
            CREATE TRIGGER trg_fte_prevent_cross_active
            BEFORE INSERT OR UPDATE OF user_id, ended_at_utc
            ON focus_time_entries
            FOR EACH ROW
            EXECUTE FUNCTION enforce_no_cross_active_fte()
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_ite_prevent_cross_active ON idle_time_entries');
        DB::statement("
            CREATE TRIGGER trg_ite_prevent_cross_active
            BEFORE INSERT OR UPDATE OF user_id, ended_at_utc
            ON idle_time_entries
            FOR EACH ROW
            EXECUTE FUNCTION enforce_no_cross_active_ite()
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_fte_prevent_cross_active ON focus_time_entries');
        DB::statement('DROP TRIGGER IF EXISTS trg_ite_prevent_cross_active ON idle_time_entries');

        DB::statement('DROP FUNCTION IF EXISTS enforce_no_cross_active_fte()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_no_cross_active_ite()');

        DB::statement('ALTER TABLE focus_tasks DROP CONSTRAINT IF EXISTS chk_focus_tasks_timer_initial_seconds_non_negative');
        DB::statement('ALTER TABLE focus_tasks DROP CONSTRAINT IF EXISTS chk_focus_tasks_timer_remaining_seconds_non_negative');
        DB::statement('ALTER TABLE focus_tasks DROP CONSTRAINT IF EXISTS chk_focus_tasks_stopwatch_elapsed_seconds_non_negative');
        DB::statement('ALTER TABLE focus_tasks DROP CONSTRAINT IF EXISTS chk_focus_tasks_total_tracked_seconds_non_negative');
        DB::statement('ALTER TABLE focus_tasks DROP CONSTRAINT IF EXISTS chk_focus_tasks_version_min_1');

        DB::statement('ALTER TABLE focus_time_entries DROP CONSTRAINT IF EXISTS chk_fte_timer_target_snapshot_seconds_non_negative');
        DB::statement('ALTER TABLE focus_time_entries DROP CONSTRAINT IF EXISTS chk_fte_elapsed_nullness_matches_end');
        DB::statement('ALTER TABLE focus_time_entries DROP CONSTRAINT IF EXISTS chk_fte_elapsed_non_negative_when_closed');
        DB::statement('ALTER TABLE focus_time_entries DROP CONSTRAINT IF EXISTS chk_fte_end_after_start');

        DB::statement('ALTER TABLE idle_time_entries DROP CONSTRAINT IF EXISTS chk_ite_elapsed_nullness_matches_end');
        DB::statement('ALTER TABLE idle_time_entries DROP CONSTRAINT IF EXISTS chk_ite_elapsed_non_negative_when_closed');
        DB::statement('ALTER TABLE idle_time_entries DROP CONSTRAINT IF EXISTS chk_ite_end_after_start');
    }
};
