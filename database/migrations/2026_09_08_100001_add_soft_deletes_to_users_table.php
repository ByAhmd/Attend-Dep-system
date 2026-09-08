<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Hide, keep records": a deleted account keeps its row.
 *
 * Deleting an employee has to remove them from the interface without
 * removing them from history - their name still belongs on the attendance
 * rows, the rejected attempts and the presence pings they produced. A soft
 * delete is exactly that: `deleted_at` is stamped, Eloquent's global scope
 * hides the row from the employee list and from the authentication
 * provider, so the account can never sign in again, and an administrator
 * can restore it.
 *
 * One column, nullable, no backfill: every existing account keeps
 * `deleted_at` NULL and behaves precisely as it did before. Safe to run on
 * the live table.
 *
 * The unique index on `email` is deliberately left alone, and it therefore
 * still counts deleted accounts. That is the correct behaviour here, not an
 * oversight:
 *
 * - An address identifies a person in this system. A second row carrying
 *   the same address would split one person's attendance history across two
 *   accounts, and the attendance list would show the same name twice with
 *   no way to tell which is which.
 * - The way to bring somebody back is Restore, which returns the account
 *   with its history attached. Re-inviting the same address instead would
 *   silently orphan everything they did before.
 * - MySQL cannot express "unique among the rows that are not deleted"
 *   anyway: a partial index does not exist, and `unique(email, deleted_at)`
 *   would fail to constrain live rows, because MySQL treats each NULL in a
 *   unique index as distinct and would happily hold two live accounts on
 *   one address. Scoping the index would remove the guarantee it exists for.
 *
 * The create form therefore reports a collision, and its message points at
 * the deleted-accounts filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Dropping the column alone would resurrect every deleted account as
        // a live one that can sign in again. They are folded into
        // 'inactive' first - the pre-delete way of saying "this account
        // exists and cannot sign in" - so a rollback never grants access.
        DB::table('users')->whereNotNull('deleted_at')->update(['status' => 'inactive']);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
