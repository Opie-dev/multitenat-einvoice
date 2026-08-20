<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->text('secret');
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->string('environment', 16);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'environment', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
