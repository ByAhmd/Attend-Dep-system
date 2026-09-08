<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Eloquent's soft-delete scope, with one row it refuses to hide.
 *
 * `deleted_at` is a new way to lock an account out, and the super
 * administrator is designated in .env precisely so that no column in the
 * database can lock them out. Nothing in the interface can delete them - the
 * policy forbids it - but the pin exists to survive a careless or malicious
 * hand in phpMyAdmin, and a stamped `deleted_at` would otherwise do exactly
 * what a flipped `role` or `status` is not allowed to do: the authentication
 * provider would stop finding the row and the owner would be locked out of
 * their own system with no way back except another database edit.
 *
 * So the account named by `admin.super_admin_email` is always visible.
 * Everything else behaves exactly as `SoftDeletes` does: this subclass
 * inherits the standard scope's query macros - `withTrashed()`,
 * `onlyTrashed()`, `withoutTrashed()`, `restore()` - and widens only the one
 * WHERE clause. It replaces the standard scope rather than joining it (see
 * User::bootSoftDeletes()), so the macros go on removing the scope that is
 * actually registered, and Filament's trashed filter, which builds on those
 * same macros, keeps working untouched.
 *
 * @extends SoftDeletingScope<User>
 */
final class AccountSoftDeletingScope extends SoftDeletingScope
{
    /**
     * @param  Builder<covariant User>  $builder
     * @param  User  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $superAdminEmail = User::superAdminEmail();

        if ($superAdminEmail === null) {
            $builder->whereNull($model->getQualifiedDeletedAtColumn());

            return;
        }

        // LOWER() on the column against an already lower-cased setting: the
        // pin is matched case-insensitively everywhere else, and a MySQL
        // collation is not something to depend on for a lock-out guarantee.
        $builder->where(function (Builder $query) use ($model, $superAdminEmail): void {
            $query
                ->whereNull($model->getQualifiedDeletedAtColumn())
                ->orWhereRaw('LOWER('.$model->qualifyColumn('email').') = ?', [$superAdminEmail]);
        });
    }
}
