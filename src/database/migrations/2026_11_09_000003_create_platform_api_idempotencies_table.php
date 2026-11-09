<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('platform_api_idempotencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained('platforms')->restrictOnDelete();
            $table->string('endpoint', 80)->collation('utf8mb4_0900_as_cs');
            $table->char('idempotency_key', 36)->collation('utf8mb4_0900_as_cs');
            $table->char('request_hash', 64);
            $table->string('state', 16);
            $table->foreignId('player_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('response_status')->nullable();
            // Preserve the exact emitted JSON, including safe string representations of large IDs.
            $table->text('response_body')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->unique(['platform_id', 'endpoint', 'idempotency_key'], 'platform_api_idempotencies_scope_unique');
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_api_idempotencies');
    }
};
