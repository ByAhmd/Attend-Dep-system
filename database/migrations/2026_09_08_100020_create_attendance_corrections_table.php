<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's request to change a recorded attendance time.
 *
 * The two requested moments are TIME columns and not DATETIME. The phone
 * sends a day and a wall-clock time, and the server composes them in
 * Asia/Riyadh at approval; a TIME column cannot carry a date, so nothing
 * can smuggle one in beside the date the request already names. The cost is
 * that a session crossing midnight cannot be requested - which is a session
 * the live workflow cannot produce either, since a day's sessions are filed
 * under the day they started.
 *
 * pending_attendance_date carries attendance_date only while the request is
 * pending, and the unique index on (user_id, pending_attendance_date) is
 * therefore the rule "one open question per employee per day". NULL repeats
 * freely inside a unique index on both MySQL 8 and MariaDB 10.4, so a
 * decided request drops out of the index and the employee may ask again
 * about the same day. This is the same construction the attendances table
 * already uses for one open session per day; nothing reads or writes the
 * generated column, it exists to carry the index.
 *
 * attendance_id is nullable because the commonest request of all is about a
 * day that has no row at all - somebody who never checked in. Both user
 * keys restrict deletion, because a decision carries the name of whoever
 * made it and a request with a missing decider is not an audit trail.
 *
 * Indexes are declared before the foreign keys: MySQL and MariaDB add an
 * index of their own for a key only when no existing index already leads
 * with that column, so declaring ours first leaves one index per access
 * path rather than two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id');
            $table->foreignId('attendance_id')->nullable();

            $table->date('attendance_date');
            $table->string('reason', 40);

            $table->time('requested_check_in_time')->nullable();
            $table->time('requested_check_out_time')->nullable();

            $table->text('note')->nullable();

            $table->string('status', 20)->default('pending');
            $table->dateTime('submitted_at');

            $table->foreignId('decided_by_id')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->date('pending_attendance_date')
                ->nullable()
                ->virtualAs("IF(status = 'pending', attendance_date, NULL)");

            $table->index(['user_id', 'submitted_at']);
            $table->index(['status', 'submitted_at']);
            $table->index('attendance_id');
            $table->index('decided_by_id');

            $table->unique(['user_id', 'pending_attendance_date'], 'attendance_corrections_one_pending_per_day');

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('attendance_id')->references('id')->on('attendances')->restrictOnDelete();
            $table->foreign('decided_by_id')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_corrections ADD CONSTRAINT attendance_corrections_reason_check CHECK (reason IN (
                'forgot_to_record', 'location_problem', 'application_problem', 'connection_problem',
                'remote_work', 'external_visit', 'overtime_after_check_out'
            ))
        SQL);

        DB::statement("ALTER TABLE attendance_corrections ADD CONSTRAINT attendance_corrections_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");

        // A request that asks for nothing is not a request.
        DB::statement('ALTER TABLE attendance_corrections ADD CONSTRAINT attendance_corrections_time_requested_check CHECK (requested_check_in_time IS NOT NULL OR requested_check_out_time IS NOT NULL)');

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_corrections ADD CONSTRAINT attendance_corrections_time_order_check CHECK (
                requested_check_in_time IS NULL
                OR requested_check_out_time IS NULL
                OR requested_check_out_time >= requested_check_in_time
            )
        SQL);

        // A decision has a decider and a moment, or it has not been made.
        // decision_note is deliberately outside this rule: the service and
        // the form require one for a rejection, and an approval may
        // honestly carry none.
        DB::statement(<<<'SQL'
            ALTER TABLE attendance_corrections ADD CONSTRAINT attendance_corrections_decision_complete_check CHECK (
                (status = 'pending' AND decided_by_id IS NULL AND decided_at IS NULL)
                OR
                (status <> 'pending' AND decided_by_id IS NOT NULL AND decided_at IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
