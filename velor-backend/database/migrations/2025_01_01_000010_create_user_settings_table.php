<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('locale', 10)->default('es');
            $table->string('time_zone_name')->default('America/Lima');
            $table->boolean('ui_sounds_enabled')->default(true);
            $table->boolean('background_music_enabled')->default(false);
            $table->smallInteger('background_music_volume_percent')->default(50);
            $table->boolean('confirm_task_switch_enabled')->default(true);
            $table->boolean('sign_out_confirmation_enabled')->default(true);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE user_settings
                ADD CONSTRAINT chk_user_settings_locale
                CHECK (locale IN ('es', 'en'))
            ");

            DB::statement("
                ALTER TABLE user_settings
                ADD CONSTRAINT chk_user_settings_music_volume_range
                CHECK (background_music_volume_percent BETWEEN 0 AND 100)
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
