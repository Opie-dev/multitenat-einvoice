<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuer_secret_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issuer_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->longText('payload');
            $table->string('cert_fingerprint', 64)->nullable();
            $table->timestamp('replaced_at');
            $table->timestamps();
            $table->index(['issuer_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuer_secret_histories');
    }
};
