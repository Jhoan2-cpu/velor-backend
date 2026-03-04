<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Normalize potentially invalid historical data before adding CHECK constraints.
        DB::statement("UPDATE users SET locale = 'es' WHERE locale IS NULL OR locale NOT IN ('es', 'en')");
        DB::statement("UPDATE user_settings SET locale = 'es' WHERE locale IS NULL OR locale NOT IN ('es', 'en')");

        DB::statement("
            UPDATE user_settings
            SET background_music_volume_percent = CASE
                WHEN background_music_volume_percent IS NULL THEN 50
                WHEN background_music_volume_percent < 0 THEN 0
                WHEN background_music_volume_percent > 100 THEN 100
                ELSE background_music_volume_percent
            END
            WHERE background_music_volume_percent IS NULL
               OR background_music_volume_percent < 0
               OR background_music_volume_percent > 100
        ");

        DB::statement('ALTER TABLE user_settings ALTER COLUMN background_music_volume_percent SET DEFAULT 50');
        DB::statement('ALTER TABLE user_settings ALTER COLUMN background_music_volume_percent SET NOT NULL');

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'chk_users_locale'
                      AND conrelid = 'users'::regclass
                ) THEN
                    ALTER TABLE users
                    ADD CONSTRAINT chk_users_locale
                    CHECK (locale IN ('es', 'en'));
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'chk_user_settings_locale'
                      AND conrelid = 'user_settings'::regclass
                ) THEN
                    ALTER TABLE user_settings
                    ADD CONSTRAINT chk_user_settings_locale
                    CHECK (locale IN ('es', 'en'));
                END IF;
            END
            $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'chk_user_settings_music_volume_range'
                      AND conrelid = 'user_settings'::regclass
                ) THEN
                    ALTER TABLE user_settings
                    ADD CONSTRAINT chk_user_settings_music_volume_range
                    CHECK (background_music_volume_percent BETWEEN 0 AND 100);
                END IF;
            END
            $$;
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_locale');
        DB::statement('ALTER TABLE user_settings DROP CONSTRAINT IF EXISTS chk_user_settings_locale');
        DB::statement('ALTER TABLE user_settings DROP CONSTRAINT IF EXISTS chk_user_settings_music_volume_range');
    }
};
