<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('platform_jwt_issuers', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_id')->primary();
            $table->string('issuer', 100)->collation('utf8mb4_0900_as_cs')->unique();
            $table->timestamps();
            $table->foreign('platform_id')->references('id')->on('platforms')->restrictOnDelete();
        });

        Schema::create('platform_jwt_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->string('kid', 100)->collation('utf8mb4_0900_as_cs');
            $table->text('public_key');
            $table->char('fingerprint', 64);
            $table->boolean('is_active')->default(true);
            $table->dateTime('valid_from');
            $table->dateTime('valid_until');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['platform_id', 'kid']);
            $table->foreign('platform_id')->references('platform_id')->on('platform_jwt_issuers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_jwt_keys');
        Schema::dropIfExists('platform_jwt_issuers');
    }
};
