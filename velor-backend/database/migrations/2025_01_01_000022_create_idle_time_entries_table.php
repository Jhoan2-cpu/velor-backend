<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('idle_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('started_at_utc');
            $table->timestamp('ended_at_utc')->nullable();
            $table->integer('elapsed_seconds')->nullable();
            $table->string('reason', 100)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'started_at_utc']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            ALTER TABLE idle_time_entries
            ADD CONSTRAINT chk_idle_time_entries_reason
            CHECK (
                reason IS NULL
                OR reason IN ('user_idle', 'break', 'task_switch', 'session_end')
            )
        ");

        DB::statement("
            ALTER TABLE idle_time_entries
            ADD CONSTRAINT chk_ite_elapsed_nullness_matches_end
            CHECK (
                (ended_at_utc IS NULL AND elapsed_seconds IS NULL)
                OR
                (ended_at_utc IS NOT NULL AND elapsed_seconds IS NOT NULL)
            )
        ");

        DB::statement("
            ALTER TABLE idle_time_entries
            ADD CONSTRAINT chk_ite_elapsed_non_negative_when_closed
            CHECK (ended_at_utc IS NULL OR elapsed_seconds >= 0)
        ");

        DB::statement("
            ALTER TABLE idle_time_entries
            ADD CONSTRAINT chk_ite_end_after_start
            CHECK (ended_at_utc IS NULL OR ended_at_utc >= started_at_utc)
        ");

        DB::statement('
            CREATE UNIQUE INDEX idx_ite_one_active_per_user
            ON idle_time_entries (user_id)
            WHERE ended_at_utc IS NULL
        ');

        DB::statement('
            CREATE INDEX idx_idle_time_entries_user_started
            ON idle_time_entries (user_id, started_at_utc DESC)
        ');

        DB::statement('
            CREATE INDEX idx_idle_time_entries_user_ended_closed
            ON idle_time_entries (user_id, ended_at_utc DESC)
            WHERE ended_at_utc IS NOT NULL
        ');

        // Cross-table active-session invariant (FTE <-> ITE)
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

        DB::statement("
            CREATE TRIGGER trg_fte_prevent_cross_active
            BEFORE INSERT OR UPDATE OF user_id, ended_at_utc
            ON focus_time_entries
            FOR EACH ROW
            EXECUTE FUNCTION enforce_no_cross_active_fte()
        ");

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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS enforce_no_cross_active_fte() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS enforce_no_cross_active_ite() CASCADE');
        }

        Schema::dropIfExists('idle_time_entries');
    }
};

