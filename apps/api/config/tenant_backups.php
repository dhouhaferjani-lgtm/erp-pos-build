<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Backup root directory
    |--------------------------------------------------------------------------
    |
    | Per-tenant pg_dump output lands under:
    |   <root>/<tenant-uuid>/<YYYYMMDD-HHMMSS>.dump
    |
    | Override via TENANT_BACKUP_ROOT for an alternate path (e.g., a mounted
    | volume on the production host).
    |
    */
    'root' => env('TENANT_BACKUP_ROOT', storage_path('app/tenant-backups')),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Number of most-recent successful backups to retain per tenant. Older
    | backups are deleted after a successful backup completes. Set to 0 to
    | disable retention (keep everything).
    |
    */
    'keep' => (int) env('TENANT_BACKUP_KEEP', 14),
];
