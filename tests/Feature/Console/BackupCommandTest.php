<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Leave\LeaveAttachmentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

/**
 * app:backup end to end: a timestamped set holding a readable gzipped
 * dump and the attachments, and rotation that keeps the newest sets.
 *
 * These tests run mysqldump for real against the testing database - the
 * command's whole job is driving that binary correctly, and a stub would
 * prove nothing. Where the machine has no mysqldump the suite says so and
 * skips, rather than failing on an environment the code cannot fix; CI
 * has the client tools and runs them.
 */
final class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $backupRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupRoot = storage_path('app/backups');

        File::deleteDirectory($this->backupRoot);

        Storage::fake(LeaveAttachmentStore::DISK);

        if (! $this->mysqldumpIsAvailable()) {
            $this->markTestSkipped('mysqldump is not available on this machine; set BACKUP_MYSQLDUMP to run these tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupRoot);

        parent::tearDown();
    }

    private function mysqldumpIsAvailable(): bool
    {
        try {
            $process = new Process([(string) config('attendance.backup.mysqldump'), '--version']);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function backupSets(): array
    {
        return collect(File::directories($this->backupRoot))->sort()->values()->all();
    }

    #[Test]
    public function one_run_writes_a_dump_and_the_attachments_beside_it(): void
    {
        Storage::disk(LeaveAttachmentStore::DISK)->put('leave-attachments/receipt.pdf', 'pdf-bytes');

        $this->artisan('app:backup')->assertSuccessful();

        $sets = $this->backupSets();

        $this->assertCount(1, $sets);

        $dump = $sets[0].DIRECTORY_SEPARATOR.'db.sql.gz';
        $zip = $sets[0].DIRECTORY_SEPARATOR.'attachments.zip';

        $this->assertFileExists($dump);
        $this->assertFileExists($zip);

        // The dump decompresses to SQL that names a table this schema
        // actually has - a zero-byte gz would pass a mere file check.
        $sql = (string) file_get_contents('compress.zlib://'.$dump);

        $this->assertStringContainsString('attendances', $sql);

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($zip));
        $this->assertNotFalse($archive->locateName('leave-attachments/receipt.pdf'));
        $archive->close();
    }

    #[Test]
    public function rotation_keeps_the_newest_sets_and_deletes_the_rest(): void
    {
        // Three pre-existing sets with names that sort older than any
        // timestamp the command writes today.
        foreach (['2001-01-01_010101', '2001-01-02_010101', '2001-01-03_010101'] as $old) {
            File::ensureDirectoryExists($this->backupRoot.DIRECTORY_SEPARATOR.$old);
        }

        $this->artisan('app:backup', ['--keep' => 2])->assertSuccessful();

        $sets = $this->backupSets();

        $this->assertCount(2, $sets);
        // The newest survivor is the set just written; the oldest kept is
        // the newest of the stale ones.
        $this->assertStringContainsString('2001-01-03_010101', $sets[0]);
    }
}
