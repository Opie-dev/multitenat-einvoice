<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuer_secrets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issuer_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('lhdn_client_id')->nullable();
            $table->text('lhdn_client_secret')->nullable();
            $table->longText('signing_certificate')->nullable();
            $table->longText('signing_key')->nullable();
            $table->string('cert_subject', 500)->nullable();
            $table->string('cert_serial', 100)->nullable();
            $table->string('cert_fingerprint', 64)->nullable();
            $table->timestamp('cert_not_before')->nullable();
            $table->timestamp('cert_not_after')->nullable();
            $table->timestamp('credentials_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuer_secrets');
    }
};
