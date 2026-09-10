<?php

declare(strict_types=1);

namespace App\Services\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who is told when a request arrives.
 *
 * A business rule, so it lives here and not in a listener: "administrator"
 * in this system is not `role = 'admin'`. The account named by
 * SUPER_ADMIN_EMAIL is an administrator whatever the column says - that is
 * the whole purpose of pinning it in .env - and the same clause the
 * soft-delete scope uses is written again here, against the same normalised
 * address, so both agree on what "the same account" means.
 *
 * Two rules decide the list, and both are applied identically to every row
 * including the pinned one, which is deliberate. Nothing in this product may
 * reveal which account is the super administrator, and a bell is an
 * excellent place to leak it: an administrator who noticed that one colleague
 * kept receiving notices after being deactivated would have found them.
 *
 *  - Deleted accounts are excluded. An account taken out of the system is
 *    not an administrator, and the pinned one is excluded on exactly the
 *    same terms - it can only be trashed by a hand in the database, since
 *    the policy refuses to delete it.
 *
 *  - Deactivated accounts are NOT excluded, and this is the clause that
 *    keeps the pin invisible. isActive() answers yes for the super
 *    administrator however `users.status` reads, so filtering on status
 *    would notify them while a deactivated colleague received nothing, and
 *    the difference would be visible in the queue. Not filtering treats
 *    them the same, and costs only a row nobody reads until the account is
 *    switched back on - at which point finding out what was missed is the
 *    useful thing anyway.
 *
 * The requester is always excluded. An administrator files a correction from
 * the employee screen like anybody else, and being told about one's own
 * request is noise; nobody decides their own request in any case.
 */
final readonly class RequestAudience
{
    /**
     * Every administrator except the person who asked.
     *
     * One query, whatever the answer's size. The rows are needed as models
     * because each of them is notified, and Laravel's database channel is
     * one INSERT per recipient - which for a company of fifteen is two or
     * three.
     *
     * @return Collection<int, User>
     */
    public function administratorsOtherThan(User $requester): Collection
    {
        $superAdminEmail = User::superAdminEmail();

        return User::query()
            // The scope this model boots deliberately never hides the super
            // administrator, so "not deleted" has to be said out loud rather
            // than left to it.
            ->withoutTrashed()
            ->whereKeyNot($requester->getKey())
            ->where(function (Builder $query) use ($superAdminEmail): void {
                $query->where('role', UserRole::Admin->value);

                if ($superAdminEmail !== null) {
                    // LOWER() on the column against an already lower-cased
                    // setting, exactly as AccountSoftDeletingScope matches
                    // it: a collation is not something to rest this on.
                    $query->orWhereRaw('LOWER(email) = ?', [$superAdminEmail]);
                }
            })
            ->get();
    }
}
