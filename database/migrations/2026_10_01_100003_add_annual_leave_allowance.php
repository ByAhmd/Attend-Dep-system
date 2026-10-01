<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The annual leave allowance: one company-wide figure, and a per-employee
 * override for the accounts whose contract says otherwise.
 *
 * The default is 21 working days - the Saudi labour law minimum - and
 * NULL on the override means "the company figure", which is why the
 * override is the one nullable allowance in the schema. The balance
 * itself is never a column: it is derived from approved annual leave in
 * the current Gregorian year, so there is nothing to reset, no stored
 * counter and no cron - the same shape as the correction quota.
 *
 * ON THE LIVE DATA: two ADD COLUMNs (one with a default, one nullable)
 * and two CHECKs. Every existing row satisfies both; no UPDATE runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('annual_leave_days')
                ->default(21)
                ->after('weekend_days');
        });

        DB::statement('ALTER TABLE attendance_settings ADD CONSTRAINT attendance_settings_annual_leave_check CHECK (annual_leave_days BETWEEN 0 AND 365)');

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('annual_leave_override')
                ->nullable()
                ->after('job_title_id');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_annual_leave_override_check CHECK (annual_leave_override IS NULL OR annual_leave_override BETWEEN 0 AND 365)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_annual_leave_override_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('annual_leave_override');
        });

        DB::statement('ALTER TABLE attendance_settings DROP CONSTRAINT attendance_settings_annual_leave_check');

        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->dropColumn('annual_leave_days');
        });
    }
};
