<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveAttachmentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The response headers, on every kind of response this product returns.
 *
 * Two things are being defended here. The first is the policy itself, and
 * in particular the single line the whole product depends on: geolocation
 * must stay permitted, and a check-in cannot be tested by reading a header,
 * so the tests below state the rule that would have to be broken for
 * check-in to stop working, in the hope that breaking it is then a
 * deliberate act with a red test beside it.
 *
 * The second is the reach. SecurityHeaders is global middleware, and these
 * tests walk the paths that are easy to forget: both panels, a redirect, a
 * streamed file download and an error page. A policy that is absent from
 * exactly the responses somebody probing the site would see is worse than
 * no policy, because it reads as one.
 *
 * One thing here is deliberately untested. X-Powered-By is added by PHP
 * itself and never enters the response object, so removing it is
 * header_remove() reaching into the list the SAPI will send - a list that
 * does not exist under PHPUnit, where nothing is ever sent. An assertion
 * that the header bag lacks a header it could never have held would pass
 * whether or not the middleware did anything, which is worse than no test.
 */
final class SecurityHeadersTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_sign_in_page_carries_every_policy_header(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');

        self::assertNotSame('', $this->policy($response));
        self::assertNotSame('', $this->permissions($response));
    }

    #[Test]
    public function the_policy_permits_geolocation_for_this_origin(): void
    {
        $employee = $this->makeEmployee();

        $permissions = $this->permissions($this->actingAs($employee)->get('/'));

        // The whole product. `(self)` is documents of this origin, which is
        // the attendance page; `()` would be nobody at all, and would refuse
        // every check-in in the company.
        self::assertStringContainsString('geolocation=(self)', $permissions);
    }

    #[Test]
    public function the_policy_gives_up_the_capabilities_that_read_a_device(): void
    {
        $permissions = $this->permissions($this->get('/login'));

        foreach (['camera', 'microphone', 'display-capture', 'usb', 'serial', 'hid', 'bluetooth', 'payment', 'idle-detection'] as $capability) {
            self::assertStringContainsString($capability.'=()', $permissions);
        }
    }

    #[Test]
    public function the_policy_leaves_the_clipboard_alone_because_an_invitation_link_is_copied(): void
    {
        $permissions = $this->permissions($this->get('/login'));

        self::assertStringNotContainsString('clipboard', $permissions);
    }

    #[Test]
    public function the_policy_confines_every_fetch_to_this_origin(): void
    {
        $policy = $this->policy($this->get('/login'));

        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertStringContainsString("base-uri 'self'", $policy);
        self::assertStringContainsString("object-src 'none'", $policy);
        self::assertStringContainsString("form-action 'self'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);

        // A script that did get onto the page has nowhere to send anything.
        self::assertStringContainsString("connect-src 'self' blob:", $policy);
    }

    #[Test]
    public function the_policy_allows_the_avatar_and_the_upload_preview_and_nothing_else_remote(): void
    {
        $policy = $this->policy($this->get('/login'));

        // data: is the avatar this application draws instead of fetching;
        // blob: is FilePond's preview of a chosen photograph, and the worker
        // it compiles to build that preview.
        self::assertStringContainsString("img-src 'self' data: blob:", $policy);
        self::assertStringContainsString("worker-src 'self' blob:", $policy);
    }

    #[Test]
    public function the_policy_admits_what_livewire_and_alpine_actually_need(): void
    {
        $policy = $this->policy($this->get('/login'));

        // Honest rather than aspirational: Livewire writes inline script and
        // style into the page and Alpine compiles its expressions, so these
        // two relaxations are the price of the framework. Written down here
        // so that removing them is a decision somebody takes with a failing
        // test in front of them rather than a checklist tightening that
        // white-screens both panels in production.
        self::assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $policy);
        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $policy);
    }

    #[Test]
    public function the_policy_keeps_the_upgrade_directive_the_edge_already_sends(): void
    {
        // The CDN in front of production injects a policy containing exactly
        // this and nothing else. Two policies are intersected rather than
        // merged, so keeping the directive in ours means the behaviour the
        // site has today survives whichever way the edge treats a second
        // header.
        self::assertStringContainsString('upgrade-insecure-requests', $this->policy($this->get('/login')));
    }

    #[Test]
    public function the_administration_panel_carries_the_headers_too(): void
    {
        $response = $this->actingAs($this->makeAdmin())->get('/admin');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');

        self::assertStringContainsString('geolocation=(self)', $this->permissions($response));
        self::assertStringContainsString("frame-ancestors 'none'", $this->policy($response));
    }

    #[Test]
    public function a_redirect_carries_the_headers(): void
    {
        $response = $this->get(route('locale.switch', ['locale' => 'en']));

        $response->assertRedirect();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        self::assertStringContainsString("default-src 'self'", $this->policy($response));
    }

    #[Test]
    public function the_attachment_download_keeps_its_own_stricter_policy(): void
    {
        Storage::fake(LeaveAttachmentStore::DISK);

        $employee = $this->makeEmployee();
        $request = $this->requestWithDocument($employee);

        $response = $this->actingAs($employee)->get(route('leave-attachments.show', $request));

        $response->assertOk();

        // The one file this product accepts from anybody comes back out
        // under a type taken from what was uploaded, which is exactly where
        // a browser must not be allowed to guess something else.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        // LeaveAttachmentStore says something narrower about these bytes
        // than the site policy ever could, and the site policy - which has
        // to admit inline script for Filament - must not be allowed to
        // loosen it on the way out.
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        // The rest of the set is still applied.
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');

        self::assertStringContainsString('geolocation=(self)', $this->permissions($response));
    }

    #[Test]
    public function an_error_page_carries_the_headers(): void
    {
        $response = $this->get('/a-page-that-does-not-exist');

        $response->assertNotFound();
        $response->assertHeader('X-Frame-Options', 'DENY');

        self::assertStringContainsString("object-src 'none'", $this->policy($response));
    }

    #[Test]
    public function strict_transport_security_is_not_sent_outside_production(): void
    {
        // A browser must ignore the header when it arrives over plain HTTP,
        // and pinning a developer's own hostname for a year is a trap with
        // nothing behind it.
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function production_promises_https_for_the_configured_time_and_nothing_more(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        config(['security.hsts.max_age' => 31_536_000]);

        $header = (string) $this->get('/login')->headers->get('Strict-Transport-Security');

        self::assertSame('max-age=31536000', $header);

        // includeSubDomains would speak for names that do not exist under
        // this host today, and would quietly widen if the application were
        // moved to the parent domain. preload is a submission to a list
        // built into browser releases and takes months to leave.
        self::assertStringNotContainsString('includeSubDomains', $header);
        self::assertStringNotContainsString('preload', $header);
    }

    #[Test]
    public function the_promised_duration_is_the_one_that_was_configured(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        config(['security.hsts.max_age' => 300]);

        $this->get('/login')->assertHeader('Strict-Transport-Security', 'max-age=300');
    }

    private function policy(TestResponse $response): string
    {
        return (string) $response->headers->get('Content-Security-Policy');
    }

    /**
     * The two copies of the policy must say the same thing.
     *
     * Hostinger's web server replaces the Content-Security-Policy this
     * middleware sets, so public/.htaccess sets it again at a layer that
     * runs afterwards. That is two copies of one rule, and two copies drift:
     * somebody adds a directive here for a new feature, production keeps
     * serving the old policy, and the failure is silent because the header
     * is still present and still looks right. This test is the thing that
     * refuses to let that happen.
     */
    #[Test]
    public function the_server_level_policy_matches_the_one_this_middleware_sets(): void
    {
        $htaccess = (string) file_get_contents(public_path('.htaccess'));

        $expected = SecurityHeaders::contentSecurityPolicy();

        $this->assertStringContainsString(
            'Header always set Content-Security-Policy "'.$expected.'" env=!MAKANI_UPLOADED_FILE',
            $htaccess,
            'public/.htaccess serves a different Content-Security-Policy from the one this middleware sets. '
            .'Update the .htaccess string to match SecurityHeaders::CONTENT_SECURITY_POLICY.',
        );
    }

    /**
     * A file somebody uploaded keeps the policy that lets it do nothing.
     *
     * The .htaccess rule above would otherwise hand the attachment download
     * the same policy as every ordinary page, which is far looser than a
     * response made of bytes a stranger chose deserves.
     */
    #[Test]
    public function the_server_level_rule_excepts_the_uploaded_file(): void
    {
        $htaccess = (string) file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString(
            'SetEnvIf Request_URI "^/leave-requests/[0-9]+/attachment$" MAKANI_UPLOADED_FILE=1',
            $htaccess,
        );

        $this->assertStringContainsString(
            'Header always set Content-Security-Policy "default-src \'none\'; sandbox" env=MAKANI_UPLOADED_FILE',
            $htaccess,
        );
    }

    /**
     * The origin advertises HTTP/3 and this site asks browsers to forget it.
     *
     * `clear` is RFC 7838's value for "discard the alternative services you
     * have cached for this origin". Removing the header instead would leave
     * every phone that has already seen it pinned to HTTP/3 for the thirty
     * days the server advertised - which is the whole problem, not the fix.
     */
    #[Test]
    public function the_site_asks_browsers_to_forget_the_http3_alternative(): void
    {
        $this->assertStringContainsString(
            'Header always set Alt-Svc "clear"',
            (string) file_get_contents(public_path('.htaccess')),
        );
    }

    private function permissions(TestResponse $response): string
    {
        return (string) $response->headers->get('Permissions-Policy');
    }

    private function requestWithDocument(User $employee): LeaveRequest
    {
        $request = $this->leaveRequest($employee);

        $path = 'leave/'.$employee->id.'/'.$request->id.'.pdf';

        Storage::disk(LeaveAttachmentStore::DISK)->put($path, '%PDF-1.4 a note');

        $request->forceFill([
            'attachment_path' => $path,
            'attachment_name' => 'note.pdf',
            'attachment_size' => 15,
            'attachment_mime_type' => 'application/pdf',
        ])->save();

        return $request;
    }
}
