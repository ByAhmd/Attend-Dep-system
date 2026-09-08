<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Filament\Resources\AttendanceRejections\Pages\ListAttendanceRejections;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\PresencePings\Pages\ListPresencePings;
use App\Models\Attendance;
use App\Models\AttendanceRejection;
use App\Models\PresencePing;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Deleting an employee means hide the account and keep the records.
 *
 * Two halves, and the second is the one that is easy to get wrong. The
 * account has to be gone - out of the list, unable to sign in, out of the
 * authentication provider's reach - while every row it produced goes on
 * naming the person who produced it. A soft delete gives the first half for
 * free and takes the second away, because an ordinary belongsTo resolves to
 * null the moment the parent is trashed and every screen quietly starts
 * printing an empty name where somebody used to be.
 */
final class AccountDeletionTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->superAdmin = $this->makeAdmin('owner@company.test');
        config(['admin.super_admin_email' => 'owner@company.test']);

        $this->actingAs($this->superAdmin);
    }

    #[Test]
    public function a_deleted_account_keeps_its_row_rather_than_losing_it(): void
    {
        $employee = $this->namedEmployee();

        $employee->delete();

        $this->assertSoftDeleted($employee);
        $this->assertDatabaseHas('users', ['id' => $employee->id, 'name' => 'Sara Ali']);
    }

    #[Test]
    public function a_deleted_account_can_never_sign_in_again(): void
    {
        $employee = $this->namedEmployee();

        $employee->delete();

        // The provider is what a browser still holding a session cookie
        // meets on its next request, and what the login form asks. Neither
        // can find the account any more.
        $this->assertFalse(Auth::attempt(['email' => $employee->email, 'password' => 'secret']));
        $this->assertNull(Auth::getProvider()->retrieveById($employee->getKey()));
    }

    #[Test]
    public function a_session_left_open_by_a_deleted_account_stops_working(): void
    {
        $employee = $this->namedEmployee();

        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            self::fail('The web guard is no longer a session guard; this test probes the session it keeps.');
        }

        // The session a signed-in browser holds is the employee's id under
        // the guard's key. Replaying it is what that browser's next request
        // does; setUp() signed an administrator in on this guard instance,
        // so it is dropped first and each probe resolves the session afresh.
        $session = [$guard->getName() => $employee->getKey()];

        $this->app['auth']->forgetGuards();
        $this->withSession($session)->get('/')->assertOk();

        $employee->delete();

        $this->app['auth']->forgetGuards();
        $this->withSession($session)->get('/')->assertRedirect('/login');

        $this->assertGuest();
    }

    #[Test]
    public function a_deleted_account_is_out_of_the_employee_list_and_out_of_ordinary_queries(): void
    {
        $employee = $this->namedEmployee();

        $employee->delete();

        $this->assertNull(User::query()->find($employee->getKey()));
        $this->assertNotNull(User::withTrashed()->find($employee->getKey()));

        Livewire::test(ListEmployees::class)->assertCanNotSeeTableRecords([$employee]);
    }

    #[Test]
    public function a_deleted_employees_name_stays_on_their_attendance_rows(): void
    {
        $employee = $this->namedEmployee();
        $day = $this->checkedOut($employee);

        $employee->delete();

        $this->assertSame('Sara Ali', Attendance::query()->find($day->getKey())?->user->name);

        Livewire::test(ListAttendances::class)
            ->assertCanSeeTableRecords([$day])
            ->assertSee('Sara Ali');
    }

    #[Test]
    public function a_deleted_employees_name_stays_on_their_rejected_attempts(): void
    {
        $employee = $this->namedEmployee();
        $rejection = AttendanceRejection::query()->create([
            'user_id' => $employee->id,
            'action' => 'check_in',
            'latitude' => 24.7336,
            'longitude' => 46.6753,
            'accuracy' => 15.0,
            'distance_from_company' => 2223.9,
            'reason' => 'outside_allowed_area',
        ]);

        $employee->delete();

        $this->assertSame('Sara Ali', AttendanceRejection::query()->find($rejection->getKey())?->user->name);

        Livewire::test(ListAttendanceRejections::class)
            ->assertCanSeeTableRecords([$rejection])
            ->assertSee('Sara Ali');
    }

    #[Test]
    public function a_deleted_employees_name_stays_on_their_presence_pings(): void
    {
        $employee = $this->namedEmployee();
        $session = $this->checkedIn($employee);
        $ping = PresencePing::query()->create([
            'user_id' => $employee->id,
            'attendance_id' => $session->id,
            'latitude' => 24.7336,
            'longitude' => 46.6753,
            'accuracy' => 12.0,
            'distance_from_company' => 40.0,
            'is_inside' => true,
        ]);

        $employee->delete();

        $this->assertSame('Sara Ali', PresencePing::query()->find($ping->getKey())?->user->name);

        Livewire::test(ListPresencePings::class)
            ->assertCanSeeTableRecords([$ping])
            ->assertSee('Sara Ali');
    }

    #[Test]
    public function restoring_an_account_brings_it_back_exactly_as_it_was(): void
    {
        $employee = $this->namedEmployee();
        $day = $this->checkedOut($employee);

        $employee->delete();
        $employee->restore();

        $this->assertNotSoftDeleted($employee);
        $this->assertNotNull(User::query()->find($employee->getKey()));
        $this->assertTrue(Auth::attempt(['email' => $employee->email, 'password' => 'secret']));
        $this->assertSame('Sara Ali', Attendance::query()->find($day->getKey())?->user->name);
    }

    /**
     * A spelled-out name rather than a faked one: these tests assert that
     * exact string appears on three screens, and a generated name could
     * arrive carrying an apostrophe that the rendered HTML escapes.
     */
    private function namedEmployee(): User
    {
        return User::factory()->create([
            'name' => 'Sara Ali',
            'email' => 'sara@company.test',
            'password' => 'secret',
        ]);
    }
}
