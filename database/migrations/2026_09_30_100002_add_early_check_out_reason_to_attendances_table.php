<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why a session was closed before the end of the working day.
 *
 * A check-out before work_ends_at is accepted only together with a reason
 * the employee chose, and that reason is kept on the session it closed - it
 * describes one departure, not the person. The note is the employee's own
 * words and is optional; the reason is not.
 *
 * The three CHECKs keep the pair honest whatever writes the row: the reason
 * is one of the enum's words, a note cannot exist without a reason, and a
 * reason cannot exist without the check-out it explains - a reason on an
 * open session would be a claim about a departure that has not happened.
 *
 * Nothing marks the on-time rows: NULL here means "left at or after the end
 * of the working day, or the row predates this rule", and both read as
 * "nothing to explain".
 *
 * ON THE LIVE DATA: two nullable ADD COLUMNs and three CHECKs. Every
 * existing row satisfies all three with NULLs; no UPDATE runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->string('early_check_out_reason', 30)
                ->nullable()
                ->after('check_out_distance_from_company');

            $table->string('early_check_out_note', 500)
                ->nullable()
                ->after('early_check_out_reason');
        });

        // Mirrors App\Enums\EarlyCheckOutReason, as the users table mirrors
        // its role and status enums.
        DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_early_reason_known_check CHECK (early_check_out_reason IS NULL OR early_check_out_reason IN ('sick', 'personal_errand', 'work_assignment', 'other'))");

        DB::statement('ALTER TABLE attendances ADD CONSTRAINT attendances_early_note_needs_reason_check CHECK (early_check_out_note IS NULL OR early_check_out_reason IS NOT NULL)');

        DB::statement('ALTER TABLE attendances ADD CONSTRAINT attendances_early_reason_needs_check_out_check CHECK (early_check_out_reason IS NULL OR check_out_at IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attendances DROP CONSTRAINT attendances_early_reason_needs_check_out_check');
        DB::statement('ALTER TABLE attendances DROP CONSTRAINT attendances_early_note_needs_reason_check');
        DB::statement('ALTER TABLE attendances DROP CONSTRAINT attendances_early_reason_known_check');

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn(['early_check_out_reason', 'early_check_out_note']);
        });
    }
};
