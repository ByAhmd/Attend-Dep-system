<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance records - one per employee per day.
 *
 * attendance_date is the attendance period, derived by the server from the
 * check-in moment in the application timezone. The unique index on
 * (user_id, attendance_date) is the database's own guarantee against a
 * duplicate check-in: the workflow checks first, but two requests arriving
 * together are settled here.
 *
 * DATETIME rather than TIMESTAMP for the attendance moments: the values are
 * written and read in the application timezone and must never be shifted by
 * a server or session timezone setting.
 *
 * user_id restricts deletion: an employee with attendance history is
 * deactivated, never deleted, so history is never silently lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            $table->date('attendance_date');

            $table->dateTime('check_in_at');
            $table->decimal('check_in_latitude', 10, 7);
            $table->decimal('check_in_longitude', 10, 7);
            $table->decimal('check_in_accuracy', 8, 2);
            $table->decimal('check_in_distance_from_company', 10, 2);

            $table->dateTime('check_out_at')->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->decimal('check_out_accuracy', 8, 2)->nullable();
            $table->decimal('check_out_distance_from_company', 10, 2)->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'attendance_date']);
            $table->index(['attendance_date', 'check_out_at']);
        });

        DB::statement('ALTER TABLE attendances ADD CONSTRAINT attendances_check_out_after_check_in CHECK (check_out_at IS NULL OR check_out_at >= check_in_at)');
        DB::statement('ALTER TABLE attendances ADD CONSTRAINT attendances_check_in_coordinates_check CHECK (check_in_latitude BETWEEN -90 AND 90 AND check_in_longitude BETWEEN -180 AND 180)');
        DB::statement('ALTER TABLE attendances ADD CONSTRAINT attendances_check_out_coordinates_check CHECK (check_out_latitude BETWEEN -90 AND 90 AND check_out_longitude BETWEEN -180 AND 180)');

        // A check-out is recorded whole or not at all.
        DB::statement(<<<'SQL'
            ALTER TABLE attendances ADD CONSTRAINT attendances_check_out_complete CHECK (
                (check_out_at IS NULL AND check_out_latitude IS NULL AND check_out_longitude IS NULL AND check_out_accuracy IS NULL AND check_out_distance_from_company IS NULL)
                OR
                (check_out_at IS NOT NULL AND check_out_latitude IS NOT NULL AND check_out_longitude IS NOT NULL AND check_out_accuracy IS NOT NULL AND check_out_distance_from_company IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
