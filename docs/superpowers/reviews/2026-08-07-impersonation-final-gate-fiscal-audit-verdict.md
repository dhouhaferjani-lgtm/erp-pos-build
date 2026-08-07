# Final-Gate Adversarial Review — Fiscal/Audit-Chain Surfaces — `codex/tenant-impersonation`

**Date:** 2026-08-07 · **Reviewer:** fiscal-pos-reviewer (Opus) · **Diff:** `46fd7decd..f3466dadc`
**VERDICT: REJECT — do not merge.** 2 BLOCKER / 5 MAJOR / 5 MINOR.

## Verified passing (from source, not assumed)
- **Pre-existing chain integrity:** `AuditEvent::calculateHash()` (AuditEvent.php:236-249) byte-identical to base; new columns nullable-additive; `admin_audit_logs` never had a chain. No already-written chain invalidated.
- **New session chain mechanics:** recursive ksort, JSON_THROW_ON_ERROR, no floats, UTC second-pinned timestamps, hash_equals verifier, genesis zeros, PG+SQLite append-only triggers. Tamper test is genuine (7 mutated fields, asserts exact failing sequence; DB-mirror tamper asserts exit 1 after clean exit 0).
- **Fiscal signed bytes untouched:** zero `impersonat` hits in POS/Fiscal/Accounting modules; DomainEventSubscriber change is fail-closed rethrow only.
- PHPStan clean on changed surfaces; SupportAccess tests by path 8/74 green.

## BLOCKER-1 — Fiscal write endpoints only `RequiresElevation`, not `HardBlocked`
`config/support_access.php:59-70` + `ImpersonationActionClassifier.php:26-32`. Escaping the hard-block patterns (verified against `POS/routes.php`): `POST /pos/receipts/{id}/return` (authors POS_RECEIPT_RETURN — `return` missing from the void|refund alternation), `POST /pos/reports/z` + `/z/sync` (closes fiscal period), `POST /pos/audit-events/sync`, `POST /pos/shifts/{id}/close` + `/sync-close`, `/pos/voucher-ledger/sync`. An elevated support session can author a refund and close a Z period on the tenant's fiscal chain.
**Fix:** add the missing patterns AND a route-table-enumerating test asserting every non-safe POS/Fiscal/Accounting route classifies `HardBlocked` — a config allowlist never diffed against the route table rots on the next route.

## BLOCKER-2 — Denials and all lifecycle transitions absent from the hash chain
Only `RequestAuthorized` is ever emitted (SessionAuditService.php:66,83). Nine of twelve `SessionEventType` cases never fire (grant request/approve/reject/revoke, session start/end, denial, elevation request/approve/reject, reveal). Middleware order (`ImpersonationContext` → `ImpersonationWriteGuard` → `ImpersonationAudit`) means a 403 from the write-guard leaves ZERO trace — an operator can probe every hard-blocked endpoint invisibly.
**Fix:** emit chained events for all lifecycle transitions; for denials, either reorder audit before guard or have the guard append a terminating `RequestDenied`.

## MAJOR findings
- **M-3:** chain records `outcome: allowed` pre-dispatch (ImpersonationAudit.php:31 records BEFORE `$next`); policy denials/validation failures/500s land as "allowed", irreversibly. Fix: versioned `request_received` case + terminating event with real status.
- **M-4:** raw `X-Request-ID` header bound into PG `uuid` column → client-triggerable 22P02 → audit rollback → 503 for the whole feature (SQLite test lane masks it). Fix: `Str::isUuid()` validation + PG-lane malformed-header test.
- **M-5:** tenant mirror write (AuditService.php:21-45) relies on ambient connection while the verifier resolves explicitly — central-config lane writes mirror to the WRONG DB silently. Fix: shared explicit `$tenant->run()` resolver for write + verify.
- **M-6:** attribution only in `AuditService::record()`; bypassed by `AuditEventSyncController` client envelopes (combined with BLOCKER-1: elevated operator can inject chosen-payload audit events with NO impersonator_id), `FiscalSchemaCutoverService::create`, and un-updated `AdminAuditService::log()`. Fix: model `creating` observer + stamp AdminAuditService.
- **M-7:** session-chain hash overloads `audit_events.event_hash` (everywhere else = `calculateHash()` tuple) — support_access rows permanently fail future forensic recomputation. Fix: `recomputeHash()` for event_hash; chain hash stays in `impersonation_hash` only; document the `company_id === null` NF525-exclusion coupling.

## MINOR findings
- **m-8:** new attribution columns outside `calculateHash()` — NULLing impersonator_id validates; mitigated by mirror-count check, state explicitly (any inline fix must be a versioned `calculateHashV2`, rule 8).
- **m-9:** dead shipped surface — `impersonation_reveal_events` table + entity + `RevealRecorded` have zero references. Wire to `SensitiveResponseMasker` or drop.
- **m-10:** singleton `AuditService` captures scoped `ImpersonationContextProvider` (+ boot-pinned subscriber) — silently drops attribution under Octane/`forgetScopedInstances`. Bind scoped or resolve lazily.
- **m-11:** `AdminAuditService` mirror omits operator `ip_address`/`user_agent` — the forensic field you want.
- **m-12:** `array_any()` is PHP 8.4-only vs composer `^8.2` + platform 8.3.30 pin — works on the 8.4 runtime image, fatals on a 8.3 composer install. Fix the constraint or the calls.
