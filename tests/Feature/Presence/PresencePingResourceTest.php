<?php

declare(strict_types=1);

namespace Tests\Feature\Presence;

use App\Enums\NavigationGroup;
use App\Filament\Resources\PresencePings\Pages\ListPresencePings;
use App\Filament\Resources\PresencePings\PresencePingResource;
use App\Models\PresencePing;
use App\Models\User;
use App\Services\Attendance\PresencePingRecorder;
use App\Support\Geo\LocationReading;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * PresencePingResource: the administrator's read-only view of the pings.
 *
 * Rows come from the recorder rather than being inserted by hand, so the
 * table is shown exactly what the system records. The page must also carry
 * the sentence that keeps it honest - pings are recorded only while the
 * attendance page is open, so a gap is not an absence - and that sentence
 * is asserted here like any other behaviour.
 */
final class PresencePingResourceTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->configureCompanyLocation();
        $this->freezeRiyadhClock('2026-09-02 10:20:00');
    }

    #[Test]
    public function the_list_shows_the_pings_with_their_position_verdict(): void
    {
        $this->actingAs($this->makeAdmin());

        $sara = $this->makeEmployee('sara@company.test');
        $this->attendanceSession($sara, '08:00');

        $inside = $this->ping($sara, $this->readingMetersFromCompany(60.0));
        $outside = $this->ping($sara, $this->readingMetersFromCompany(640.0));

        Livewire::test(ListPresencePings::class)
            ->assertCanSeeTableRecords([$inside, $outside])
            ->assertSee($sara->name)
            ->assertSee(__('presence.values.inside'))
            ->assertSee(__('presence.values.outside'))
            ->assertSee(__('attendance.units.meters', ['value' => '640']))
            ->assertOk();
    }

    #[Test]
    public function the_page_says_that_a_gap_is_not_an_absence(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->get('/admin/presence-pings')
            ->assertOk()
            ->assertSee(__('presence.pages.list.subheading'), false);
    }

    #[Test]
    public function the_inside_or_outside_filter_narrows_the_list(): void
    {
        $this->actingAs($this->makeAdmin());

        $sara = $this->makeEmployee();
        $this->attendanceSession($sara, '08:00');

        $inside = $this->ping($sara, $this->readingMetersFromCompany(60.0));
        $outside = $this->ping($sara, $this->readingMetersFromCompany(640.0));

        Livewire::test(ListPresencePings::class)
            ->filterTable('is_inside', false)
            ->assertCanSeeTableRecords([$outside])
            ->assertCanNotSeeTableRecords([$inside])
            ->filterTable('is_inside', true)
            ->assertCanSeeTableRecords([$inside])
            ->assertCanNotSeeTableRecords([$outside]);
    }

    #[Test]
    public function the_employee_filter_shows_only_that_employees_pings(): void
    {
        $this->actingAs($this->makeAdmin());

        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');
        $this->attendanceSession($sara, '08:00');
        $this->attendanceSession($omar, '08:30');

        $hers = $this->ping($sara, $this->readingAtCompany());
        $his = $this->ping($omar, $this->readingAtCompany());

        Livewire::test(ListPresencePings::class)
            ->filterTable('user_id', $sara->id)
            ->assertCanSeeTableRecords([$hers])
            ->assertCanNotSeeTableRecords([$his]);
    }

    #[Test]
    public function the_date_range_filter_shows_only_that_period(): void
    {
        $this->actingAs($this->makeAdmin());

        $sara = $this->makeEmployee();

        $this->freezeRiyadhClock('2026-09-01 09:00:00');
        $this->attendanceSession($sara, '08:00');
        $yesterday = $this->ping($sara, $this->readingAtCompany());

        $this->freezeRiyadhClock('2026-09-02 09:00:00');
        $this->attendanceSession($sara, '08:00');
        $today = $this->ping($sara, $this->readingAtCompany());

        Livewire::test(ListPresencePings::class)
            ->filterTable('date_range', ['from' => '2026-09-02', 'until' => '2026-09-02'])
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$yesterday]);
    }

    #[Test]
    public function the_map_link_opens_the_reported_position(): void
    {
        $this->actingAs($this->makeAdmin());

        $sara = $this->makeEmployee();
        $this->attendanceSession($sara, '08:00');

        $ping = $this->ping($sara, $this->readingMetersFromCompany(640.0));

        Livewire::test(ListPresencePings::class)
            ->assertTableActionHasUrl(
                'openMap',
                "https://www.google.com/maps?q={$ping->latitude},{$ping->longitude}",
                $ping,
            )
            ->assertTableActionShouldOpenUrlInNewTab('openMap', $ping);
    }

    #[Test]
    public function the_resource_is_registered_in_the_attendance_group_of_the_admin_panel(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->assertContains(PresencePingResource::class, Filament::getPanel('admin')->getResources());
        $this->assertSame(NavigationGroup::Attendance, PresencePingResource::getNavigationGroup());

        $this->get('/admin')
            ->assertOk()
            ->assertSee(__('presence.navigation.label'));
    }

    #[Test]
    public function the_list_is_read_only(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->assertFalse(PresencePingResource::canCreate());
        $this->assertSame(['index'], array_keys(PresencePingResource::getPages()));

        $this->get('/admin/presence-pings')->assertOk();
        $this->get('/admin/presence-pings/create')->assertNotFound();
    }

    #[Test]
    public function nobody_writes_a_ping_through_the_gate(): void
    {
        $admin = $this->makeAdmin();
        $employee = $this->makeEmployee();
        $this->attendanceSession($employee, '08:00');

        $ping = $this->ping($employee, $this->readingAtCompany());

        foreach ([$admin, $employee] as $user) {
            $gate = Gate::forUser($user);

            $this->assertFalse($gate->allows('create', PresencePing::class));
            $this->assertFalse($gate->allows('update', $ping));
            $this->assertFalse($gate->allows('delete', $ping));
            $this->assertFalse($gate->allows('deleteAny', PresencePing::class));
        }

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', PresencePing::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $ping));
    }

    #[Test]
    public function an_employee_can_neither_reach_the_screen_nor_read_a_colleagues_pings(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $this->attendanceSession($omar, '08:00');
        $his = $this->ping($omar, $this->readingAtCompany());

        $this->assertFalse(Gate::forUser($sara)->allows('viewAny', PresencePing::class));
        $this->assertFalse(Gate::forUser($sara)->allows('view', $his));

        $this->actingAs($sara)
            ->get('/admin/presence-pings')
            ->assertForbidden();

        // Nor does their own screen carry anybody's pings.
        $this->actingAs($sara)
            ->get('/')
            ->assertOk()
            ->assertDontSee(__('presence.navigation.label'));
    }

    /**
     * Records one ping the way the application does, and returns the row.
     */
    private function ping(User $employee, LocationReading $reading): PresencePing
    {
        $ping = app(PresencePingRecorder::class)->record($employee, $reading);

        $this->assertInstanceOf(PresencePing::class, $ping);

        return $ping;
    }
}
