<?php

declare(strict_types=1);

return [
    'mail' => [
        'subject' => 'Your :company account',
        'greeting' => 'Hello :name,',
        'intro' => 'An account has been created for you on :company so you can record your attendance. Choose a password to start using it.',
        'action' => 'Set my password',
        'expiry' => 'This link works once and expires in :minutes minutes. If it has, ask your administrator for a new one.',
        'ignore' => 'If you were not expecting this message, you can ignore it.',
    ],
];
