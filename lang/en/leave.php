<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Leave requests',
        'model' => 'Leave request',
        'plural_model' => 'Leave requests',
    ],

    'sections' => [
        'request' => 'The request',
        'conflicts' => 'What it runs into',
        'decision' => 'The decision',
    ],

    'fields' => [
        'employee' => 'Employee',
        'type' => 'Leave type',
        'period' => 'Period',
        'starts_on' => 'Starts',
        'ends_on' => 'Ends',
        'days' => 'Days',
        'is_exit_and_return' => 'Leaving and coming back',
        'reason' => 'Reason',
        'status' => 'Status',
        'submitted_at' => 'Submitted',
        'decided_by' => 'Decided by',
        'decided_at' => 'Decided',
        'decision_note' => 'Decision note',
        'attended_days' => 'Days with recorded attendance',
        'overlapping_leave' => 'Other leave covering these days',
        'attachment' => 'Supporting document',
    ],

    'placeholders' => [
        'reason' => 'Write the reason for the leave',
        'no_overlap' => 'Nothing overlaps',
        'no_note' => 'No note',
        'no_attachment' => 'No document',
        'days' => 'Choose both dates',
    ],

    'helpers' => [
        'range' => 'You can request leave that started within the last 30 days, or that starts within a year from today.',
        'is_exit_and_return' => 'Hours away on the same day, not a whole day off.',
        'reason' => 'The administrator reads this before deciding.',
        'attachment' => 'Optional: one file supporting your request — a medical report or an exam timetable, say. PDF, JPEG or PNG, at most :size MB. Only the administration sees it.',
        'approve_consequence' => 'The days are recorded as agreed. It does not stop the employee checking in if they come in.',
        'reject_consequence' => 'The employee sees your note in place of the approval they asked for.',
        'decision_note_optional' => 'Optional. The employee sees it beside the decision.',
        'decision_note_required' => 'The employee sees this instead of the approval they asked for, so say why.',
    ],

    'units' => [
        'days' => '{1} :count day|[2,*] :count days',
    ],

    'summaries' => [
        'total_days' => 'Total days',
    ],

    'validation' => [
        'type_required' => 'Choose a leave type.',
        'starts_required' => 'Choose a start date.',
        'ends_required' => 'Choose an end date.',
        'ends_before_start' => 'The end date is before the start date.',
        'starts_too_early' => 'Leave that started more than 30 days ago cannot be requested here.',
        'starts_too_late' => 'Leave starting more than a year from now cannot be requested.',
        'too_long' => 'A single request may cover at most :days days.',
        'reason_required' => 'Write the reason for the leave.',
        'reason_min' => 'Write at least a sentence (:min characters).',
        'reason_max' => 'The reason is longer than allowed (:max characters).',
        'decision_note_required' => 'Give a reason for the rejection: it is all the employee receives.',
        'attachment_type' => 'Accepted files: PDF, JPEG or PNG.',
        'attachment_size' => 'The file may be at most :size MB.',
    ],

    'refusals' => [
        'account_not_active' => 'Your account is not active, so a request cannot be sent.',
        'end_before_start' => 'The end date is before the start date.',
        'date_out_of_range' => 'The start date is outside the allowed range.',
        'too_long' => 'A single request may not exceed :days days.',
        'overlapping' => 'You already have a leave request covering those days.',
        'already_decided' => 'This request has already been decided. Reload the page to see the decision and who made it.',
        'requester_account_deleted' => "The requester's account has been deleted. Restore it first if it was deleted by mistake.",
        'could_not_be_applied' => 'The decision could not be saved, and nothing changed. Try again; if it keeps happening, tell whoever runs the server.',
    ],

    'filters' => [
        'status' => 'Status',
        'employee' => 'Employee',
        'type' => 'Leave type',
        'period' => 'Period',
        'from' => 'From',
        'until' => 'Until',
        'on_leave_today' => 'On leave today',
        'deleted_employee' => 'Requests from deleted accounts',
        'deleted_employee_with' => 'With deleted accounts',
        'deleted_employee_without' => 'Without deleted accounts',
        'deleted_employee_only' => 'Only deleted accounts',
    ],

    'actions' => [
        'view' => 'Open the request',
        'approve' => 'Approve',
        'approve_heading' => 'Approve the leave for :name?',
        'approve_confirm' => 'Approve the leave',
        'reject' => 'Reject',
        'reject_heading' => 'Reject the request from :name?',
        'reject_confirm' => 'Reject the request',
    ],

    'notifications' => [
        'approved' => 'The leave for :name has been approved',
        'approved_body' => ':from to :until.',
        'rejected' => 'The request from :name has been rejected',
        'rejected_body' => 'Those days are not recorded as leave.',
        'not_applied' => 'The decision was not saved',
    ],

    'employee' => [
        'heading_table' => 'Leave requests',
        'submitted' => 'Your leave request has been sent',
        'submitted_body' => ':from to :until — awaiting a decision.',
        'modal_heading' => 'Request leave',
        'modal_description' => 'Approved days are recorded as agreed. Leave records no attendance, and it does not stop you checking in if you come in.',
        'submit' => 'Send the request',
        'attachment_open' => 'Open the document',
        'empty_heading' => 'No leave requests',
        'empty_description' => 'Ask for leave from the home screen and it will appear here with its state.',
    ],

    'empty' => [
        'heading' => 'Nothing is waiting for a decision',
        'description' => 'Leave requests appear here as employees send them. Clear the status filter to read the ones already decided.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'Leave requests, the longest wait first.',
        ],
    ],
];
