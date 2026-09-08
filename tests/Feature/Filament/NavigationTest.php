<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\NavigationGroup;
use App\Filament\Employee\Pages\Requests;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Filament\Resources\JobTitles\JobTitleResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Support\Filament\PanelAccess;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The sidebar: what is in it, in what order, and what is deliberately not.
 *
 * The absence under test is the navigation badge. Filament rebuilds the
 * whole sidebar on every full page load, so a getNavigationBadge() on one
 * resource is a COUNT on every admin page for the life of the deployment -
 * and no functional test in this suite can see it, because a Livewire
 * component test never renders the layout. The dashboard widget carries the
 * same two figures once, on the page an administrator opens first anyway.
 */
final class NavigationTest extends TestCase
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
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return [
            'english' => ['en'],
            'arabic' => ['ar'],
        ];
    }

    /**
     * Every resource and page registered on either panel.
     *
     * @return list<class-string>
     */
    private function registeredClasses(): array
    {
        $classes = [];

        foreach ([PanelAccess::ADMIN_PANEL_ID, PanelAccess::EMPLOYEE_PANEL_ID] as $panelId) {
            $panel = Filament::getPanel($panelId);

            $classes = [...$classes, ...array_values($panel->getResources()), ...array_values($panel->getPages())];
        }

        return $classes;
    }

    #[Test]
    public function no_resource_or_page_declares_a_navigation_badge(): void
    {
        $registered = $this->registeredClasses();

        $this->assertNotSame([], $registered);

        foreach ($registered as $class) {
            $declaredBy = (new ReflectionMethod($class, 'getNavigationBadge'))->getDeclaringClass()->getName();

            $this->assertNotSame(
                $class,
                $declaredBy,
                "{$class} declares getNavigationBadge(), which costs a COUNT on every admin page load.",
            );

            $this->assertContains($declaredBy, [Resource::class, Page::class, 'Filament\\Resources\\Resource\\Concerns\\HasNavigation', 'Filament\\Pages\\Page']);
        }
    }

    #[Test]
    public function the_requests_group_sits_between_attendance_and_system(): void
    {
        // Case order is sidebar order, and the panel iterates cases().
        $this->assertSame(
            ['attendance', 'requests', 'system'],
            array_map(static fn (NavigationGroup $case): string => $case->value, NavigationGroup::cases()),
        );

        $this->assertSame(
            ['Attendance', 'Requests', 'System'],
            array_keys(Filament::getPanel(PanelAccess::ADMIN_PANEL_ID)->getNavigationGroups()),
        );
    }

    #[Test]
    #[DataProvider('locales')]
    public function the_requests_group_is_named_in_both_languages(string $locale): void
    {
        App::setLocale($locale);

        $label = NavigationGroup::Requests->getLabel();

        $this->assertNotSame('', $label);
        $this->assertStringNotContainsString('navigation.', $label);

        $this->get('/admin')
            ->assertOk()
            ->assertSee($label);
    }

    #[Test]
    public function the_three_new_resources_are_registered_on_the_admin_panel(): void
    {
        $resources = array_values(Filament::getPanel(PanelAccess::ADMIN_PANEL_ID)->getResources());

        $this->assertContains(JobTitleResource::class, $resources);
        $this->assertContains(AttendanceCorrectionResource::class, $resources);
        $this->assertContains(LeaveRequestResource::class, $resources);
    }

    #[Test]
    public function the_two_queues_stand_in_the_requests_group_and_job_titles_under_system(): void
    {
        $this->assertSame(NavigationGroup::Requests, AttendanceCorrectionResource::getNavigationGroup());
        $this->assertSame(1, AttendanceCorrectionResource::getNavigationSort());

        $this->assertSame(NavigationGroup::Requests, LeaveRequestResource::getNavigationGroup());
        $this->assertSame(2, LeaveRequestResource::getNavigationSort());

        $this->assertSame(NavigationGroup::System, JobTitleResource::getNavigationGroup());
        $this->assertSame(2, JobTitleResource::getNavigationSort());
    }

    /**
     * Two entries in one group sharing a sort do not fail: they fall back to
     * the order the panel provider happened to register them in, which is a
     * fact about a file nobody reading the sidebar can see. When the job-title
     * list took sort 2 in the System group, the settings page still held it
     * too, and the sidebar was correct only by accident.
     */
    #[Test]
    public function no_two_entries_in_a_group_compete_for_the_same_position(): void
    {
        $seen = [];

        foreach (Filament::getPanel(PanelAccess::ADMIN_PANEL_ID)->getResources() as $resource) {
            $group = $resource::getNavigationGroup();
            $key = ($group instanceof NavigationGroup ? $group->value : (string) $group).'/'.$resource::getNavigationSort();

            $this->assertArrayNotHasKey(
                $key,
                $seen,
                $resource.' and '.($seen[$key] ?? '').' share a navigation group and sort.',
            );

            $seen[$key] = $resource;
        }

        $this->assertNotSame([], $seen);
    }

    #[Test]
    public function none_of_the_three_new_resources_joins_the_global_search(): void
    {
        // Filament asks every searchable resource on each keystroke in the
        // top bar. That box answers "who" and "which day"; a title and a
        // queue are neither.
        $this->assertFalse(JobTitleResource::canGloballySearch());
        $this->assertFalse(AttendanceCorrectionResource::canGloballySearch());
        $this->assertFalse(LeaveRequestResource::canGloballySearch());
    }

    #[Test]
    public function the_employee_panel_carries_the_requests_page_and_still_shows_no_navigation(): void
    {
        $panel = Filament::getPanel(PanelAccess::EMPLOYEE_PANEL_ID);

        $this->assertContains(Requests::class, array_values($panel->getPages()));
        $this->assertFalse($panel->hasNavigation());
    }
}
