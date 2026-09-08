<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Employees',
        'model' => 'Employee',
        'plural_model' => 'Employees',
    ],

    'sections' => [
        'details' => 'Employee details',
        'access' => 'Access',
    ],

    'fields' => [
        'name' => 'Name',
        'email' => 'Email',
        'password_confirmation' => 'Confirm password',
        'new_password' => 'New password',
        'role' => 'Role',
        'status' => 'Status',
        'created_at' => 'Created',
        'invitation_link' => 'Invitation link',
    ],

    'placeholders' => [
        'name' => 'Full name as it should appear in attendance records',
        'email' => 'name@company.com',
    ],

    'helpers' => [
        'password' => 'At least 8 characters. Share it with the employee; only an administrator can reset it.',
        'status' => 'Inactive employees cannot sign in or record attendance. Their history is kept.',
        'role' => 'Administrators manage employees and settings; employees only check in and out.',
        'own_access' => 'You cannot change your own role or status.',
        'invitation_on_create' => 'The employee chooses their own password. The account is created awaiting its invitation and can sign in once that password is set.',
        'invitation_link' => 'Send this to the employee. It works once, replaces any earlier link, and expires in :minutes minutes.',
        'pending_status' => 'This account becomes active by itself when the employee sets their password.',
        'role_super_admin_only' => 'Only the super administrator appoints or removes an administrator.',
        'deleted_account' => 'This account is deleted. Restore it before changing anything on it.',
    ],

    'super_admin' => [
        'badge' => 'Super administrator',
        'protected' => 'Designated by SUPER_ADMIN_EMAIL on the server. This account cannot be deactivated, demoted or deleted from here.',
        'role_locked' => 'Decided on the server, not here: this account is an administrator because SUPER_ADMIN_EMAIL names it.',
        'status_locked' => 'Decided on the server, not here: this account stays active because SUPER_ADMIN_EMAIL names it.',
    ],

    'badges' => [
        'deleted' => 'Deleted',
    ],

    'validation' => [
        'email_unique' => 'This email is already in use. If that account was deleted, restore it from "Deleted accounts" instead of creating a second one.',
    ],

    'filters' => [
        'role' => 'Role',
        'status' => 'Status',
        'trashed' => 'Deleted accounts',
        'trashed_without' => 'Without deleted accounts',
        'trashed_with' => 'With deleted accounts',
        'trashed_only' => 'Only deleted accounts',
    ],

    'actions' => [
        'reset_password' => 'Reset password',
        'reset_password_heading' => 'Reset password for :name',
        'reset_password_description' => 'The current password stops working immediately.',
        'invitation_link' => 'Copy invitation link',
        'invitation_link_heading' => 'Invitation link for :name',
        'invitation_link_description' => 'A fresh link, so any link sent earlier stops working. It is shown only here.',
        'invitation_link_close' => 'Done',
        'resend_invitation' => 'Resend invitation',
        'resend_invitation_heading' => 'Resend the invitation to :name?',
        'resend_invitation_description' => 'A new link is issued and any earlier one stops working.',
        'activate' => 'Activate',
        'activate_heading' => 'Activate :name?',
        'deactivate' => 'Deactivate',
        'deactivate_heading' => 'Deactivate :name?',
        'deactivate_description' => 'They will be signed out and unable to check in until reactivated. Their attendance history is kept.',
        'delete' => 'Delete',
        'delete_heading' => 'Delete :name?',
        'delete_description' => 'They can no longer sign in, and the account disappears from this list. Their attendance history is kept, with their name on it, and you can bring the account back with Restore.',
        'delete_confirm' => 'Delete account',
        'restore' => 'Restore',
        'restore_heading' => 'Restore :name?',
        'restore_description' => 'The account returns to the list with the status it had when it was deleted, so a deactivated account comes back deactivated.',
        'restore_confirm' => 'Restore account',
    ],

    'notifications' => [
        'password_reset' => 'Password updated',
        'password_reset_body' => 'The new password for :name is in effect immediately.',
        'invited' => 'Invitation sent',
        'invited_body' => 'An email with the link to set a password is on its way to :email.',
        'invitation_not_emailed' => 'The invitation email could not be sent',
        'invitation_not_emailed_body' => 'The account is ready. Use "Copy invitation link" to give :name the link yourself.',
        'link_copied' => 'Invitation link copied',
        'activated' => ':name is now active',
        'deactivated' => ':name is now inactive',
        'reopened' => ':name is awaiting their invitation again',
        'deleted' => ':name has been deleted, and their attendance history kept',
        'restored' => ':name has been restored',
    ],

    'empty' => [
        'heading' => 'No employees yet',
        'description' => 'Add the first employee so they can check in.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'Everyone who can sign in, and what they may do.',
        ],
    ],
];
