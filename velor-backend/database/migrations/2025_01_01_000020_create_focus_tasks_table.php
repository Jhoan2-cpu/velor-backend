<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('focus_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Task identity
            $table->string('name');
            $table->string('icon_tag')->nullable();
            $table->string('color_tag')->nullable();
            $table->string('alarm_time_local')->nullable();

            // Timer
            $table->integer('timer_initial_seconds')->nullable();
            $table->integer('timer_remaining_seconds')->nullable();
            $table->timestamp('timer_started_at_utc')->nullable();
            $table->timestamp('timer_ended_at_utc')->nullable();

            // Stopwatch
            $table->integer('stopwatch_elapsed_seconds')->default(0);
            $table->timestamp('stopwatch_started_at_utc')->nullable();
            $table->timestamp('stopwatch_ended_at_utc')->nullable();

            // Aggregates and runtime state
            $table->integer('total_tracked_seconds')->default(0);
            $table->string('active_mode', 30)->default('stopwatch'); // timer | stopwatch
            $table->string('state', 30)->default('idle');             // idle | working | paused | stopped
            $table->integer('version')->default(1);                   // optimistic locking

            $table->timestamps();

            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_active_mode
            CHECK (active_mode IN ('timer', 'stopwatch'))
        ");

        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_state
            CHECK (state IN ('idle', 'working', 'paused', 'stopped'))
        ");

        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_timer_initial_seconds_non_negative
            CHECK (timer_initial_seconds IS NULL OR timer_initial_seconds >= 0)
        ");

        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_timer_remaining_seconds_non_negative
            CHECK (timer_remaining_seconds IS NULL OR timer_remaining_seconds >= 0)
        ");

        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_stopwatch_elapsed_seconds_non_negative
            CHECK (stopwatch_elapsed_seconds >= 0)
        ");

        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_total_tracked_seconds_non_negative
            CHECK (total_tracked_seconds >= 0)
        ");

        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_version_min_1
            CHECK (version >= 1)
        ");

        DB::statement("
            ALTER TABLE focus_tasks
            ADD CONSTRAINT chk_focus_tasks_alarm_time_local_hhmm
            CHECK (
                alarm_time_local IS NULL
                OR alarm_time_local ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'
            )
        ");

        DB::statement('
            CREATE INDEX idx_focus_tasks_user_updated
            ON focus_tasks (user_id, updated_at DESC)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_tasks');
    }
};

