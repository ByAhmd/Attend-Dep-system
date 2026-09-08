<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Says who the super administrator currently is.
 *
 * There is deliberately no command that sets one. The address is pinned in
 * the server's .env precisely so that nothing inside the application - no
 * screen, no command, no row - can hand the role to somebody else; a setter
 * here would be a way around the only rule this feature has. Changing it
 * means editing SUPER_ADMIN_EMAIL on the server and clearing the config
 * cache, and that is the whole point.
 *
 * What this does do is catch the silent misconfiguration. A typo in the
 * address leaves a deployment where every super-administrator power is
 * invisible and nobody is told why: no account matches, so nobody can
 * delete an account or appoint an administrator, and the panel simply never
 * offers those buttons. That is worth an exit code, so a deployment check
 * can fail on it instead of somebody discovering it months later.
 *
 * That check is what --check is for. An unattended runner needs the exit
 * code and nothing else, and its output is not a private place.
 */
final class ShowSuperAdminCommand extends Command
{
    protected $signature = 'app:super-admin {--check : Print a verdict only, never an address}';

    protected $description = 'Show which account SUPER_ADMIN_EMAIL designates';

    public function handle(): int
    {
        $email = User::superAdminEmail();

        if ($this->option('check')) {
            return $this->verdict($email);
        }

        if ($email === null) {
            $this->components->warn('No super administrator is configured.');
            $this->components->bulletList([
                'SUPER_ADMIN_EMAIL is empty in this environment.',
                'No account can be deleted, restored, promoted or demoted through the panel.',
                'Set SUPER_ADMIN_EMAIL in .env on the server, then run php artisan config:clear.',
            ]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('SUPER_ADMIN_EMAIL', $email);

        // withTrashed() because a deleted account still holds the address,
        // and reporting "no account" for one that is merely hidden would
        // point at the wrong problem.
        $account = User::withTrashed()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $account instanceof User) {
            $this->newLine();
            $this->components->error("No account uses {$email}.");
            $this->components->bulletList([
                'The super administrator powers are unavailable to everybody until an account holds this address.',
                'Fix the address in .env, or create the account with php artisan app:create-admin.',
            ]);

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Account', $account->name);
        $this->components->twoColumnDetail('Role column', $account->role->value);
        $this->components->twoColumnDetail('Status column', $account->status->value);
        $this->components->twoColumnDetail('Deleted', $account->trashed() ? 'yes' : 'no');

        $this->newLine();
        $this->components->info('This account is the super administrator.');
        $this->components->bulletList([
            'It is an administrator and treated as active whatever the two columns above say.',
            'Nobody can deactivate, demote or delete it - not another administrator, not itself.',
            'Only editing SUPER_ADMIN_EMAIL on the server changes who this is.',
        ]);

        return self::SUCCESS;
    }

    /**
     * A verdict with no subject.
     *
     * This command's output reaches a build log through scripts/deploy.sh,
     * and the address it would otherwise print designates the one account
     * nobody can deactivate, demote or delete. A log is read by more people
     * than a terminal is, and for longer. The three exit codes are identical
     * to the verbose form, so the deployment guard is preserved exactly.
     */
    private function verdict(?string $email): int
    {
        if ($email === null) {
            $this->line('Super administrator: not configured - no account can be deleted, restored, promoted or demoted.');

            return self::SUCCESS;
        }

        $exists = User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists();

        if (! $exists) {
            $this->line('Super administrator: MISCONFIGURED - the configured address names no account.');

            return self::FAILURE;
        }

        $this->line('Super administrator: configured.');

        return self::SUCCESS;
    }
}
