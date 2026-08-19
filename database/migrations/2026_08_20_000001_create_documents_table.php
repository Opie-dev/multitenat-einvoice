<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issuer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('buyer_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('group_id')->nullable();
            $table->string('environment', 16);
            $table->string('type', 32);
            $table->string('status', 32);
            $table->string('held_reason', 32)->nullable();
            $table->json('buyer_snapshot');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->date('issue_date');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('total_excluding_tax', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total_including_tax', 18, 2)->default(0);
            $table->decimal('total_payable', 18, 2)->default(0);
            $table->boolean('consolidate')->default(false);
            $table->string('source_system', 50);
            $table->string('source_ref', 191);
            $table->ulid('original_document_id')->nullable();
            $table->string('original_lhdn_uuid', 64)->nullable();
            $table->json('payment')->nullable();
            $table->json('metadata')->nullable();
            $table->char('payload_hash', 64);
            $table->string('lhdn_uuid', 64)->nullable();
            $table->string('lhdn_long_id', 128)->nullable();
            $table->string('lhdn_submission_uid', 64)->nullable();
            $table->json('lhdn_errors')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('lhdn_status_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->ulid('consolidated_into_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'source_system', 'source_ref', 'type'], 'documents_natural_key_unique');
            $table->index(['tenant_id', 'environment', 'status']);
            $table->index(['tenant_id', 'issuer_id']);
            $table->index(['tenant_id', 'group_id']);
            $table->index(['tenant_id', 'lhdn_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
