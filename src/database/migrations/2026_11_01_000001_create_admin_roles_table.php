<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_id');
            $table->string('role', 50)->collation('utf8mb4_unicode_ci');

            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->unique(['admin_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_roles');
    }
};
