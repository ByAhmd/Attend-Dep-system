<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company's attendance configuration - one row.
 *
 * Latitude and longitude are nullable because a fresh installation has no
 * location yet; the workflow refuses attendance until an administrator sets
 * one. decimal(10,7) resolves to about a centimetre, which is far finer than
 * any phone reports. The radius defaults to 150 metres, the figure fixed by
 * the brief; the constant in config/attendance.php carries the same value
 * for the row the application creates itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table): void {
            // Not auto-incrementing: MySQL forbids a CHECK constraint on an
            // auto-increment column, and the model always writes id 1.
            $table->unsignedTinyInteger('id')->primary();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('radius_meters')->default(150);
            $table->timestamps();
        });

        // Single-row table: the model reads and writes id 1 only.
        DB::statement('ALTER TABLE attendance_settings ADD CONSTRAINT attendance_settings_single_row CHECK (id = 1)');
        DB::statement('ALTER TABLE attendance_settings ADD CONSTRAINT attendance_settings_radius_check CHECK (radius_meters > 0)');
        DB::statement('ALTER TABLE attendance_settings ADD CONSTRAINT attendance_settings_latitude_check CHECK (latitude BETWEEN -90 AND 90)');
        DB::statement('ALTER TABLE attendance_settings ADD CONSTRAINT attendance_settings_longitude_check CHECK (longitude BETWEEN -180 AND 180)');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
