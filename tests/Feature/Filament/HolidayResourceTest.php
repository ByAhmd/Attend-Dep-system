<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Holidays\Pages\CreateHoliday;
use App\Filament\Resources\Holidays\Pages\EditHoliday;
use App\Filament\Resources\Holidays\Pages\ListHolidays;
use App\Models\Holiday;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The holiday calendar screen: an administrator keeps it, an employee
 * never sees it, and the range cannot be saved the wrong way round.
 */
final class HolidayResourceTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-02 10:00:00');

        Filament::setCurrentPanel('admin');

        $this->actingAs($this->makeAdmin());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_list_shows_both_names_and_the_range(): void
    {
        $this->makeHoliday(
            CarbonImmutable::parse('2026-09-21', 'Asia/Riyadh'),
            CarbonImmutable::parse('2026-09-23', 'Asia/Riyadh'),
            ar: 'اليوم الوطني',
            en: 'National Day',
        );

        Livewire::test(ListHolidays::class)
            ->assertOk()
            ->assertSee('اليوم الوطني')
            ->assertSee('National Day')
            ->assertSee('2026-09-21')
            ->assertSee('2026-09-23');
    }

    #[Test]
    public function a_holiday_is_created_from_the_form(): void
    {
        Livewire::test(CreateHoliday::class)
            ->fillForm([
                'name_ar' => 'عيد الفطر',
                'name_en' => 'Eid al-Fitr',
                'starts_on' => '2026-03-19',
                'ends_on' => '2026-03-23',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('holidays', [
            'name_ar' => 'عيد الفطر',
            'starts_on' => '2026-03-19',
            'ends_on' => '2026-03-23',
        ]);
    }

    #[Test]
    public function a_range_saved_backwards_is_refused_in_the_forms_words(): void
    {
        Livewire::test(CreateHoliday::class)
            ->fillForm([
                'name_ar' => 'عطلة',
                'name_en' => 'Holiday',
                'starts_on' => '2026-03-23',
                'ends_on' => '2026-03-19',
            ])
            ->call('create')
            ->assertHasFormErrors(['ends_on' => 'after_or_equal'])
            ->assertSee(__('holidays.validation.range_ordered'));

        $this->assertDatabaseCount('holidays', 0);
    }

    #[Test]
    public function a_holiday_entered_wrong_is_deleted_outright(): void
    {
        $holiday = $this->makeHoliday(CarbonImmutable::parse('2026-09-21', 'Asia/Riyadh'));

        Livewire::test(EditHoliday::class, ['record' => $holiday->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseCount('holidays', 0);
    }

    #[Test]
    public function an_employee_is_refused_the_calendar_over_http(): void
    {
        $this->actingAs($this->makeEmployee());

        $this->get('/admin/holidays')->assertForbidden();
    }
}
