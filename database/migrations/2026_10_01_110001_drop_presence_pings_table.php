<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The presence-ping feature is removed, table and all, by the owner's
 * decision.
 *
 * The pings were the product's one uneasy feature: they collected the
 * most sensitive data in the system - an employee's position every five
 * minutes for a whole shift - while the product's own rules forbade them
 * from proving anything ("supporting evidence, never proof of absence; a
 * gap means nothing on its own"). A collection that heavy in exchange for
 * evidence that light was a poor trade, and it was also the one unbounded
 * table and the one thing draining employees' batteries all day. The
 * check-in and check-out geofence - the evidence this product actually
 * stands on - is untouched.
 *
 * ON THE LIVE DATA: this DROPs the stored pings, deliberately. They are
 * the one kind of row in this product that may be destroyed, precisely
 * because they prove nothing; the attendance evidence they annotated
 * remains, and the last pre-removal `app:backup` set holds the final copy
 * for anyone who ever wants the history.
 *
 * down() restores the empty table exactly as 2026_09_07_100010 built it,
 * so a rollback leaves a schema the old code would recognise - but not
 * the rows, which no migration can bring back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('presence_pings');
    }

    public function down(): void
    {
        Schema::create('presence_pings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id');
            $table->foreignId('attendance_id');

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 8, 2);
            $table->decimal('distance_from_company', 10, 2);
            $table->boolean('is_inside');

            $table->dateTime('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('attendance_id');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('attendance_id')->references('id')->on('attendances')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE presence_pings ADD CONSTRAINT presence_pings_coordinates_check CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)');
        DB::statement('ALTER TABLE presence_pings ADD CONSTRAINT presence_pings_measurements_check CHECK (accuracy >= 0 AND distance_from_company >= 0)');
    }
};
