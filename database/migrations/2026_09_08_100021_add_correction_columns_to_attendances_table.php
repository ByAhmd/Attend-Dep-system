<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a corrected session keeps saying what the device said.
 *
 * check_in_at and check_out_at go on holding the effective moment, so every
 * screen, every total and every query keeps reading one column. What the
 * device wrote moves into original_check_in_at / original_check_out_at
 * before the corrected value is written, and the two correction ids record
 * which approved request did it. The device's coordinates, accuracy and
 * distance are never rewritten by anything: they describe the moment the
 * device recorded, and rewriting them would turn a distance into a claim
 * about a time the device never saw.
 *
 * The CHECK is the invariant that makes an archive readable: an original
 * moment may exist only where a correction id explains it. Without it a
 * NULL original beside a filled one would be ambiguous - was this moment
 * never corrected, or corrected by something that forgot to archive?
 *
 * The mutual restrict between attendances and attendance_corrections is
 * deliberate and legal on both engines: every column involved is nullable
 * and no delete rule forms a cycle. Neither a session nor the correction
 * that amended it can be removed while the other exists.
 *
 * ON THE LIVE DATA: four ADD COLUMN and one CHECK. Every existing row -
 * twenty-two here, and the production table - takes NULL in all four
 * columns, which satisfies the CHECK trivially, and reads afterwards
 * exactly as it read before: a session with no correction id is a session
 * the device recorded whole. open_attendance_date is not named and no index
 * is rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dateTime('original_check_in_at')->nullable()->after('check_in_distance_from_company');
            $table->dateTime('original_check_out_at')->nullable()->after('check_out_distance_from_company');

            $table->foreignId('check_in_correction_id')
                ->nullable()
                ->after('original_check_out_at')
                ->constrained('attendance_corrections')
                ->restrictOnDelete();

            $table->foreignId('check_out_correction_id')
                ->nullable()
                ->after('check_in_correction_id')
                ->constrained('attendance_corrections')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE attendances ADD CONSTRAINT attendances_original_moments_check CHECK (
                (check_in_correction_id IS NOT NULL OR original_check_in_at IS NULL)
                AND
                (check_out_correction_id IS NOT NULL OR original_check_out_at IS NULL)
            )
        SQL);
    }

    /**
     * Rolling this back DESTROYS the correction audit. Afterwards a
     * corrected check_out_at is indistinguishable from one the device
     * recorded, because the archived original and the request that
     * explained it are both gone. Nothing here can warn a reader of the
     * table about that; it is stated in the pull request instead.
     *
     * The constraint goes first, then the two foreign keys, then the four
     * columns, so a refused step never leaves the table enforcing a rule
     * about a column that is on its way out.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE attendances DROP CONSTRAINT attendances_original_moments_check');

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropForeign(['check_in_correction_id']);
            $table->dropForeign(['check_out_correction_id']);
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn([
                'original_check_in_at',
                'original_check_out_at',
                'check_in_correction_id',
                'check_out_correction_id',
            ]);
        });
    }
};
