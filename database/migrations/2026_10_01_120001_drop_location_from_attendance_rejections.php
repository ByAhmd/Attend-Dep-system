<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rejection audit stops keeping WHERE a refused attempt came from.
 *
 * A refused check-in is, by definition, a moment the person was somewhere
 * the system has no business recording - most often their home. Keeping
 * the pair of coordinates meant any administrator could put a map pin on
 * an employee's house, and the audit never needed it: the reason says what
 * went wrong, the accuracy says how blind the phone was, and the distance
 * says how far outside the line the attempt stood - all of the diagnosis,
 * none of the address. The successful check-in and check-out keep their
 * coordinates as before: those are at the company, and they are the
 * evidence this product exists to hold.
 *
 * ON THE LIVE DATA: dropping the two columns destroys every stored
 * coordinate pair, deliberately - the point is that nobody can look them
 * up, and that includes us.
 *
 * down() restores the empty columns so old code finds its schema, but
 * NOT NULL is impossible over existing rows, so they come back nullable;
 * the positions themselves no migration can resurrect, which is the
 * feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE attendance_rejections DROP CONSTRAINT attendance_rejections_coordinates_check');

        Schema::table('attendance_rejections', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_rejections', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('action');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        DB::statement('ALTER TABLE attendance_rejections ADD CONSTRAINT attendance_rejections_coordinates_check CHECK (latitude IS NULL OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');
    }
};
