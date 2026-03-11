<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_focus_tasks_one_active_runtime_per_user
            ON focus_tasks (user_id)
            WHERE state IN ('working', 'paused')
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_focus_tasks_one_active_runtime_per_user');
    }
};
