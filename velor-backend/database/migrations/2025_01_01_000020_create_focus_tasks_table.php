<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('focus_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title', 255);
            $table->text('description')->nullable();

            // ── UI personalización ──────────────────────────────────────────
            $table->string('color', 20)->nullable();   // ej. "#FF5733"
            $table->string('icon', 50)->nullable();    // identificador del icono

            // ── Estado y tiempo acumulado ───────────────────────────────────
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('total_focused_seconds')->default(0); // cache desnormalizado
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // ── Índices ─────────────────────────────────────────────────────
            $table->index(['user_id', 'is_archived']);
            $table->index(['user_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_tasks');
    }
};
