<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's request for days off, and the decision on it.
 *
 * The range is inclusive at both ends, so a one-day leave has starts_on
 * equal to ends_on, and is_exit_and_return marks the case that is not a day
 * off at all - hours away and back on the same day. The reason is required
 * text and not a code: the six types say what kind of leave it is, and the
 * one thing an approver actually reads is why.
 *
 * A leave request records what was agreed. It never suppresses a check-in,
 * never creates or closes an attendance session, and never excuses a
 * missing check-out. Somebody on approved leave who comes in anyway checks
 * in normally: leave is a plan made in advance, the door is the door.
 *
 * pending_starts_on carries starts_on only while the request is pending,
 * and the unique index on it guards a DOUBLE SUBMIT - the same request
 * arriving twice from an impatient tap. Genuine overlap is a different
 * question and is refused by the service under a lock, with its own
 * sentence: neither MySQL 8 nor MariaDB 10.4 has an exclusion constraint,
 * and a unique index cannot express "these ranges must not intersect".
 *
 * The four attachment columns hold a file the employee may attach - a
 * medical note, an examination timetable - and are written together or not
 * at all. They are declared here, with the CHECK that keeps them coherent,
 * so the column that holds a stored path never exists without the name to
 * show the reader or the size and type to serve it by. The upload control,
 * the private disk and the authorised download route come later; a table
 * that grows a file reference one column at a time grows rows nobody can
 * interpret.
 *
 * Indexes precede the foreign keys so each key rides an index that already
 * answers a question the application asks, rather than one the engine adds
 * for itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id');

            $table->string('type', 30);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_exit_and_return')->default(false);
            $table->text('reason');

            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->string('attachment_mime_type', 150)->nullable();

            $table->string('status', 20)->default('pending');
            $table->dateTime('submitted_at');

            $table->foreignId('decided_by_id')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->date('pending_starts_on')
                ->nullable()
                ->virtualAs("IF(status = 'pending', starts_on, NULL)");

            $table->index(['user_id', 'starts_on']);
            $table->index(['status', 'submitted_at']);
            $table->index(['starts_on', 'ends_on']);
            $table->index('decided_by_id');

            $table->unique(['user_id', 'pending_starts_on'], 'leave_requests_one_pending_start_per_day');

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('decided_by_id')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_type_check CHECK (type IN (
                'annual', 'sick', 'exam', 'bereavement_immediate', 'bereavement_sibling', 'unpaid'
            ))
        SQL);

        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");

        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_dates_check CHECK (ends_on >= starts_on)');

        // The reason is the whole of what an approver reads.
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_reason_check CHECK (reason <> '')");

        // A decision has a decider and a moment, or it has not been made.
        DB::statement(<<<'SQL'
            ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_decision_complete_check CHECK (
                (status = 'pending' AND decided_by_id IS NULL AND decided_at IS NULL)
                OR
                (status <> 'pending' AND decided_by_id IS NOT NULL AND decided_at IS NOT NULL)
            )
        SQL);

        // An attachment is stored whole or not at all. Every branch spells
        // its NULL tests out: a CHECK is violated only when it evaluates to
        // FALSE, and a comparison against NULL evaluates to NULL, so a rule
        // written with `<>` alone would silently admit a half-written row.
        DB::statement(<<<'SQL'
            ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_attachment_complete_check CHECK (
                (attachment_path IS NULL AND attachment_name IS NULL
                 AND attachment_size IS NULL AND attachment_mime_type IS NULL)
                OR
                (attachment_path IS NOT NULL AND attachment_name IS NOT NULL
                 AND attachment_size IS NOT NULL AND attachment_mime_type IS NOT NULL
                 AND attachment_path <> '' AND attachment_name <> ''
                 AND attachment_mime_type <> '' AND attachment_size > 0)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
