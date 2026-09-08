<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\LeaveRequest;

/**
 * What an administrator ought to know before deciding a leave request.
 *
 * Both figures are warnings and neither is a refusal. Half a day worked
 * before going home is ordinary, and a system that refused leave because
 * somebody was at their desk that morning would be overruling the person
 * who was actually there. The one hard rule about days already spoken for
 * lives in LeaveRequestWorkflow, where a refusal belongs.
 *
 * Called once per record from the decision modal, never from a table
 * column: two queries beside one decision are nothing, and two queries per
 * row of a list are a list nobody should build.
 */
final readonly class LeaveConflicts
{
    /**
     * Days inside the request's range on which the employee actually
     * recorded a session.
     *
     * Counted over distinct attendance days rather than rows, because a day
     * with three sessions is still one day somebody came in.
     */
    public function attendedDays(LeaveRequest $request): int
    {
        return Attendance::query()
            ->forUser($request->user_id)
            ->whereBetween('attendance_date', [
                $request->starts_on->toDateString(),
                $request->ends_on->toDateString(),
            ])
            ->distinct()
            ->count('attendance_date');
    }

    /**
     * The period of other approved leave covering any of these days, as
     * "Y-m-d → Y-m-d", or null when nothing else covers them.
     *
     * Approved only: a pending request is a question, and printing one here
     * as a conflict would tell an administrator that something has been
     * agreed which has not. In ordinary use this is null, because the
     * workflow refuses an overlapping request at submission; it is read
     * anyway, because the modal's job is to state what is true of the days
     * rather than to trust that a rule held.
     */
    public function overlapSummary(LeaveRequest $request): ?string
    {
        $other = LeaveRequest::query()
            ->forUser($request->user_id)
            ->where('status', RequestStatus::Approved)
            ->whereKeyNot($request->getKey())
            ->where('starts_on', '<=', $request->ends_on->toDateString())
            ->where('ends_on', '>=', $request->starts_on->toDateString())
            ->orderBy('starts_on')
            ->orderBy('id')
            ->first();

        if (! $other instanceof LeaveRequest) {
            return null;
        }

        return $other->starts_on->toDateString().' → '.$other->ends_on->toDateString();
    }
}
