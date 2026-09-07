<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presence pings - where a checked-in employee's phone reported itself to be
 * while the attendance page was open.
 *
 * Supporting evidence, never proof: a browser reports a position only while
 * its page is open and the device awake, so a stretch with no pings says
 * nothing at all about where the employee was. What the table can show is
 * the opposite - that someone who checked in was still inside the radius at
 * 10:20 - and it can show a position outside it, which is a question worth
 * asking rather than an answer.
 *
 * Append-only, hence created_at without updated_at: a ping is one
 * observation at one instant, and an observation that can be edited is not
 * one. Every value in a row was computed by the server from the three
 * numbers the browser sent; distance_from_company and is_inside in
 * particular are never taken from the device.
 *
 * attendance_id cascades. A ping is a fragment of the session it annotates
 * and means nothing without it, so it belongs to that row rather than
 * standing beside it. Nothing in the application deletes an attendance
 * record - the policy denies it and no screen offers it - so this rule only
 * decides what happens if a row is ever removed by hand: its pings go with
 * it instead of surviving as observations about a session nobody can look
 * up. Restrict would reach the same end through a foreign-key error whose
 * only remedy is to delete the pings first.
 *
 * The indexes are declared before the foreign keys on purpose. MySQL and
 * MariaDB add an index of their own for a foreign key only when no existing
 * index already leads with that column, so declaring ours first leaves one
 * index per access path instead of two: (user_id, created_at) answers "this
 * employee's pings, newest first" and covers the user_id key, and
 * (attendance_id) answers "this session's pings" and covers that key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presence_pings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id');
            $table->foreignId('attendance_id');

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 8, 2);

            // Not nullable, unlike the rejection audit: a ping is only ever
            // recorded while a session is open, which cannot happen before
            // the company location has been configured, so there is always
            // a centre to measure from.
            $table->decimal('distance_from_company', 10, 2);
            $table->boolean('is_inside');

            $table->dateTime('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('attendance_id');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('attendance_id')->references('id')->on('attendances')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE presence_pings ADD CONSTRAINT presence_pings_coordinates_check CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)');

        // A negative accuracy or a negative distance is not a poor reading,
        // it is a corrupt one.
        DB::statement('ALTER TABLE presence_pings ADD CONSTRAINT presence_pings_measurements_check CHECK (accuracy >= 0 AND distance_from_company >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('presence_pings');
    }
};
