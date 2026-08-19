<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tin', 20)->nullable();
            $table->string('id_type', 10)->nullable();
            $table->string('id_number', 30)->nullable();
            $table->string('sst_number', 40)->nullable();
            $table->string('email', 320)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address_line1', 150)->nullable();
            $table->string('address_line2', 150)->nullable();
            $table->string('address_line3', 150)->nullable();
            $table->string('postcode', 10)->nullable();
            $table->string('city', 50)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('country_code', 3)->default('MYS');
            $table->boolean('general_public')->default(false);
            $table->timestamp('tin_validated_at')->nullable();
            $table->json('tin_validation_result')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'tin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyers');
    }
};
