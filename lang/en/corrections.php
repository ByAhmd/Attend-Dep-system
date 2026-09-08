<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Correction requests',
        'model' => 'Correction request',
        'plural_model' => 'Correction requests',
    ],

    'sections' => [
        'request' => 'The request',
        'recorded' => 'What the device recorded',
        'requested' => 'What is being asked for',
        'decision' => 'The decision',
        'day_sessions' => 'What is recorded for that day',
    ],

    'fields' => [
        'employee' => 'Employee',
        'attendance_date' => 'Day',
        'session' => 'Session',
        'reason' => 'Reason',
        'recorded_check_in' => 'Check-in recorded',
        'recorded_check_out' => 'Check-out recorded',
        'recorded_distance' => 'Distance when recorded',
        'requested_check_in' => 'Check-in requested',
        'requested_check_out' => 'Check-out requested',
        'requested' => 'Requested',
        'note' => "Employee's note",
        'status' => 'Status',
        'submitted_at' => 'Submitted',
        'decided_by' => 'Decided by',
        'decided_at' => 'Decided',
        'decision_note' => 'Decision note',
    ],

    'placeholders' => [
        'no_device_record' => 'The device recorded nothing',
        'unchanged' => 'Unchanged',
        'no_note' => 'No note',
        'note' => 'Say briefly what happened',
    ],

    'helpers' => [
        'attendance_date' => 'The day you want corrected. This month or last month.',
        'session' => "Each request corrects one session. To correct two sessions on the same day, send two requests — each one uses part of this month's allowance.",
        'times' => 'Give at least one time. If nothing was recorded that day, give both.',
        'note' => 'Optional, but it helps the request be accepted.',
        'note_required' => 'This reason needs a short explanation.',
        'approve_consequence' => 'What the device recorded is kept exactly as it was, and the record is marked as corrected wherever it is read.',
        'reject_consequence' => 'Nothing on the attendance record changes. The employee sees your note in place of the correction they asked for.',
        'decision_note_optional' => 'Optional. The employee sees it beside the decision.',
        'decision_note_required' => 'The employee sees this instead of the correction they asked for, so say what was wrong with it.',
        'own_request' => 'A request is decided by an administrator other than the person who made it. If you are the only administrator, appoint a second one.',
    ],

    'sessions' => [
        'device_recorded' => 'Recorded from your location',
        'option' => 'Session :number',
        'none' => 'Nothing is recorded for that day.',
        'pick_date' => 'Choose the date first.',
        // The end of a session on a past day that was never checked out of.
        'still_open' => 'No check-out',
        'heading' => 'What is recorded for that day',
    ],

    'validation' => [
        'date_required' => 'Choose the day you want corrected.',
        'date_range' => 'You can only ask for a correction to this month or last month.',
        'date_future' => 'A day that has not happened yet cannot be corrected.',
        'session_required' => 'That day has more than one session. Choose the one you want corrected.',
        'time_required' => 'Propose a check-in time, a check-out time, or both.',
        'both_times_required' => 'Nothing was recorded that day, so give both a check-in and a check-out time.',
        'check_out_after_check_in' => 'The check-out time must be after the check-in time.',
        'reason_required' => 'Choose a reason.',
        'note_required' => 'This reason needs a short explanation.',
        'note_max' => 'The note is longer than allowed.',
        'decision_note_required' => 'Give a reason for the rejection: it is all the employee receives.',
    ],

    'refusals' => [
        'account_not_active' => 'Your account is not active, so a request cannot be sent.',
        'corrections_disabled' => 'Attendance correction requests are currently switched off.',
        'quota_exhausted' => "You have used this month's correction allowance. It renews at the start of the next Gregorian month.",
        'day_in_the_future' => 'A day that has not happened yet cannot be corrected.',
        'date_too_old' => 'You can only ask for a correction to this month or last month.',
        'time_missing' => 'Propose a check-in time, a check-out time, or both.',
        'incomplete_new_session' => 'Nothing was recorded that day, so give both a check-in and a check-out time.',
        'session_not_yours' => 'The session you chose is not yours, or is not on that day.',
        'request_already_pending' => 'You already have a correction awaiting review for that day.',
        'already_decided' => 'This request has already been decided. Reload the page to see the decision and who made it.',
        'requester_account_deleted' => "The requester's account has been deleted. Attendance is never written for a deleted account — restore it first if it was deleted by mistake.",
        'moment_in_the_future' => 'The requested time has not arrived yet, Riyadh time. Attendance is never recorded in the future.',
        'check_out_before_check_in' => 'The requested check-out is before the check-in.',
        'nothing_to_change' => 'The requested time is already the recorded one, so there is nothing to correct.',
        'two_open_sessions' => 'This employee already has an open session on that day, and only one open session per day is allowed.',
        'overlapping_session' => 'The requested time overlaps another session on the same day. Correct that session first, or ask for a time that does not overlap it.',
        'could_not_be_applied' => 'The correction could not be saved, and nothing changed. Try again; if it keeps happening, tell whoever runs the server.',
    ],

    'filters' => [
        'status' => 'Status',
        'employee' => 'Employee',
        'reason' => 'Reason',
        'date_range' => 'Day',
        'from' => 'From',
        'until' => 'Until',
        'deleted_employee' => 'Requests from deleted accounts',
        'deleted_employee_with' => 'With deleted accounts',
        'deleted_employee_without' => 'Without deleted accounts',
        'deleted_employee_only' => 'Only deleted accounts',
    ],

    'actions' => [
        'view' => 'Open the request',
        'approve' => 'Approve',
        'approve_heading' => 'Approve the correction for :name?',
        'approve_confirm' => 'Approve the correction',
        'reject' => 'Reject',
        'reject_heading' => 'Reject the request from :name?',
        'reject_confirm' => 'Reject the request',
        'open_attendance' => 'Open the attendance record',
    ],

    'notifications' => [
        'approved' => 'The correction for :name has been applied',
        'approved_body' => 'The record for :date now reads the corrected time, with what the device recorded beside it.',
        'rejected' => 'The request from :name has been rejected',
        'rejected_body' => 'Nothing on the attendance record changed.',
        'not_applied' => 'The correction was not applied',
    ],

    'employee' => [
        'heading_table' => 'Correction requests',
        'quota' => 'You have :remaining of :allowance correction requests left this month. The allowance renews on :date.',
        'quota_spent_heading' => 'No allowance left this month',
        'quota_spent_body' => 'You have used all :allowance correction requests this month. The allowance renews on :date.',
        'disabled_body' => 'Attendance correction requests are currently switched off.',
        'submitted' => 'Your correction request has been sent',
        'submitted_body' => ':date — awaiting a decision.',
        'modal_heading' => 'Ask for a record to be fixed',
        'modal_description' => 'Nothing on your attendance record changes until an administrator approves this.',
        'submit' => 'Send the request',
        'disabled_heading' => 'Correction requests are switched off',
        'empty_heading' => 'No correction requests',
        'empty_description' => 'If you forgot to record, or the app failed, ask for a correction from the home screen.',
    ],

    'empty' => [
        'heading' => 'Nothing is waiting for a decision',
        'description' => 'Correction requests appear here as employees send them. Clear the status filter to read the ones already decided.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'Requests to correct a recorded time, the longest wait first.',
        ],
    ],
];
