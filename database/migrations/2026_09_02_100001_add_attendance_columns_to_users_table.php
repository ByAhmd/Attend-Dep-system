<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role and status on Laravel's own users table.
 *
 * Extends the framework table rather than replacing it, so sessions and
 * password hashing keep working unchanged. The CHECK constraints mirror the
 * UserRole and UserStatus enums: a value the code does not know cannot be
 * stored, whatever writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('employee')->after('password');
            $table->string('status', 20)->default('active')->after('role');

            $table->index('role');
            $table->index('status');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'employee'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'inactive'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_status_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropColumn(['role', 'status']);
        });
    }
};
