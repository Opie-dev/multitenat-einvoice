<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issuer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('submission_uid', 64)->nullable();
            $table->string('operation', 20);
            $table->string('environment', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('error_kind', 16)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'issuer_id', 'created_at']);
            $table->index('document_id');
            $table->index('submission_uid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_attempts');
    }
};
