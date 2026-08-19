<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->index();
            $table->string('actor_type', 20)->nullable();
            $table->string('actor_id', 26)->nullable();
            $table->string('actor_name', 100)->nullable();
            $table->string('action', 60)->index();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 26)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
