<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Legacy identities have no safe platform/player mapping. Never invent one.
        if (DB::table('users')->exists()) {
            throw new RuntimeException('Cannot convert non-empty users: an explicit platform/player mapping is required.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'email_verified_at', 'password', 'remember_token']);
            $table->string('name', 100)->nullable()->change();
            $table->foreignId('platform_id')->constrained('platforms')->restrictOnDelete();
            $table->string('external_user_id', 100)->collation('utf8mb4_0900_as_cs');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->unique(['platform_id', 'external_user_id']);
        });
    }

    public function down(): void
    {
        // Rollback cannot reconstruct passwords/email or discard player identities.
        if (DB::table('users')->exists()) {
            throw new RuntimeException('Cannot revert non-empty player users without a reviewed recovery plan.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['platform_id']);
            $table->dropUnique(['platform_id', 'external_user_id']);
            $table->dropColumn(['platform_id', 'external_user_id', 'status', 'last_login_at']);
            $table->string('name')->nullable(false)->change();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
        });
    }
};
