<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Placeholder table backing the placeholder `App\Models\IssuerSecret` model
 * (Task 6). Task 7 owns the real credential/certificate storage design and
 * will extend or replace this migration accordingly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuer_secrets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('issuer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cert_subject')->nullable();
            $table->string('cert_serial')->nullable();
            $table->string('cert_fingerprint')->nullable();
            $table->timestamp('cert_not_before')->nullable();
            $table->timestamp('cert_not_after')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuer_secrets');
    }
};
