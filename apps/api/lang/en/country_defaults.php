<?php

declare(strict_types=1);

return [
    'auth' => [
        'unauthenticated' => 'Authentication required.',
        'deactivated' => 'This admin account has been deactivated.',
        'external_editors_disabled' => 'External country-default editors are not enabled.',
    ],
    'errors' => [
        'template_conflict' => 'The template operation conflicts with its current lifecycle state.',
        'template_validation' => 'The template did not pass certification validation.',
        'template_validation_detail' => 'One or more certification rules failed.',
        'assignment_conflict' => 'The assignment conflicts with the template certification rules.',
        'editor_conflict' => 'The editor account operation conflicts with its current state.',
    ],
    'catalog' => ['generic_fallback' => 'Generic fallback'],
];
