<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's schema builder has no fluent API for table-level CHECK
     * constraints. SQLite additionally has no `ALTER TABLE ... ADD
     * CONSTRAINT` (its ALTER TABLE only supports rename/add-column/
     * drop-column), so the CHECK must be present in the CREATE TABLE
     * statement itself on that driver. MySQL supports adding it after the
     * fact. Both branches produce the same columns/indexes/FKs; only the
     * CHECK wiring differs.
     *
     * `issuer_id`'s FK deliberately has no ON DELETE action (defaults to
     * RESTRICT): MySQL 8 refuses (error 3823) to CHECK a column that also
     * carries a referential action like SET NULL/CASCADE, and semantically
     * SET NULL would violate this very CHECK anyway for a vendor row.
     * Verified directly against MySQL 8 (docker) in addition to the sqlite
     * test suite, since Laravel's schema builder can't build/run this
     * locally against both engines automatically.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TABLE users (
                    id char(26) not null primary key,
                    tenant_id char(26) not null,
                    name varchar(255) not null,
                    email varchar(191) not null,
                    role varchar(20) not null,
                    issuer_id char(26) null,
                    invited_at datetime not null,
                    last_login_at datetime null,
                    created_at datetime null,
                    updated_at datetime null,
                    unique(email),
                    foreign key(tenant_id) references tenants(id) on delete cascade,
                    foreign key(issuer_id) references issuers(id),
                    check ((role = 'vendor' and issuer_id is not null) or (role <> 'vendor' and issuer_id is null))
                )
                SQL);

            Schema::table('users', function (Blueprint $table): void {
                $table->index(['tenant_id', 'role']);
            });

            return;
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email', 191)->unique();
            $table->string('role', 20);
            $table->foreignUlid('issuer_id')->nullable()->constrained();
            $table->timestamp('invited_at');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'role']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT chk_users_role_issuer
            CHECK ((role = 'vendor' AND issuer_id IS NOT NULL) OR (role <> 'vendor' AND issuer_id IS NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
