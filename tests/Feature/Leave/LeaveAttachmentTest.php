<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Enums\UserStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveAttachmentStore;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The one file this product accepts from anybody, and the door it comes
 * back out of.
 *
 * These requests walk the real route with its real middleware, so they
 * prove the door as deployed rather than the policy in isolation: who is
 * turned away and where they land, what a stranger learns about a request
 * that is not theirs, and what happens to a path that tries to leave the
 * disk. Every refusal is checked twice - the status, and then the bytes,
 * because a 403 that still streamed the file would pass a status assertion.
 */
final class LeaveAttachmentTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    /**
     * Recognisable bytes. Nothing here parses a PDF; the tests only need to
     * know whether these exact bytes reached the caller.
     */
    private const string DOCUMENT = "%PDF-1.4\nA medical note that only its owner may read.\n";

    private const string DOCUMENT_NAME = 'medical-note.pdf';

    private const string DOCUMENT_TYPE = 'application/pdf';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(LeaveAttachmentStore::DISK);
    }

    #[Test]
    public function an_employee_downloads_the_document_they_attached(): void
    {
        $employee = $this->makeEmployee();
        $request = $this->requestWithDocument($employee);

        $response = $this->actingAs($employee)->get($this->url($request));

        $response->assertOk();
        $response->assertHeader('Content-Type', self::DOCUMENT_TYPE);

        self::assertSame(self::DOCUMENT, $response->streamedContent());
    }

    #[Test]
    public function an_administrator_downloads_any_employees_document(): void
    {
        $request = $this->requestWithDocument($this->makeEmployee());

        $response = $this->actingAs($this->makeAdmin())->get($this->url($request));

        $response->assertOk();

        self::assertSame(self::DOCUMENT, $response->streamedContent());
    }

    #[Test]
    public function the_address_carries_the_requests_id_and_never_a_file_name(): void
    {
        $request = $this->requestWithDocument($this->makeEmployee());

        self::assertSame(
            'https://attendance.test/leave-requests/'.$request->id.'/attachment',
            route('leave-attachments.show', $request),
        );
    }

    #[Test]
    public function the_download_carries_the_name_the_employee_gave_the_file(): void
    {
        $employee = $this->makeEmployee();
        $request = $this->requestWithDocument($employee, name: 'تقرير طبي.pdf');

        $disposition = (string) $this->actingAs($employee)
            ->get($this->url($request))
            ->headers->get('Content-Disposition');

        self::assertStringStartsWith('attachment;', $disposition);
        self::assertStringContainsString("filename*=utf-8''".rawurlencode('تقرير طبي.pdf'), $disposition);
        // The ASCII fallback an older client reads instead. It exists so
        // that a name which transliterates to nothing cannot turn a
        // download into a header the response class refuses to build.
        self::assertStringContainsString('filename="tkryr tby.pdf"', $disposition);
    }

    #[Test]
    public function a_signed_out_visitor_is_sent_to_the_login_page_and_never_the_file(): void
    {
        $request = $this->requestWithDocument($this->makeEmployee());

        $response = $this->get($this->url($request));

        $response->assertRedirect('/login');

        self::assertStringNotContainsString(self::DOCUMENT, (string) $response->getContent());
        $this->assertGuest();
    }

    #[Test]
    public function an_employee_may_not_read_another_employees_document(): void
    {
        $request = $this->requestWithDocument($this->makeEmployee('owner@example.test'));

        $response = $this->actingAs($this->makeEmployee('stranger@example.test'))
            ->get($this->url($request));

        $response->assertForbidden();

        self::assertStringNotContainsString(self::DOCUMENT, (string) $response->getContent());
    }

    #[Test]
    public function a_deactivated_account_is_signed_out_rather_than_served(): void
    {
        $employee = $this->makeEmployee(status: UserStatus::Inactive);
        $request = $this->requestWithDocument($employee);

        $response = $this->actingAs($employee)->get($this->url($request));

        $response->assertRedirect('/login');

        self::assertStringNotContainsString(self::DOCUMENT, (string) $response->getContent());
        $this->assertGuest();
    }

    #[Test]
    public function a_deleted_account_stops_reading_the_document_it_left_behind(): void
    {
        $employee = $this->makeEmployee();
        $request = $this->requestWithDocument($employee);

        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            self::fail('The web guard is no longer a session guard; this test replays the session it keeps.');
        }

        // The session a signed-in browser holds, replayed exactly as that
        // browser's next request would send it - the only honest way to ask
        // what a deleted account can still reach, since actingAs() would
        // put the deleted row back on the guard by hand.
        $session = [$guard->getName() => $employee->getKey()];

        $this->app['auth']->forgetGuards();
        $this->withSession($session)->get($this->url($request))->assertOk();

        $employee->delete();

        $this->app['auth']->forgetGuards();
        $response = $this->withSession($session)->get($this->url($request));

        $response->assertRedirect('/login');

        self::assertStringNotContainsString(self::DOCUMENT, (string) $response->getContent());
        $this->assertGuest();
    }

    #[Test]
    public function a_request_with_no_document_is_not_found(): void
    {
        $employee = $this->makeEmployee();
        $request = $this->leaveRequest($employee);

        $this->actingAs($employee)->get($this->url($request))->assertNotFound();
    }

    #[Test]
    public function a_request_whose_file_has_vanished_from_the_disk_is_not_found(): void
    {
        $employee = $this->makeEmployee();
        $request = $this->requestWithDocument($employee);

        Storage::disk(LeaveAttachmentStore::DISK)->delete((string) $request->attachment_path);

        $this->actingAs($employee)->get($this->url($request))->assertNotFound();
    }

    #[Test]
    public function a_stored_path_that_climbs_out_of_the_disk_serves_nothing(): void
    {
        $employee = $this->makeEmployee();
        $request = $this->requestWithDocument($employee);

        $outside = storage_path('framework/testing/disks/outside-the-disk.txt');
        File::ensureDirectoryExists(dirname($outside));
        File::put($outside, 'SECRET-OUTSIDE-THE-DISK');

        try {
            foreach (['../outside-the-disk.txt', '/etc/passwd', 'C:\\Windows\\win.ini', 'a/../../outside-the-disk.txt'] as $path) {
                $request->forceFill(['attachment_path' => $path])->save();

                $response = $this->actingAs($employee)->get($this->url($request));

                $response->assertNotFound();

                self::assertStringNotContainsString(
                    'SECRET-OUTSIDE-THE-DISK',
                    (string) $response->getContent(),
                    "The stored path [{$path}] reached a file outside the disk.",
                );
            }
        } finally {
            File::delete($outside);
        }
    }

    #[Test]
    public function a_traversal_in_the_address_never_reaches_the_route(): void
    {
        $this->actingAs($this->makeEmployee());

        foreach (['..%2F..%2F.env', '1%2F..%2F..%2F.env', '.env', '1.pdf'] as $parameter) {
            $this->get('/leave-requests/'.$parameter.'/attachment')->assertNotFound();
        }
    }

    #[Test]
    public function a_script_disguised_as_a_document_comes_back_as_inert_bytes(): void
    {
        $script = "<?php echo 'this must never run'; ?>";

        $employee = $this->makeEmployee();
        $request = $this->requestWithDocument($employee, contents: $script);

        $response = $this->actingAs($employee)->get($this->url($request));

        $response->assertOk();
        // Downloaded, never rendered, and never sniffed into something the
        // browser would rather run.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        self::assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));

        self::assertSame($script, $response->streamedContent());
    }

    #[Test]
    public function a_stored_type_this_product_never_accepts_is_declared_as_opaque_bytes(): void
    {
        $employee = $this->makeEmployee();
        $request = $this->requestWithDocument($employee, type: 'text/html');

        $response = $this->actingAs($employee)->get($this->url($request));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
    }

    #[Test]
    public function the_disk_is_private_and_stands_outside_the_web_root(): void
    {
        /** @var array<string, mixed> $disk */
        $disk = config('filesystems.disks.'.LeaveAttachmentStore::DISK);

        self::assertSame(storage_path('app/private/leave-attachments'), $disk['root']);
        self::assertSame('private', $disk['visibility']);
        self::assertFalse($disk['serve'] ?? false, 'The attachment disk must not serve itself over HTTP.');
        self::assertArrayNotHasKey('url', $disk, 'A stored attachment path must never resolve to a URL.');

        self::assertStringStartsNotWith(
            $this->realPath(public_path()),
            $this->realPath(storage_path('app/private')),
            'The attachment disk lives under the document root.',
        );
    }

    #[Test]
    public function nothing_in_the_disk_root_is_reachable_over_http(): void
    {
        // Real bytes on the real path this time: the faked disk lives
        // somewhere else, and the question here is whether the web server's
        // own view of storage/ can be walked into.
        $probe = storage_path('app/private/leave-attachments/http-probe.txt');
        File::ensureDirectoryExists(dirname($probe));
        File::put($probe, 'SECRET-ON-THE-DISK');

        try {
            foreach ([
                '/storage/leave-attachments/http-probe.txt',
                '/storage/../private/leave-attachments/http-probe.txt',
            ] as $address) {
                $response = $this->actingAs($this->makeEmployee())->get($address);

                self::assertNotSame(200, $response->getStatusCode(), "[{$address}] served a private file.");
                self::assertStringNotContainsString('SECRET-ON-THE-DISK', (string) $response->getContent());
            }
        } finally {
            File::delete($probe);
        }
    }

    #[Test]
    public function discarding_a_document_removes_the_file_and_all_four_columns(): void
    {
        $request = $this->requestWithDocument($this->makeEmployee());
        $path = (string) $request->attachment_path;

        $this->store()->discard($request);

        Storage::disk(LeaveAttachmentStore::DISK)->assertMissing($path);

        $this->assertDatabaseHas('leave_requests', [
            'id' => $request->id,
            'attachment_path' => null,
            'attachment_name' => null,
            'attachment_size' => null,
            'attachment_mime_type' => null,
        ]);
    }

    #[Test]
    public function discarding_a_request_that_never_had_a_document_is_harmless(): void
    {
        $request = $this->leaveRequest($this->makeEmployee());

        $this->store()->discard($request);

        $this->assertDatabaseHas('leave_requests', [
            'id' => $request->id,
            'attachment_path' => null,
        ]);
    }

    #[Test]
    public function an_abandoned_upload_can_be_forgotten_without_a_request_row(): void
    {
        $disk = Storage::disk(LeaveAttachmentStore::DISK);
        $disk->put('leave/abandoned.pdf', self::DOCUMENT);

        $this->store()->forget('leave/abandoned.pdf');

        $disk->assertMissing('leave/abandoned.pdf');
    }

    #[Test]
    public function forgetting_refuses_a_path_that_points_outside_the_disk(): void
    {
        $outside = storage_path('framework/testing/disks/must-survive.txt');
        File::ensureDirectoryExists(dirname($outside));
        File::put($outside, 'SECRET-OUTSIDE-THE-DISK');

        try {
            $this->store()->forget('../must-survive.txt');
            $this->store()->forget('/etc/passwd');

            self::assertTrue(File::exists($outside), 'A deletion escaped the attachment disk.');
        } finally {
            File::delete($outside);
        }
    }

    private function store(): LeaveAttachmentStore
    {
        return app(LeaveAttachmentStore::class);
    }

    private function url(LeaveRequest $request): string
    {
        return route('leave-attachments.show', $request);
    }

    /**
     * A pending leave request carrying a stored document, written the way
     * the workflow writes it: the file first, then the four columns
     * together.
     */
    private function requestWithDocument(
        User $employee,
        string $contents = self::DOCUMENT,
        string $name = self::DOCUMENT_NAME,
        string $type = self::DOCUMENT_TYPE,
    ): LeaveRequest {
        $request = $this->leaveRequest($employee);

        $path = 'leave/'.$employee->id.'/'.$request->id.'.pdf';

        Storage::disk(LeaveAttachmentStore::DISK)->put($path, $contents);

        $request->forceFill([
            'attachment_path' => $path,
            'attachment_name' => $name,
            'attachment_size' => strlen($contents),
            'attachment_mime_type' => $type,
        ])->save();

        return $request;
    }

    private function realPath(string $path): string
    {
        return str_replace('\\', '/', (string) (realpath($path) ?: $path));
    }
}
