<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a correction request was refused - when it was sent, or when somebody
 * tried to apply it.
 *
 * One case per rule, so the employee always reads a sentence written for
 * their situation and a test can assert which rule fired instead of parsing
 * a message. The cases split into two groups by where they are raised:
 * everything down to RequestAlreadyPending is decided while the employee is
 * filling the form in; everything after it is decided when an administrator
 * presses Approve, against a day that may have changed in between.
 *
 * There is no label(): these are refusals an employee reads once, never a
 * value stored in a column or offered in a select, so message() is the only
 * thing to resolve and there is nothing to put in a dropdown.
 */
enum CorrectionRefusalReason: string
{
    case AccountNotActive = 'account_not_active';
    case CorrectionsDisabled = 'corrections_disabled';
    case QuotaExhausted = 'quota_exhausted';
    case DayInTheFuture = 'day_in_the_future';
    case DateTooOld = 'date_too_old';
    case TimeMissing = 'time_missing';
    case IncompleteNewSession = 'incomplete_new_session';
    case SessionNotYours = 'session_not_yours';
    case RequestAlreadyPending = 'request_already_pending';

    case AlreadyDecided = 'already_decided';
    case RequesterAccountDeleted = 'requester_account_deleted';
    case MomentInTheFuture = 'moment_in_the_future';
    case CheckOutBeforeCheckIn = 'check_out_before_check_in';
    case NothingToChange = 'nothing_to_change';
    case TwoOpenSessions = 'two_open_sessions';
    case OverlappingSession = 'overlapping_session';
    case CouldNotBeApplied = 'could_not_be_applied';

    public function message(): string
    {
        return __('corrections.refusals.'.$this->value);
    }
}
