<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tin', 20);
            $table->string('id_type', 10);
            $table->string('id_number', 30);
            $table->string('sst_number', 40)->nullable();
            $table->string('tourism_tax_number', 40)->nullable();
            $table->string('msic_code', 5);
            $table->string('business_activity_description', 300);
            $table->string('address_line1', 150);
            $table->string('address_line2', 150)->nullable();
            $table->string('address_line3', 150)->nullable();
            $table->string('postcode', 10);
            $table->string('city', 50);
            $table->string('state_code', 2);
            $table->string('country_code', 3)->default('MYS');
            $table->string('email', 320);
            $table->string('phone', 20);
            $table->string('environment', 16);
            $table->string('lhdn_mode', 20);
            $table->boolean('einvoice_required')->default(true);
            $table->boolean('consolidation_enabled')->default(false);
            $table->string('status', 20)->default('draft');
            $table->timestamp('tin_verified_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('certificate_valid_until')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'tin', 'environment']);
            $table->index(['tenant_id', 'environment', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuers');
    }
};
