<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An employee has filed a request, and the row is on the disk.
 *
 * Dispatched by the two workflow services after the write has committed,
 * never from inside the transaction. Who is told about it is not the
 * workflow's business - it decides and records, and this is it saying what
 * it did.
 *
 * Carries the model rather than an id: every listener wants the row, and an
 * id would mean each of them fetching it again.
 */
final readonly class RequestSubmitted
{
    use Dispatchable;

    public function __construct(public AttendanceCorrection|LeaveRequest $request) {}
}
