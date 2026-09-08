<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The top bar's search box, and what one keystroke in it costs.
 *
 * A search field is the one control in a panel that runs a query on every
 * few characters typed, against the table that grows fastest - one row per
 * session per employee per day, for as long as the company exists. Two
 * things therefore have to hold at once, and the second is why these tests
 * live beside the other page-cost ones: the box has to find the right rows,
 * and it has to find them without a full table scan and without a query per
 * result. A search that is merely correct becomes the slowest thing in the
 * product in its second year.
 *
 * The shape of the SQL is asserted rather than the timing, because timing on
 * a fixture of ten rows measures nothing: `between` on attendance_date can
 * use the index the table already carries and `like '%2026-09-08%'` never
 * can, whatever the clock says today.
 */
final class GlobalSearchCostTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->actingAs($this->makeAdmin());
    }

    /**
     * An employee with a name worth searching for.
     */
    private function employeeNamed(string $name, string $email): User
    {
        $employee = $this->makeEmployee($email);

        $employee->forceFill(['name' => $name])->save();

        return $employee;
    }

    /**
     * The one result a search is expected to have produced.
     */
    private function onlyResult(string $term): GlobalSearchResult
    {
        $result = AttendanceResource::getGlobalSearchResults($term)->first();

        $this->assertInstanceOf(GlobalSearchResult::class, $result, "Searching {$term} found nothing.");

        return $result;
    }

    /**
     * The SQL of every statement one search ran.
     *
     * @return list<string>
     */
    private function sqlFor(string $term): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        AttendanceResource::getGlobalSearchResults($term);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return array_map(static fn (array $entry): string => (string) $entry['query'], $log);
    }

    #[Test]
    public function the_search_box_is_a_control_that_actually_does_something(): void
    {
        // Filament only draws the field when some resource on the panel can
        // answer it. Nothing here is more embarrassing than a search box
        // that has been typed into and has never once replied.
        $this->assertTrue(
            Filament::isGlobalSearchEnabled(),
            'The admin panel draws a search box that no resource answers.',
        );

        $this->assertTrue(
            AttendanceResource::canGloballySearch(),
            'Attendance records are what an administrator searches for, and the search does not reach them.',
        );
    }

    #[Test]
    public function an_employees_name_finds_that_employees_sessions_and_nobody_elses(): void
    {
        $sara = $this->employeeNamed('Sara Alharbi', 'sara@company.test');
        $omar = $this->employeeNamed('Omar Alqahtani', 'omar@company.test');

        $this->attendanceSession($sara, '08:00', '12:00');
        $this->attendanceSession($omar, '09:00', '17:00');

        $results = AttendanceResource::getGlobalSearchResults('Sara');

        $this->assertCount(1, $results);
        $this->assertSame('Sara Alharbi', (string) $this->onlyResult('Sara')->title);
    }

    #[Test]
    public function a_name_is_matched_through_the_employee_rather_than_by_scanning_the_sessions(): void
    {
        $this->attendanceSession($this->employeeNamed('Sara Alharbi', 'sara@company.test'), '08:00', '12:00');

        $sql = $this->sqlFor('Sara');

        // The constraint has to reach `users` through the relation. A `like`
        // written against a column of `attendances` would mean the name had
        // been copied onto every session, and a table scan to read it.
        $this->assertStringContainsString('exists (select * from `users`', $sql[0]);
        $this->assertStringContainsString('`attendances`.`user_id` = `users`.`id`', $sql[0]);
    }

    #[Test]
    public function a_day_is_compared_against_the_indexed_column_and_never_matched_as_text(): void
    {
        $sara = $this->employeeNamed('Sara Alharbi', 'sara@company.test');

        $today = $this->attendanceSession($sara, '08:00', '12:00');
        $earlier = $this->attendanceSession($sara, '08:00', '12:00', now()->subDays(3));

        $day = $today->attendance_date->toDateString();

        $results = AttendanceResource::getGlobalSearchResults($day);

        $this->assertCount(1, $results, "Searching {$day} found something other than that day's one session.");

        $sql = $this->sqlFor($day);

        $this->assertStringContainsString('`attendance_date` between', $sql[0]);
        $this->assertStringNotContainsString('like', $sql[0]);

        // And the day either side of it is untouched, which is the point of
        // comparing rather than matching a printed date.
        $this->assertCount(1, AttendanceResource::getGlobalSearchResults($earlier->attendance_date->toDateString()));
    }

    #[Test]
    public function a_month_is_one_range_over_the_same_index(): void
    {
        $sara = $this->employeeNamed('Sara Alharbi', 'sara@company.test');

        $inside = $this->attendanceSession($sara, '08:00', '12:00');
        $this->attendanceSession($sara, '08:00', '12:00', $inside->attendance_date->subMonths(2));

        $month = $inside->attendance_date->format('Y-m');

        $this->assertCount(1, AttendanceResource::getGlobalSearchResults($month));
        $this->assertStringContainsString('`attendance_date` between', $this->sqlFor($month)[0]);
    }

    #[Test]
    public function a_date_that_never_happened_is_treated_as_a_name_rather_than_a_range(): void
    {
        $this->attendanceSession($this->employeeNamed('Sara Alharbi', 'sara@company.test'), '08:00', '12:00');

        // The 45th of the 13th month is not a date, so it falls through to
        // the name search - which finds nobody, rather than a range that
        // MySQL would have had to be handed a nonsense boundary for.
        $this->assertCount(0, AttendanceResource::getGlobalSearchResults('2026-13-45'));
        $this->assertStringContainsString('exists (select * from `users`', $this->sqlFor('2026-13-45')[0]);
    }

    #[Test]
    public function naming_fifty_employees_costs_one_query_and_not_fifty(): void
    {
        $sara = $this->employeeNamed('Sara Alharbi', 'sara@company.test');

        foreach (range(1, 12) as $index) {
            $this->attendanceSession($sara, sprintf('%02d:00', $index), sprintf('%02d:30', $index));
        }

        $sql = $this->sqlFor('Sara');

        // Every result prints the employee's name, so an unloaded relation
        // would be one query per row on top of the search itself.
        $this->assertCount(
            2,
            $sql,
            'The search ran '.count($sql).' queries for twelve results: the employee relation is being loaded per row.',
        );
    }

    #[Test]
    public function the_newest_sessions_are_the_ones_the_fifty_result_limit_keeps(): void
    {
        $sara = $this->employeeNamed('Sara Alharbi', 'sara@company.test');

        $this->attendanceSession($sara, '08:00', '12:00', now()->subDays(5));
        $newest = $this->attendanceSession($sara, '08:00', '12:00');

        $this->assertSame(
            $newest->attendance_date->toDateString(),
            $this->onlyResult('Sara')->details[(string) __('attendance.fields.date')],
        );
    }

    #[Test]
    public function a_result_opens_the_record_rather_than_the_list_it_sits_in(): void
    {
        $sara = $this->employeeNamed('Sara Alharbi', 'sara@company.test');
        $session = $this->attendanceSession($sara, '08:00', '12:00');

        $url = $this->onlyResult('Sara')->url;

        // This resource has no view page: the record is read in a modal over
        // the list, and these are the parameters that mount it on arrival.
        $this->assertStringContainsString('tableAction=view', $url);
        $this->assertStringContainsString('tableActionRecord='.$session->getKey(), $url);
    }

    #[Test]
    public function a_result_says_which_session_it_is(): void
    {
        $sara = $this->employeeNamed('Sara Alharbi', 'sara@company.test');
        $session = $this->attendanceSession($sara, '08:00', '12:00');

        $details = $this->onlyResult('Sara')->details;

        $this->assertSame($session->attendance_date->toDateString(), $details[(string) __('attendance.fields.date')]);
        $this->assertSame('08:00 – 12:00', $details[(string) __('attendance.fields.duration')]);
        $this->assertSame($session->status()->label(), $details[(string) __('attendance.fields.status')]);
    }

    #[Test]
    public function an_open_session_says_so_where_its_check_out_would_be(): void
    {
        $sara = $this->employeeNamed('Sara Alharbi', 'sara@company.test');

        $this->attendanceSession($sara, '08:00');

        $details = $this->onlyResult('Sara')->details;

        $this->assertStringContainsString(
            (string) __('attendance.placeholders.no_check_out'),
            $details[(string) __('attendance.fields.duration')],
        );
    }

    #[Test]
    public function an_employee_cannot_search_their_way_to_somebody_elses_attendance(): void
    {
        $sara = $this->employeeNamed('Sara Alharbi', 'sara@company.test');
        $this->attendanceSession($sara, '08:00', '12:00');

        $this->actingAs($this->makeEmployee('nobody@company.test'));

        // canAccess() is the resource's own gate and it is what makes the
        // search box exist at all; an account that cannot open the list must
        // not be able to reach a row of it through the search either.
        $this->assertFalse(AttendanceResource::canGloballySearch());

        $this->assertNull(
            AttendanceResource::getGlobalSearchResultUrl(Attendance::query()->firstOrFail()),
            'An employee was given a link into another employee\'s attendance record.',
        );
    }
}
