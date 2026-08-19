<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 16);
            $table->string('key_hash', 64)->unique();
            $table->string('environment', 16);
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
