<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An administrator has approved or rejected a request, and the decision has
 * committed.
 *
 * The commit is the point. Approving a correction amends an attendance row
 * inside a transaction over a locked employee-day, and a refusal from inside
 * that transaction rolls the whole thing back and leaves the request
 * pending. Dispatching from in there would mean announcing decisions that
 * were then undone, so the two workflows dispatch this only once
 * DB::transaction() has returned - which is also the moment the row is true.
 *
 * The request carried here has been read back from the table rather than
 * assumed, so what is announced is what was written.
 */
final readonly class RequestDecided
{
    use Dispatchable;

    public function __construct(public AttendanceCorrection|LeaveRequest $request) {}
}
