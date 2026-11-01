<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('roles_permissions', function (Blueprint $table) {
            $table->string('role', 50)->collation('utf8mb4_unicode_ci');
            $table->string('permission', 100);

            $table->unique(['role', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles_permissions');
    }
};
