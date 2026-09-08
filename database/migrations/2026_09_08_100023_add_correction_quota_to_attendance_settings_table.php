<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many corrections one employee may ask for in a month.
 *
 * It sits beside the radius because it is the same kind of thing: a policy
 * the owner sets from a screen, not a technical ceiling like the accuracy
 * limit. Zero switches correction requests off entirely, which is why the
 * lower bound is zero and not one.
 *
 * The upper bound is 31 - one request for every day of the longest month.
 * Anything above that is not a ration, and a ration nobody can exhaust is
 * an unlimited allowance wearing a number.
 *
 * ON THE LIVE DATA: one ADD COLUMN with a default and one CHECK. The single
 * settings row takes 3 from the column default; no UPDATE runs, and nothing
 * that reads the settings row behaves differently until the workflow that
 * consults this column exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('correction_requests_per_month')
                ->default(3)
                ->after('radius_meters');
        });

        DB::statement('ALTER TABLE attendance_settings ADD CONSTRAINT attendance_settings_correction_quota_check CHECK (correction_requests_per_month BETWEEN 0 AND 31)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attendance_settings DROP CONSTRAINT attendance_settings_correction_quota_check');

        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->dropColumn('correction_requests_per_month');
        });
    }
};
