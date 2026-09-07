<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A row becomes one attendance SESSION instead of one whole day.
 *
 * An employee who leaves during the day checks out and checks in again on
 * return, so a day now holds as many sessions as the employee made. The old
 * unique index on (user_id, attendance_date) forbade exactly that, and is
 * replaced by two things:
 *
 *  - a plain index on the same two columns, which is what the application
 *    actually asks the table for (one employee's day, or a date range);
 *  - open_attendance_date, a virtual column carrying attendance_date only
 *    while the session is open, with a unique index on
 *    (user_id, open_attendance_date).
 *
 * The generated column is the invariant. NULL repeats freely inside a
 * unique index on both MySQL 8 and MariaDB 10.4, so a closed session is
 * invisible to it, and what stays enforced by the database is precisely the
 * rule the workflow checks: at most one OPEN session per employee per day.
 * Any number of closed sessions is allowed, and a session left open on an
 * earlier day carries that earlier date, so it never blocks today.
 *
 * Nothing writes the column - the database computes it, and the model does
 * not expose it for mass assignment. Existing rows keep every value they
 * hold: this migration adds an index, a derived column and an index on it,
 * and rewrites no data.
 *
 * The plain index is created before the unique one is dropped. The unique
 * index is what the user_id foreign key relies on for its own lookups, and
 * dropping it while it is the only index leading with user_id would be
 * refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->index(['user_id', 'attendance_date']);
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'attendance_date']);
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->date('open_attendance_date')
                ->nullable()
                ->virtualAs('IF(check_out_at IS NULL, attendance_date, NULL)');
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->unique(['user_id', 'open_attendance_date'], 'attendances_one_open_session_per_day');
        });
    }

    /**
     * Restoring the old rule fails - correctly - as soon as the feature has
     * been used: an employee who recorded two sessions on one day has two
     * rows that the unique index on (user_id, attendance_date) cannot both
     * hold, and the database refuses to create it. Rolling back therefore
     * means deciding first what becomes of those extra sessions. There is
     * no honest automatic answer, and inventing one here would silently
     * delete recorded attendance.
     *
     * The old index is restored FIRST, before anything is taken away.
     * MySQL and MariaDB do not roll back DDL, so a step that fails halfway
     * through leaves what ran before it in place: doing the drops first
     * would end a refused rollback with neither rule on the table, which is
     * a production database enforcing nothing. This order fails with the
     * table exactly as it was.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->unique(['user_id', 'attendance_date']);
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropUnique('attendances_one_open_session_per_day');
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn('open_attendance_date');
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'attendance_date']);
        });
    }
};
