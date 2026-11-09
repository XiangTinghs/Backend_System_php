<?php

use App\Support\RsaPublicKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Fail closed, preserving all rows. Existing duplicates require separately reviewed remediation.
        DB::table('platform_jwt_keys')->select(['id', 'public_key', 'fingerprint'])->orderBy('id')
            ->chunkById(100, function ($keys): void {
                foreach ($keys as $key) {
                    if (! hash_equals(RsaPublicKey::fromPem($key->public_key)->fingerprint, $key->fingerprint)) {
                        throw new RuntimeException('JWT key fingerprints must be canonical before adding uniqueness.');
                    }
                }
            });
        if (DB::table('platform_jwt_keys')->select('fingerprint')->groupBy('fingerprint')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate JWT public keys require reviewed remediation; no rows were changed.');
        }
        Schema::table('platform_jwt_keys', function (Blueprint $table) {
            $table->unique('fingerprint', 'platform_jwt_keys_fingerprint_unique');
        });
    }

    public function down(): void
    {
        Schema::table('platform_jwt_keys', function (Blueprint $table) {
            $table->dropUnique('platform_jwt_keys_fingerprint_unique');
        });
    }
};
