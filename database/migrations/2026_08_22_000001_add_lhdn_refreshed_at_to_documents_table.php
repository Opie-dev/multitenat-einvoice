<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('lhdn_refreshed_at')->nullable()->after('lhdn_status_at');
            $table->index(['tenant_id', 'status', 'lhdn_refreshed_at'], 'documents_refresh_sweep_index');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_refresh_sweep_index');
            $table->dropColumn('lhdn_refreshed_at');
        });
    }
};
