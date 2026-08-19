<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('classification_code', 3);
            $table->string('description', 300);
            $table->decimal('quantity', 18, 4);
            $table->string('unit_code', 10);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('discount_rate', 8, 4)->nullable();
            $table->string('tax_type', 2);
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->string('tax_exemption_reason', 300)->nullable();
            $table->decimal('subtotal', 18, 2);
            $table->decimal('total', 18, 2);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'position']);
            $table->index(['tenant_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lines');
    }
};
