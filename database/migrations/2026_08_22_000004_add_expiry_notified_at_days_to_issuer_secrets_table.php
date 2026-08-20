<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuer_secrets', function (Blueprint $table) {
            // Last expiry threshold (30, 7 or 1 day(s)) a certificate.expiring notice
            // was sent for; reset to null whenever a new certificate is uploaded.
            $table->unsignedSmallInteger('expiry_notified_at_days')->nullable()->after('cert_not_after');
        });
    }

    public function down(): void
    {
        Schema::table('issuer_secrets', function (Blueprint $table) {
            $table->dropColumn('expiry_notified_at_days');
        });
    }
};
