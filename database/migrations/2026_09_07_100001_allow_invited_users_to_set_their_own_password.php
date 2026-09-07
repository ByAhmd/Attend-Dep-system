<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room for an invited account: a third status and no password yet.
 *
 * An administrator no longer chooses anybody's password. A new employee is
 * created 'pending' with users.password NULL and picks their own through the
 * invitation link, at which point the account becomes 'active'.
 *
 * Both changes are additive and leave every existing row exactly as it is:
 * widening a CHECK constraint accepts everything it accepted before, and a
 * column that stops being NOT NULL keeps the hashes already in it. There is
 * nothing to backfill - every account on the live system is 'active' or
 * 'inactive' and has a password.
 *
 * A CHECK constraint cannot be altered in place on either MySQL 8 or
 * MariaDB 10.4, so it is dropped and re-added under the same name.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Widen the constraint first: nothing may write 'pending' until the
        // database is willing to hold it.
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_status_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'inactive', 'pending'))");

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A pending account cannot be described by the old constraint, and an
        // account with no password cannot live in a NOT NULL column. Both are
        // folded into 'inactive', the pre-invitation way of saying "this
        // account exists and cannot sign in"; an empty hash never matches a
        // password, so nobody gains access by rolling back.
        DB::table('users')->where('status', 'pending')->update(['status' => 'inactive']);
        DB::table('users')->whereNull('password')->update(['password' => '']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_status_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'inactive'))");
    }
};
