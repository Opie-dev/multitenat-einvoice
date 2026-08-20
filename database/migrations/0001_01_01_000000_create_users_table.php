<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock Laravel scaffolding. The `users` table this migration originally
     * created is superseded entirely by
     * 2026_08_23_000001_create_users_table.php (spec 2026-08-20-onboarding-
     * dashboard-design.md §4.1: ULID PK, no password column, ever). `sessions`
     * is still needed — SESSION_DRIVER=database in .env.example — and
     * `password_reset_tokens` is left in place as harmless, unused stock
     * scaffolding since nothing in this app performs password resets.
     *
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // ulid, not foreignId: App\Models\User's primary key is a ULID string.
            $table->ulid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
