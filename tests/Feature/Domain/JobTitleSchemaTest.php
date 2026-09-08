<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\EmploymentType;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * What the columns describing a person refuse on their own.
 *
 * The forms enforce these rules too, and say something readable when they
 * do. This is the layer underneath: a title with a blank half, a second
 * title under a name somebody already used, an employment type nobody has
 * heard of, or a title pulled out from under the people wearing it are all
 * refused by the table, whatever writes to it.
 *
 * The foreign key is the one worth pinning hardest. DeleteJobTitleAction is
 * built as three layers with "the database refuses it" underneath the other
 * two, so if this test ever goes green for the wrong reason, the layer that
 * catches the race between rendering a modal and clicking its button has
 * quietly stopped existing.
 */
final class JobTitleSchemaTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    #[Test]
    public function a_title_with_no_arabic_name_is_refused(): void
    {
        $this->expectException(QueryException::class);

        DB::table('job_titles')->insert([
            'name_ar' => '',
            'name_en' => 'Marketing',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function a_title_with_no_english_name_is_refused(): void
    {
        $this->expectException(QueryException::class);

        DB::table('job_titles')->insert([
            'name_ar' => 'التسويق',
            'name_en' => '',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function the_same_arabic_name_cannot_be_used_twice(): void
    {
        $this->makeJobTitle('التسويق', 'Marketing');

        $this->expectException(UniqueConstraintViolationException::class);

        // A different English name, so only the Arabic half collides.
        $this->makeJobTitle('التسويق', 'Sales');
    }

    #[Test]
    public function the_same_english_name_cannot_be_used_twice(): void
    {
        $this->makeJobTitle('التسويق', 'Marketing');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeJobTitle('المبيعات', 'Marketing');
    }

    #[Test]
    public function an_employment_type_the_enum_does_not_know_is_refused(): void
    {
        $employee = $this->makeEmployee();

        // There are two ways to be engaged here and no third. "Contractor"
        // is not one of them, in the enum or in the table.
        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $employee->id)->update(['employment_type' => 'contractor']);
    }

    #[Test]
    public function an_account_created_without_one_is_an_ordinary_employee(): void
    {
        $employee = $this->makeEmployee();

        // The column default reaches the row; the model's own default is
        // what puts the same answer on the instance that wrote it, so a
        // caption composed before any round trip reads the same as one
        // composed after.
        $this->assertSame(EmploymentType::Employee, $employee->employment_type);
        $this->assertNull($employee->positionLabel());
        $this->assertSame(EmploymentType::Employee, $employee->fresh()?->employment_type);
        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'employment_type' => EmploymentType::Employee->value,
        ]);
    }

    #[Test]
    public function a_title_somebody_holds_cannot_be_deleted(): void
    {
        $title = $this->makeJobTitle();
        $employee = $this->makeEmployee();
        $employee->forceFill(['job_title_id' => $title->id])->save();

        try {
            DB::table('job_titles')->where('id', $title->id)->delete();

            $this->fail('A job title an account holds was deleted.');
        } catch (QueryException) {
            // users.job_title_id restricts the delete, which is what makes
            // "retire it instead" the honest answer rather than a policy
            // the interface merely hopes nobody works around.
        }

        $this->assertDatabaseHas('job_titles', ['id' => $title->id]);
        $this->assertSame($title->id, $employee->fresh()?->job_title_id);
    }

    #[Test]
    public function a_title_nobody_holds_is_deleted_without_argument(): void
    {
        $held = $this->makeJobTitle('التسويق', 'Marketing');
        $free = $this->makeJobTitle('المبيعات', 'Sales');

        $this->makeEmployee()->forceFill(['job_title_id' => $held->id])->save();

        $free->delete();

        $this->assertDatabaseMissing('job_titles', ['id' => $free->id]);
        $this->assertDatabaseHas('job_titles', ['id' => $held->id]);
    }

    #[Test]
    public function an_account_cannot_point_at_a_title_that_does_not_exist(): void
    {
        $employee = $this->makeEmployee();

        $this->assertSame(0, JobTitle::query()->count());

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $employee->id)->update(['job_title_id' => 999]);
    }

    #[Test]
    public function an_account_may_be_deleted_while_it_holds_a_title(): void
    {
        $title = $this->makeJobTitle();
        $employee = $this->makeEmployee();
        $employee->forceFill(['job_title_id' => $title->id])->save();

        // The restriction runs one way only: a title is protected from the
        // people wearing it, never the other way round. Accounts are soft
        // deleted here, and a soft delete must not be refused by a column
        // that only describes them.
        $employee->delete();

        $this->assertSoftDeleted('users', ['id' => $employee->id]);
        $this->assertSame(1, User::withTrashed()->where('job_title_id', $title->id)->count());
    }
}
