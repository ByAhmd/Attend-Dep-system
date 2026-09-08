<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\EmploymentType;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\JobTitles\Pages\CreateJobTitle;
use App\Filament\Resources\JobTitles\Pages\EditJobTitle;
use App\Filament\Resources\JobTitles\Pages\ListJobTitles;
use App\Models\JobTitle;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * JobTitleResource: the one list in this panel the owner extends
 * themselves.
 *
 * Two things are worth proving here and the rest follows from them. The
 * first is that a title survives the language switch: both names are
 * required, each is unique in its own right, and what a reader sees is
 * their own. The second is that a title somebody holds cannot be deleted -
 * which the foreign key already guarantees, and JobTitleSchemaTest already
 * proves - in a way that explains itself instead of arriving as a database
 * error. This file tests the layers above the constraint: the modal that
 * counts the holders, the submit button that is not drawn, and the second
 * look the delete takes under the click.
 */
final class JobTitleResourceTest extends TestCase
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
     * How many accounts hold the title, and the words the refusal must use
     * for that number. Arabic needs four shapes; English needs two, and the
     * singular is a word rather than a digit.
     *
     * @return array<string, array{int, string}>
     */
    public static function arabicHolderCounts(): array
    {
        return [
            'one holder' => [1, 'حساب واحد'],
            'two holders' => [2, 'حسابان'],
            'a few holders' => [3, '3 حسابات'],
            'many holders' => [11, '11 حسابًا'],
        ];
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function englishHolderCounts(): array
    {
        return [
            'one holder' => [1, 'One account holds this title'],
            'two holders' => [2, '2 accounts hold this title'],
            'a few holders' => [3, '3 accounts hold this title'],
            'many holders' => [11, '11 accounts hold this title'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function holders(JobTitle $title, int $count, array $attributes = []): void
    {
        User::factory()->count($count)->create([...$attributes, 'job_title_id' => $title->getKey()]);
    }

    #[Test]
    public function the_three_pages_render(): void
    {
        $title = $this->makeJobTitle();

        Livewire::test(ListJobTitles::class)->assertOk();
        Livewire::test(CreateJobTitle::class)->assertOk();
        Livewire::test(EditJobTitle::class, ['record' => $title->getRouteKey()])->assertOk();
    }

    #[Test]
    public function the_list_shows_both_names_and_how_many_accounts_hold_each_title(): void
    {
        $marketing = $this->makeJobTitle('التسويق', 'Marketing');
        $finance = $this->makeJobTitle('المالية', 'Finance');

        $this->holders($marketing, 2);

        Livewire::test(ListJobTitles::class)
            ->assertCanSeeTableRecords([$marketing, $finance])
            ->assertTableColumnStateSet('name_ar', 'التسويق', $marketing)
            ->assertTableColumnHasDescription('name_ar', 'Marketing', $marketing)
            ->assertTableColumnStateSet('users_count', 2, $marketing)
            ->assertTableColumnStateSet('users_count', 0, $finance)
            ->assertTableColumnFormattedStateSet('is_active', __('job_titles.badges.active'), $marketing);
    }

    #[Test]
    public function a_title_is_created_with_a_name_in_each_language(): void
    {
        Livewire::test(CreateJobTitle::class)
            ->fillForm([
                'name_ar' => 'هندسة البرمجيات',
                'name_en' => 'Software Engineering',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $title = JobTitle::query()->where('name_en', 'Software Engineering')->sole();

        $this->assertSame('هندسة البرمجيات', $title->name_ar);
        $this->assertTrue($title->is_active);
    }

    #[Test]
    public function neither_name_may_be_left_out(): void
    {
        Livewire::test(CreateJobTitle::class)
            ->fillForm(['name_ar' => '', 'name_en' => ''])
            ->call('create')
            ->assertHasFormErrors([
                'name_ar' => 'required',
                'name_en' => 'required',
            ]);

        $this->assertSame(0, JobTitle::query()->count());
    }

    #[Test]
    public function each_name_is_unique_in_its_own_language_and_the_message_says_which(): void
    {
        $this->makeJobTitle('التسويق', 'Marketing');

        Livewire::test(CreateJobTitle::class)
            ->fillForm(['name_ar' => 'التسويق', 'name_en' => 'Sales'])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique'])
            ->assertHasNoFormErrors(['name_en'])
            ->assertSee(__('job_titles.validation.name_ar_unique'));

        Livewire::test(CreateJobTitle::class)
            ->fillForm(['name_ar' => 'المبيعات', 'name_en' => 'Marketing'])
            ->call('create')
            ->assertHasFormErrors(['name_en' => 'unique'])
            ->assertHasNoFormErrors(['name_ar'])
            ->assertSee(__('job_titles.validation.name_en_unique'));

        $this->assertSame(1, JobTitle::query()->count());
    }

    #[Test]
    public function the_unique_messages_are_written_in_english_too(): void
    {
        App::setLocale('en');

        $this->makeJobTitle('التسويق', 'Marketing');

        Livewire::test(CreateJobTitle::class)
            ->fillForm(['name_ar' => 'التسويق', 'name_en' => 'Sales'])
            ->call('create')
            ->assertSee('A title with this Arabic name already exists.');
    }

    #[Test]
    public function renaming_a_title_renames_it_everywhere_it_is_printed(): void
    {
        $title = $this->makeJobTitle('التسويق', 'Marketing');
        $employee = $this->makeEmployee();
        $employee->forceFill(['job_title_id' => $title->getKey()])->save();

        Livewire::test(EditJobTitle::class, ['record' => $title->getRouteKey()])
            ->fillForm(['name_ar' => 'التسويق الرقمي', 'name_en' => 'Digital Marketing'])
            ->call('save')
            ->assertHasNoFormErrors();

        // Composed and never stored, so one edit moves every screen that
        // prints the person's position.
        $this->assertSame('التسويق الرقمي', $employee->fresh()?->positionLabel());
    }

    #[Test]
    public function a_title_is_read_in_the_language_of_whoever_is_reading(): void
    {
        $title = $this->makeJobTitle('التسويق', 'Marketing');
        $intern = $this->makeEmployee();
        $intern->forceFill([
            'job_title_id' => $title->getKey(),
            'employment_type' => EmploymentType::Intern,
        ])->save();

        $this->assertSame('التسويق', $title->displayName());
        $this->assertSame(['التسويق'], array_values(JobTitle::selectableOptions()));
        $this->assertSame('متدرّب التسويق', $intern->positionLabel());

        App::setLocale('en');

        $this->assertSame('Marketing', $title->displayName());
        $this->assertSame(['Marketing'], array_values(JobTitle::selectableOptions()));
        $this->assertSame('Marketing intern', $intern->fresh()?->positionLabel());
    }

    #[Test]
    public function a_retired_title_is_not_offered_when_an_account_is_created(): void
    {
        $active = $this->makeJobTitle('التسويق', 'Marketing');
        $retired = $this->makeJobTitle('البريد', 'Post room', active: false);

        $this->assertSame([$active->getKey() => 'التسويق'], JobTitle::selectableOptions());

        Livewire::test(CreateEmployee::class)
            ->assertFormFieldExists('job_title_id', function (Select $field) use ($active, $retired): bool {
                $options = $field->getOptions();

                return array_key_exists($active->getKey(), $options)
                    && ! array_key_exists($retired->getKey(), $options);
            });
    }

    #[Test]
    public function a_retired_title_stays_on_the_account_that_already_holds_it(): void
    {
        $title = $this->makeJobTitle('البريد', 'Post room');
        $employee = $this->makeEmployee();
        $employee->forceFill(['job_title_id' => $title->getKey()])->save();

        $title->forceFill(['is_active' => false])->save();

        $this->assertSame('البريد', $employee->fresh()?->positionLabel());

        // The employee's own form still offers it, so saving a change of
        // name does not silently blank the field of somebody wearing it.
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldExists('job_title_id', fn (Select $field): bool => array_key_exists(
                $title->getKey(),
                $field->getOptions(),
            ))
            ->assertSchemaStateSet(['job_title_id' => $title->getKey()]);
    }

    #[Test]
    public function retiring_a_title_from_the_list_keeps_every_account_that_holds_it(): void
    {
        $title = $this->makeJobTitle('التسويق', 'Marketing');
        $this->holders($title, 2);

        Livewire::test(ListJobTitles::class)
            ->mountTableAction('toggleActive', $title)
            ->assertMountedActionModalSee(__('job_titles.actions.toggle_retire_description'))
            ->callMountedTableAction()
            ->assertNotified(__('job_titles.notifications.retired', ['name' => 'التسويق']));

        $this->assertFalse($title->fresh()?->is_active);
        $this->assertSame(2, $title->users()->count());
        $this->assertSame([], JobTitle::selectableOptions());
    }

    #[Test]
    public function a_retired_title_can_be_brought_back(): void
    {
        $title = $this->makeJobTitle('التسويق', 'Marketing', active: false);

        Livewire::test(ListJobTitles::class)
            ->mountTableAction('toggleActive', $title)
            ->assertMountedActionModalSee(__('job_titles.actions.toggle_activate_description'))
            ->callMountedTableAction()
            ->assertNotified(__('job_titles.notifications.activated', ['name' => 'التسويق']));

        $this->assertTrue($title->fresh()?->is_active);
        $this->assertSame([$title->getKey() => 'التسويق'], JobTitle::selectableOptions());
    }

    #[Test]
    public function the_edit_page_offers_the_same_two_acts_as_the_row(): void
    {
        $title = $this->makeJobTitle();

        Livewire::test(EditJobTitle::class, ['record' => $title->getRouteKey()])
            ->assertActionVisible('toggleActive')
            ->assertActionVisible('delete')
            ->callAction('toggleActive')
            ->assertNotified();

        $this->assertFalse($title->fresh()?->is_active);
    }

    #[Test]
    #[DataProvider('arabicHolderCounts')]
    public function the_refusal_to_delete_a_held_title_counts_its_holders_in_arabic(int $count, string $expected): void
    {
        $title = $this->makeJobTitle('التسويق', 'Marketing');
        $this->holders($title, $count);

        Livewire::test(ListJobTitles::class)
            ->mountTableAction('delete', $title)
            ->assertMountedActionModalSee($expected)
            // No submit button at all: a disabled one invites a second
            // press, and a person who presses twice concludes the screen is
            // broken.
            ->assertMountedActionModalDontSee(__('job_titles.actions.delete_confirm'))
            ->assertMountedActionModalSee(__('job_titles.actions.show_holders'));

        $this->assertTrue(JobTitle::query()->whereKey($title->getKey())->exists());
    }

    #[Test]
    #[DataProvider('englishHolderCounts')]
    public function the_refusal_to_delete_a_held_title_counts_its_holders_in_english(int $count, string $expected): void
    {
        App::setLocale('en');

        $title = $this->makeJobTitle('التسويق', 'Marketing');
        $this->holders($title, $count);

        Livewire::test(ListJobTitles::class)
            ->mountTableAction('delete', $title)
            ->assertMountedActionModalSee($expected)
            ->assertMountedActionModalDontSee(__('job_titles.actions.delete_confirm'));
    }

    #[Test]
    public function a_title_nobody_holds_is_deleted_from_a_modal_that_says_so(): void
    {
        $title = $this->makeJobTitle('البريد', 'Post room');

        Livewire::test(ListJobTitles::class)
            ->mountTableAction('delete', $title)
            ->assertMountedActionModalSee(__('job_titles.actions.delete_description'))
            ->assertMountedActionModalSee(__('job_titles.actions.delete_confirm'))
            ->assertMountedActionModalDontSee(__('job_titles.actions.show_holders'))
            ->callMountedTableAction()
            ->assertNotified(__('job_titles.notifications.deleted', ['name' => 'البريد']));

        $this->assertFalse(JobTitle::query()->whereKey($title->getKey())->exists());
    }

    #[Test]
    public function the_delete_asks_again_under_the_click(): void
    {
        $title = $this->makeJobTitle('البريد', 'Post room');

        $page = Livewire::test(ListJobTitles::class)
            ->mountTableAction('delete', $title)
            ->assertMountedActionModalSee(__('job_titles.actions.delete_confirm'));

        // Somebody is given the title while the modal is open. The database
        // would refuse this delete; the reader gets a sentence instead.
        $this->holders($title, 1);

        $page->callMountedTableAction()
            ->assertNotified(__('job_titles.notifications.delete_blocked', ['name' => 'البريد']))
            // Still mounted: the modal stayed open and nothing was written.
            ->assertActionMounted(TestAction::make('delete')->table($title));

        $this->assertTrue(JobTitle::query()->whereKey($title->getKey())->exists());
    }

    #[Test]
    public function the_holder_count_links_into_the_employee_list_narrowed_to_that_title(): void
    {
        $held = $this->makeJobTitle('التسويق', 'Marketing');
        $unheld = $this->makeJobTitle('البريد', 'Post room');

        $this->holders($held, 1);

        Livewire::test(ListJobTitles::class)
            ->assertTableColumnExists('users_count', function (TextColumn $column) use ($held): bool {
                $url = urldecode((string) $column->getUrl());

                return str_starts_with($url, EmployeeResource::getUrl('index'))
                    && str_contains($url, 'filters[job_title_id][value]='.$held->getKey());
            }, $held)
            // Nothing to look at, so nothing to click.
            ->assertTableColumnExists(
                'users_count',
                fn (TextColumn $column): bool => $column->getUrl() === null,
                $unheld,
            );
    }

    #[Test]
    public function the_list_can_be_narrowed_to_the_titles_still_in_use(): void
    {
        $active = $this->makeJobTitle('التسويق', 'Marketing');
        $retired = $this->makeJobTitle('البريد', 'Post room', active: false);

        Livewire::test(ListJobTitles::class)
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$retired])
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$retired])
            ->assertCanNotSeeTableRecords([$active]);
    }

    #[Test]
    public function there_are_no_bulk_actions_on_the_list(): void
    {
        $this->makeJobTitle();

        // Titles are retired or deleted one at a time, with a modal that
        // names the one being acted on: deleteAny() is false on every
        // policy in this repository and there is no bulk action anywhere.
        Livewire::test(ListJobTitles::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->bulk());
    }

    #[Test]
    public function an_employee_is_refused_the_job_titles_over_http(): void
    {
        $this->actingAs($this->makeEmployee())->get('/admin/job-titles')->assertForbidden();
    }

    #[Test]
    public function an_administrator_reaches_the_job_titles_over_http(): void
    {
        $title = $this->makeJobTitle();

        $this->get('/admin/job-titles')->assertOk();
        $this->get('/admin/job-titles/create')->assertOk();
        $this->get('/admin/job-titles/'.$title->getRouteKey().'/edit')->assertOk();
    }
}
