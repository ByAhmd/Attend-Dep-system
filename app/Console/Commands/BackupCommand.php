<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceCalendar;
use App\Services\Leave\LeaveAttachmentStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * One backup: the database as a compressed SQL dump, and the leave
 * attachments as a zip, in a timestamped folder under storage/app/backups.
 *
 * This product's whole value is that its records can be believed later,
 * and records that exist on one disk are one disk failure from never
 * having existed. There is no cron on the production host, so this is a
 * command and not a schedule: the host panel's own scheduler (or a human,
 * monthly) runs `php artisan app:backup`, and copying the newest folder
 * off the server is the half no command can do.
 *
 * mysqldump does the dump - a hand-rolled SQL writer would be a second
 * implementation of MySQL's own format, wrong exactly when it matters.
 * The binary name comes from config so a host that keeps it off PATH can
 * point at it; the password travels in the process environment, never in
 * the command line a process list could read.
 *
 * Rotation keeps the newest N sets and deletes the rest, so a scheduler
 * left running for a year cannot quietly fill the disk.
 */
final class BackupCommand extends Command
{
    protected $signature = 'app:backup {--keep= : How many backup sets to keep (default from config)}';

    protected $description = 'Back up the database and the leave attachments into storage/app/backups';

    public function handle(AttendanceCalendar $calendar): int
    {
        $root = storage_path('app/backups');
        $stamp = $calendar->now()->format('Y-m-d_His');
        $target = $root.DIRECTORY_SEPARATOR.$stamp;

        File::ensureDirectoryExists($target);

        if (! $this->dumpDatabase($target.DIRECTORY_SEPARATOR.'db.sql.gz')) {
            // A half-written set must not survive to be mistaken for a
            // backup: the dump is the half that matters.
            File::deleteDirectory($target);

            return self::FAILURE;
        }

        $attachments = $this->zipAttachments($target.DIRECTORY_SEPARATOR.'attachments.zip');

        $this->prune($root, $this->keep());

        $this->info("Backup written to {$target}".($attachments === 0 ? ' (no attachments to include)' : " ({$attachments} attachment(s))"));
        $this->comment('Copy the newest folder OFF this server; a backup beside its database shares its disk.');

        return self::SUCCESS;
    }

    /**
     * The database, dumped by mysqldump and gzipped as it streams, so the
     * uncompressed SQL never exists on disk.
     *
     * Two attempts, because mysqldump 8.0.32+ demands the RELOAD privilege
     * for its consistent snapshot and the limited user a shared host
     * grants rarely has it: the snapshot is tried first, and when the
     * server refuses exactly that, the dump runs again without it. On a
     * database this size, written to at two moments of the day, the
     * difference is theoretical; failing the whole backup over it would
     * not be.
     */
    private function dumpDatabase(string $path): bool
    {
        $error = '';

        foreach ([['--single-transaction'], ['--skip-lock-tables']] as $attempt) {
            $process = $this->runMysqldump($path, $attempt);

            if (! $process instanceof Process) {
                return false;
            }

            if ($process->isSuccessful()) {
                return true;
            }

            $error = trim($process->getErrorOutput());

            if (! str_contains($error, 'RELOAD or FLUSH_TABLES')) {
                break;
            }
        }

        $this->error('mysqldump failed: '.$error);

        return false;
    }

    /**
     * One mysqldump run, streamed into a fresh gzip at $path, or null
     * when the binary could not be started at all.
     *
     * @param  list<string>  $extraOptions
     */
    private function runMysqldump(string $path, array $extraOptions): ?Process
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $connection */
        $connection = (array) config('database.connections.mysql');

        $process = new Process(
            [
                (string) config('attendance.backup.mysqldump'),
                '--host='.$connection['host'],
                '--port='.(string) $connection['port'],
                '--user='.$connection['username'],
                ...$extraOptions,
                '--no-tablespaces',
                $connection['database'],
            ],
            env: ['MYSQL_PWD' => $connection['password']],
            timeout: 300,
        );

        $gz = gzopen($path, 'wb9');

        if ($gz === false) {
            $this->error("Cannot write {$path}.");

            return null;
        }

        try {
            $process->run(function (string $type, string $buffer) use ($gz): void {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);
                }
            });
        } catch (Throwable $exception) {
            gzclose($gz);
            $this->error('mysqldump could not be started: '.$exception->getMessage());
            $this->comment('Set BACKUP_MYSQLDUMP in .env to the full path of the mysqldump binary.');

            return null;
        }

        gzclose($gz);

        return $process;
    }

    /**
     * Every file on the leave-attachment disk, zipped with its stored
     * path. The disk is private and the zip lands beside the dump, so the
     * pair restores together or not at all.
     */
    private function zipAttachments(string $path): int
    {
        $disk = Storage::disk(LeaveAttachmentStore::DISK);
        $files = $disk->allFiles();

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->warn("Could not create {$path}; the database dump stands alone.");

            return 0;
        }

        foreach ($files as $file) {
            $zip->addFile($disk->path($file), $file);
        }

        $zip->close();

        return count($files);
    }

    /**
     * The newest sets stay, the rest go. Sorting the folder names sorts
     * the timestamps they are named after.
     */
    private function prune(string $root, int $keep): void
    {
        $sets = collect(File::directories($root))
            ->sortDesc()
            ->values();

        foreach ($sets->slice($keep) as $old) {
            File::deleteDirectory((string) $old);
        }
    }

    private function keep(): int
    {
        $option = $this->option('keep');

        if (is_numeric($option) && (int) $option > 0) {
            return (int) $option;
        }

        return max(1, (int) config('attendance.backup.keep'));
    }
}
