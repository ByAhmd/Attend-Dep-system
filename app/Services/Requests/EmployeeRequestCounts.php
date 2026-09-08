<?php

declare(strict_types=1);

namespace App\Services\Requests;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\User;

/**
 * How much of one employee's own asking is still unanswered.
 *
 * The counterpart of RequestQueueMetrics, which counts the same two tables
 * for the administrator reading the queue. Both are deliberately plain
 * counts and neither is memoised or bound as a singleton: a figure cached
 * for the length of a test method goes stale the moment a fixture is
 * created, and produces a failure whose message says nothing about why.
 *
 * These count the employee's own pending requests only - not what an
 * administrator can act on - so a request from a deactivated account is
 * still counted here. Its owner asked, and nobody has answered.
 */
final readonly class EmployeeRequestCounts
{
    public function pendingCorrections(User $employee): int
    {
        return AttendanceCorrection::query()->forUser($employee)->pending()->count();
    }

    public function pendingLeave(User $employee): int
    {
        return LeaveRequest::query()->forUser($employee)->pending()->count();
    }

    /**
     * Both kinds together - the one figure the tile band carries, because
     * the employee is asked to look at one screen, not to divide their
     * attention between two numbers.
     */
    public function pendingTotal(User $employee): int
    {
        return $this->pendingCorrections($employee) + $this->pendingLeave($employee);
    }
}
