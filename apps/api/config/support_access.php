<?php

declare(strict_types=1);

$partnerEmail = (string) env('SUPPORT_ACCESS_PARTNER_EMAIL', 'support-approver@autoerp.local');
$approverEmails = array_map(
    static fn (string $email): string => strtolower(trim($email)),
    explode(',', (string) env('SUPPORT_ACCESS_APPROVER_EMAILS', $partnerEmail)),
);

return [
    'session_ttl_minutes' => 60,
    'write_elevation_ttl_minutes' => 15,
    'max_grant_window_hours' => 168,

    'four_eyes' => [
        'enabled' => (bool) env('SUPPORT_ACCESS_FOUR_EYES_ENABLED', true),
        'sensitive_tenants' => (bool) env('SUPPORT_ACCESS_FOUR_EYES_SENSITIVE_TENANTS', true),
        'approver_emails' => array_values(array_filter($approverEmails)),
    ],

    'partner' => [
        'name' => (string) env('SUPPORT_ACCESS_PARTNER_NAME', 'Business Partner Support Approver'),
        'email' => $partnerEmail,
        'password' => env('SUPPORT_ACCESS_PARTNER_PASSWORD'),
    ],

    'permissions' => [
        'read_only' => [
            '*.view',
            'dashboard.owner',
        ],
        'write' => [
            '*.create',
            '*.update',
            '*.manage',
        ],
    ],

    'write_guard' => [
        'readonly_post_routes' => ['support-access.sessions.exit'],
        'hard_block_route_patterns' => [
            'support-access.*',
            'auth.forgot-password',
            'auth.reset-password',
            'users.reset-password',
            'tenants.*delete*',
            'tenants.*deprovision*',
            '*rotate-secret*',
            'fiscal.*',
            'invoices.post',
            'invoices.cancel',
            'credit-notes.post',
            'credit-notes.cancel',
            'payments.void',
            'payments.refund',
            'payments.partial-refund',
            'payments.reverse',
        ],
        'hard_block_path_patterns' => [
            '#/support-access(?:/|$)#i',
            '#/(?:forgot-password|reset-password)(?:/|$)#i',
            '#/users/[^/]+/reset-password(?:/|$)#i',
            '#/tenants/[^/]+/(?:delete|deprovision)(?:/|$)#i',
            '#/(?:rotate|rotation)[^/]*(?:secret|key)|/(?:secret|key)[^/]*(?:rotate|rotation)#i',
            '#(?:^|/)fiscal(?:/|$)#i',
            '#/pos/sync/fiscal-events(?:/|$)#i',
            '#/fiscal-schema-cutover(?:/|$)#i',
            '#/(?:invoices|credit-notes)/[^/]+/(?:post|cancel)(?:/|$)#i',
            '#/pos/receipts/[^/]+/(?:void|refund)(?:/|$)#i',
            '#/pos/receipts/[^/]+/return(?:/|$)#i',
            '#/pos/reports/z(?:/|$)#i',
            '#/pos/audit-events/sync(?:/|$)#i',
            '#/pos/shifts/[^/]+/(?:close|sync-close)(?:/|$)#i',
            '#/pos/voucher-ledger/sync(?:/|$)#i',
            '#/payments/[^/]+/(?:void|refund|partial-refund|reverse)(?:/|$)#i',
        ],
    ],
];
