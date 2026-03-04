<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10)->default('es');
            $table->string('time_zone_name')->default('America/Lima');
            $table->boolean('ui_sounds_enabled')->default(true);
            $table->boolean('background_music_enabled')->default(false);
            $table->unsignedSmallInteger('background_music_volume_percent')->default(50);
            $table->boolean('confirm_task_switch_enabled')->default(true);
            $table->boolean('sign_out_confirmation_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
