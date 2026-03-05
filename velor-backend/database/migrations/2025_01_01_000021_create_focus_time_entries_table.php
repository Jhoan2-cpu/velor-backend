<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('focus_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('focus_task_id_nullable')
                ->nullable()
                ->constrained('focus_tasks')
                ->nullOnDelete();

            // Task snapshot at session start
            $table->string('task_title_snapshot')->nullable();
            $table->string('task_icon_snapshot')->nullable();
            $table->string('task_color_snapshot')->nullable();
            $table->integer('timer_target_snapshot_seconds')->nullable();
            $table->string('mode_snapshot', 30); // timer | stopwatch

            // Session window
            $table->timestamp('started_at_utc');
            $table->timestamp('ended_at_utc')->nullable();
            $table->integer('elapsed_seconds')->nullable();
            $table->string('stop_reason', 50)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'started_at_utc']);
            $table->index(['focus_task_id_nullable']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            ALTER TABLE focus_time_entries
            ADD CONSTRAINT chk_focus_time_entries_mode_snapshot
            CHECK (mode_snapshot IN ('timer', 'stopwatch'))
        ");

        DB::statement("
            ALTER TABLE focus_time_entries
            ADD CONSTRAINT chk_focus_time_entries_stop_reason
            CHECK (
                stop_reason IS NULL
                OR stop_reason IN ('manual', 'timer_completed', 'task_switch', 'session_end', 'idle_detected')
            )
        ");

        DB::statement("
            ALTER TABLE focus_time_entries
            ADD CONSTRAINT chk_fte_timer_target_snapshot_seconds_non_negative
            CHECK (timer_target_snapshot_seconds IS NULL OR timer_target_snapshot_seconds >= 0)
        ");

        DB::statement("
            ALTER TABLE focus_time_entries
            ADD CONSTRAINT chk_fte_elapsed_nullness_matches_end
            CHECK (
                (ended_at_utc IS NULL AND elapsed_seconds IS NULL)
                OR
                (ended_at_utc IS NOT NULL AND elapsed_seconds IS NOT NULL)
            )
        ");

        DB::statement("
            ALTER TABLE focus_time_entries
            ADD CONSTRAINT chk_fte_elapsed_non_negative_when_closed
            CHECK (ended_at_utc IS NULL OR elapsed_seconds >= 0)
        ");

        DB::statement("
            ALTER TABLE focus_time_entries
            ADD CONSTRAINT chk_fte_end_after_start
            CHECK (ended_at_utc IS NULL OR ended_at_utc >= started_at_utc)
        ");

        DB::statement('
            CREATE UNIQUE INDEX idx_fte_one_active_per_user
            ON focus_time_entries (user_id)
            WHERE ended_at_utc IS NULL
        ');

        DB::statement('
            CREATE INDEX idx_focus_time_entries_user_started
            ON focus_time_entries (user_id, started_at_utc DESC)
        ');

        DB::statement('
            CREATE INDEX idx_focus_time_entries_user_ended_closed
            ON focus_time_entries (user_id, ended_at_utc DESC)
            WHERE ended_at_utc IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_time_entries');
    }
};

