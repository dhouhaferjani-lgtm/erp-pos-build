# Ticket: unvalidated `X-Company-Id` header → SQLSTATE 22P02 / 500 on PostgreSQL

**Filed:** 2026-08-07, by the R2-K-prev deposit-preflight lane (out-of-lane finding).
**Severity:** HIGH on production topology — any authenticated user can 500 **every**
`api/v1/*` route with one header. Masked locally because the test suite runs SQLite.
**Status:** OPEN.

## Defect

`CompanyContextMiddleware::resolveCompanyId()` forwards the raw request header
straight into a query against a native `uuid` column, with no format guard:

```php
// app/Http/Middleware/CompanyContextMiddleware.php:105-108
$headerCompanyId = $request->header('X-Company-Id');
if ($headerCompanyId !== null && $headerCompanyId !== '') {
    return $headerCompanyId;          // <- unvalidated, straight to the DB
}
```

The value lands in `CompanyContext::userHasAccessToCompany()`
(`CompanyContext.php:146-152`), which issues
`->where('company_id', $companyId)` against `user_company_memberships.company_id`.

- **SQLite** (test suite): compares as TEXT, no match, returns false → clean 403
  `COMPANY_ACCESS_DENIED`. The defect is invisible.
- **PostgreSQL** (staging + production): PG cannot parse `not-a-uuid` as `uuid` →
  `SQLSTATE[22P02]: invalid text representation` → unhandled → **500**.

`CompanyContextMiddleware` is appended to the global `api` group
(`bootstrap/app.php:104-110`), so this reaches **every** `api/v1/*` route except the
`api/v1/admin` exemption. Any authenticated user, no special permission, one header.

## Why it survived review

This is the known UUID-validation pitfall class already recorded in project memory
(*"UUID cols in PG: validate `Str::isUuid()` before `where('uuid', $val)` or it 500s"*).
It survived here because the only tests that exercise the header path run on SQLite,
where the bad value degrades to a benign 403 instead of an exception. Pre-existing —
not introduced by R2-K-prev, which only read this code while answering the V2
reachability question.

## Suggested fix

Guard the header before it is trusted as an identifier, and treat a malformed value as
absent-or-denied rather than as a lookup key:

```php
if ($headerCompanyId !== null && $headerCompanyId !== '') {
    if (! Str::isUuid($headerCompanyId)) {
        return null;   // -> NO_COMPANY_ACCESS 403, same as any other non-member answer
    }
    return $headerCompanyId;
}
```

Returning `null` (rather than throwing) keeps the response shape identical to the
current SQLite behaviour, so no client contract changes.

## Test to add

A middleware test asserting a malformed `X-Company-Id` yields a 403 **and issues no
query against a uuid column** — and, critically, one that would fail on PostgreSQL
today. Since CI runs SQLite, assert on the guard (no DB round-trip for a malformed
value) rather than on the status code alone, or the test will pass for the wrong reason
exactly as the current ones do.

## Related

- `2026-08-07-company-gate-single-middleware-coupling.md` — same middleware, structural
  concern rather than a crash.
- `docs/superpowers/tickets/2026-08-05-deposit-residual-seal-before-resolve-vectors.md`
  §V2 — where the reachability audit that surfaced this ran.
