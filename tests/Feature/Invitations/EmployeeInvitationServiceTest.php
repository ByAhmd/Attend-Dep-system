<?php

declare(strict_types=1);

namespace Tests\Feature\Invitations;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\EmployeeInvitationNotification;
use App\Services\Users\EmployeeInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Issuing an invitation.
 *
 * The promise of this service is that the link always works and the email
 * is a bonus, so the tests separate the two: what the URL contains and
 * where it leads, and then each way delivery can go - a real mailer, no
 * mailer at all, and a mailer that throws.
 */
final class EmployeeInvitationServiceTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private EmployeeInvitationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EmployeeInvitationService::class);
    }

    #[Test]
    public function the_link_is_absolute_and_carries_the_token_and_the_email(): void
    {
        $employee = $this->invitedEmployee();

        $url = $this->service->issueLink($employee);

        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('/password-reset/reset', $url);
        $this->assertNotSame('', $this->tokenFrom($url));
        $this->assertSame($employee->email, $this->queryFrom($url, 'email'));
    }

    #[Test]
    public function the_link_opens_the_screen_where_the_employee_sets_a_password(): void
    {
        // Signed route, guest request, pending account: everything the
        // employee's first click actually is.
        $url = $this->service->issueLink($this->invitedEmployee());

        $this->get($url)->assertOk();
    }

    #[Test]
    public function issuing_a_link_again_produces_a_different_token(): void
    {
        $employee = $this->invitedEmployee();

        $first = $this->tokenFrom($this->service->issueLink($employee));
        $second = $this->tokenFrom($this->service->issueLink($employee));

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function it_emails_the_invitation_when_a_mailer_can_deliver_it(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();

        $employee = $this->invitedEmployee();

        $invitation = $this->service->invite($employee);

        $this->assertTrue($invitation->emailed);
        Notification::assertSentTo($employee, EmployeeInvitationNotification::class);
    }

    #[Test]
    public function it_does_not_claim_delivery_when_no_mailer_delivers_anywhere(): void
    {
        // The array mailer of the test suite stands in for the log mailer of
        // the live deployment: the message goes nowhere, so the
        // administrator must not be told an email is on its way.
        Notification::fake();

        $invitation = $this->service->invite($this->invitedEmployee());

        $this->assertFalse($invitation->emailed);
        $this->assertStringContainsString('/password-reset/reset', $invitation->url);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_mail_failure_still_yields_a_usable_link(): void
    {
        // The mail channel itself is replaced by one that throws, which is
        // what an unreachable SMTP host looks like from here.
        config(['mail.default' => 'smtp']);
        $log = Log::spy();

        $this->app->bind(MailChannel::class, fn (): object => new class
        {
            public function send(object $notifiable, object $notification): void
            {
                throw new RuntimeException('SMTP host unreachable.');
            }
        });

        $invitation = $this->service->invite($this->invitedEmployee());

        $this->assertFalse($invitation->emailed);
        $this->get($invitation->url)->assertOk();

        // The failure is swallowed for the caller's sake, so the log is the
        // only place it survives - it has to be there.
        $log->shouldHaveReceived('error')->once();
    }

    #[Test]
    public function only_an_account_still_waiting_may_be_invited(): void
    {
        $this->assertTrue($this->service->canBeInvited($this->invitedEmployee()));
        $this->assertFalse($this->service->canBeInvited($this->makeEmployee()));
        $this->assertFalse($this->service->canBeInvited($this->makeEmployee(status: UserStatus::Inactive)));
    }

    private function invitedEmployee(string $email = 'invited@company.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'status' => UserStatus::Pending,
            'password' => null,
        ]);
    }

    private function tokenFrom(string $url): string
    {
        return $this->queryFrom($url, 'token');
    }

    private function queryFrom(string $url, string $key): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey($key, $query);
        $this->assertIsString($query[$key]);

        return $query[$key];
    }
}
