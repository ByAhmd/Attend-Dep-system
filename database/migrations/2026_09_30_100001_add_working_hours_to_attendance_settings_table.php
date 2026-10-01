<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The official working day: when it starts, when it ends, and how many
 * minutes after the start an arrival is still not called late.
 *
 * These sit beside the radius because they are the same kind of thing: a
 * policy the owner sets from a screen. The brief fixes the working day at
 * 09:00–17:00 with lateness marked after 09:30, so those are the column
 * defaults; the live values are edited from the admin panel.
 *
 * The grace is minutes past the start rather than a second clock time, so
 * the two things that must stay ordered - the start and the moment lateness
 * begins - cannot be saved the wrong way round. Zero is a real setting and
 * means an arrival one second past the start is already late.
 *
 * ON THE LIVE DATA: three ADD COLUMNs with defaults and two CHECKs. The
 * single settings row takes the brief's working day from the column
 * defaults; no UPDATE runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->time('work_starts_at')
                ->default('09:00:00')
                ->after('correction_requests_per_month');

            $table->time('work_ends_at')
                ->default('17:00:00')
                ->after('work_starts_at');

            $table->unsignedSmallInteger('late_grace_minutes')
                ->default(30)
                ->after('work_ends_at');
        });

        // A day that ends before it starts is not a working day; this
        // product does not model a night shift over midnight, and a pair
        // saved the wrong way round would silently call every check-out
        // early and every arrival late.
        DB::statement('ALTER TABLE attendance_settings ADD CONSTRAINT attendance_settings_working_day_ordered_check CHECK (work_ends_at > work_starts_at)');

        // Four hours of grace is already not a grace; anything above it is
        // "lateness off" wearing a number, and switching lateness off is
        // not a setting this product offers.
        DB::statement('ALTER TABLE attendance_settings ADD CONSTRAINT attendance_settings_late_grace_check CHECK (late_grace_minutes BETWEEN 0 AND 240)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attendance_settings DROP CONSTRAINT attendance_settings_late_grace_check');
        DB::statement('ALTER TABLE attendance_settings DROP CONSTRAINT attendance_settings_working_day_ordered_check');

        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->dropColumn(['work_starts_at', 'work_ends_at', 'late_grace_minutes']);
        });
    }
};
