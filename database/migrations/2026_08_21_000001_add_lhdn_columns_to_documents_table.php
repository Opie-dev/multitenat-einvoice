<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('lhdn_internal_id', 50)->nullable()->after('payload_hash');
            $table->longText('ubl_json')->nullable()->after('lhdn_internal_id');
            $table->char('signed_payload_hash', 64)->nullable()->after('ubl_json');
            $table->string('pdf_path')->nullable()->after('signed_payload_hash');
            $table->unsignedSmallInteger('submission_attempts_count')->default(0)->after('pdf_path');
            $table->json('last_submission_error')->nullable()->after('submission_attempts_count');
            $table->timestamp('next_submission_at')->nullable()->after('last_submission_error');
            $table->unique(['tenant_id', 'lhdn_internal_id'], 'documents_lhdn_internal_id_unique');
            $table->index(['tenant_id', 'lhdn_submission_uid'], 'documents_submission_uid_index');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_lhdn_internal_id_unique');
            $table->dropIndex('documents_submission_uid_index');
            $table->dropColumn(['lhdn_internal_id', 'ubl_json', 'signed_payload_hash', 'pdf_path', 'submission_attempts_count', 'last_submission_error', 'next_submission_at']);
        });
    }
};
