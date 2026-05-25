<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pre-auth tenancy resolver mode (T6 Phase 0a / 0b)
|--------------------------------------------------------------------------
|
| P1-4 (Codex 2026-05-25). This is a dedicated config file (NOT config/tenancy.php,
| which is owned by the Stancl flip work) that controls the fail-open vs fail-closed
| behaviour of TenancyResolver::initializeIfProvisioned().
|
| db_per_tenant = false  (Phase 0a, the current single public-schema reality):
|     A present tenant whose per-tenant database/schema does not exist is a no-op
|     for the DB switch — callers keep querying the shared DB scoped by tenant_id.
|     The resolver returns false; the request continues (fail-open is correct here).
|
| db_per_tenant = true   (Phase 0b, after the Stancl flip / real per-tenant DBs):
|     A present tenant whose database cannot be initialized must FAIL CLOSED
|     (TenantUnavailableException -> 503) before downstream middleware, rather than
|     silently continue on the wrong/default connection.
|
| The 0b flip will set TENANCY_DB_PER_TENANT=true alongside the config/tenancy.php
| and config/database.php changes.
|
*/

return [
    'db_per_tenant' => (bool) env('TENANCY_DB_PER_TENANT', false),
];
