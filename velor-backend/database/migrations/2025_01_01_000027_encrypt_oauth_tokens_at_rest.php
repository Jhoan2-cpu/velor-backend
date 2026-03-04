<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

return new class extends Migration {
    public function up(): void
    {
        $this->transformTokens(fn (string $value): string => $this->ensureEncrypted($value));
    }

    public function down(): void
    {
        $this->transformTokens(fn (string $value): string => $this->ensureDecrypted($value));
    }

    private function transformTokens(callable $transform): void
    {
        DB::table('user_oauth_identities')
            ->select(['id', 'access_token', 'refresh_token'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($transform): void {
                foreach ($rows as $row) {
                    $updates = [];

                    if ($row->access_token !== null) {
                        $updates['access_token'] = $transform((string) $row->access_token);
                    }

                    if ($row->refresh_token !== null) {
                        $updates['refresh_token'] = $transform((string) $row->refresh_token);
                    }

                    if ($updates !== []) {
                        DB::table('user_oauth_identities')
                            ->where('id', $row->id)
                            ->update($updates);
                    }
                }
            });
    }

    private function ensureEncrypted(string $value): string
    {
        if ($this->looksEncrypted($value)) {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    private function ensureDecrypted(string $value): string
    {
        if (!$this->looksEncrypted($value)) {
            return $value;
        }

        return Crypt::decryptString($value);
    }

    private function looksEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
