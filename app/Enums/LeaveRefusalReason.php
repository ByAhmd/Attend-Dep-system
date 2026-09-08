<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\LeaveRequest;

/**
 * Why a leave request was refused by the system - which is a different
 * thing from an administrator saying no.
 *
 * A rejection is a decision about the request and is recorded with the
 * decider's name and note. A refusal here is the system declining to accept
 * the request at all, because the dates cannot be held or the account
 * cannot make one; nothing is written and nobody is blamed.
 *
 * No label(), for the same reason as CorrectionRefusalReason: these are
 * sentences an employee reads once, never a stored value or a select option.
 */
enum LeaveRefusalReason: string
{
    case AccountNotActive = 'account_not_active';
    case EndBeforeStart = 'end_before_start';
    case DateOutOfRange = 'date_out_of_range';
    case TooLong = 'too_long';
    case Overlapping = 'overlapping';
    case AlreadyDecided = 'already_decided';
    case RequesterAccountDeleted = 'requester_account_deleted';
    case CouldNotBeApplied = 'could_not_be_applied';

    /**
     * The sentence the employee reads.
     *
     * The maximum span is substituted for every case, not only TooLong: the
     * figure lives on LeaveRequest and passing it unconditionally means one
     * code path here and no case that can be added later carrying a
     * placeholder nothing fills. An unused replacement costs nothing.
     */
    public function message(): string
    {
        return __('leave.refusals.'.$this->value, ['days' => (string) LeaveRequest::MAX_DAYS]);
    }
}
