<?php

declare(strict_types=1);

namespace App\Services\Requests;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;

/**
 * How much is waiting for an administrator to answer.
 *
 * Both figures count only what somebody can actually act on: pending, from
 * an account that still exists. A queue that included requests from deleted
 * accounts would show a number nobody can ever bring down to zero.
 *
 * Deliberately not a container singleton and deliberately not memoised. A
 * singleton survives a whole test method, so a figure taken before a
 * fixture exists would go stale inside RefreshDatabase and produce a
 * failure whose message says nothing about the cause. Two sub-millisecond
 * counts on the (status, submitted_at) index are cheaper than that class of
 * bug.
 */
final readonly class RequestQueueMetrics
{
    public function pendingCorrections(): int
    {
        return AttendanceCorrection::query()->actionable()->count();
    }

    public function pendingLeave(): int
    {
        return LeaveRequest::query()->actionable()->count();
    }
}
