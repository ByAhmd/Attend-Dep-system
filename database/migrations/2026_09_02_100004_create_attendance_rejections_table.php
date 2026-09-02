<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refused check-ins and check-outs - the audit trail of location failures.
 *
 * Append-only, hence created_at without updated_at. distance_from_company is
 * nullable for the case where the company location itself was not yet set.
 * The CHECK constraints mirror the AttendanceAction and
 * AttendanceRejectionReason enums.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_rejections', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('action', 20);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 8, 2);
            $table->decimal('distance_from_company', 10, 2)->nullable();
            $table->string('reason', 40);

            $table->dateTime('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE attendance_rejections ADD CONSTRAINT attendance_rejections_action_check CHECK (action IN ('check_in', 'check_out'))");
        DB::statement(<<<'SQL'
            ALTER TABLE attendance_rejections ADD CONSTRAINT attendance_rejections_reason_check CHECK (reason IN (
                'location_not_configured', 'inactive_account', 'already_checked_in', 'not_checked_in',
                'already_checked_out', 'insufficient_accuracy', 'outside_allowed_area'
            ))
        SQL);
        DB::statement('ALTER TABLE attendance_rejections ADD CONSTRAINT attendance_rejections_coordinates_check CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_rejections');
    }
};
