# M2 adversarial merge-gate review — round 2

**Scope:** brief §M2 (`docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:342-377`), diff `7d85232cc..HEAD`, fix commit `76f4c5364` on top of `d148555ba`/`7dc121e59`.
**Lenses:** tenancy-authz — applies (central-connection pinning, no HTTP surface yet, so route/permission gating is M3). treasury — applies to the publish gate's absorber/timbre semantics only. **Rule 19 N/A** (M2 introduces no monetary or quantity value; `grep` over `app/Modules/CountryDefaults/` finds no float cast, `number_format`, or bcmath). **Horizon N/A** (no named queue). **en+fr N/A** (no user-facing string; all messages are developer-facing exceptions).

## Round-1 disposition (verified, not taken on trust)

- **R1-1 (topological clone) — FIXED, but see finding 1.** `cloneToDraft()` now orders via `cloneInsertionOrder()` (`TemplatePublishingService.php:325-354`); the new red-first test `TemplateImmutabilityTest.php:310-353` builds a genuine 70/706/7061 inversion. Report records it failing `23000` (SQLite) / `23503` (PG) before the fix, and re-failing on revert.
- **R1-2 (publish-boundary normalization) — FIXED, non-vacuous.** `CountryCodeNormalizationTest.php:47-53` publishes with `[' tn ']` and asserts the persisted immutable column is `['TN']`; report records it red when `trim()`/`strtoupper()` is removed.
- **R1-3 (synthetic SQLite race padding) — FIXED.** All `addToAssertionCount()` padding removed; `requirePostgreSqlRace()` (`TemplateLifecycleRaceTest.php:413-421`) skips with an explicit label and also guards `pcntl_fork` (closing R1-10's fatal half). Final numbers are now honestly asymmetric — `SQLite 38/255 + 7 skipped` vs `PostgreSQL 45/329` (report `:1099-1105`).
- **R1-4 (discarded affected-row count) — FIXED** at `TemplatePublishingService.php:84-86,184-186`.
- **R1-5/6/7/10(partial)/11/12 — correctly routed** to `docs/sessions/2026-08-11-country-defaults-m2-p3-hardening.md`; all remain open and none is load-bearing for M2's named invariants.

## Register

**1 — P2 — `apps/api/app/Modules/CountryDefaults/Application/Services/TemplatePublishingService.php:338` (with `:282-286`) — CONFIRMED**
The new self-parent exemption `$parentCode !== $code` is type-broken. `$pending` is keyed by `$row->code` (`:329`), and PHP coerces canonical numeric strings to integer array keys, so `foreach ($pending as $code => $row)` yields `int(70)` while `$parentCode` is `string('70')` — I verified this directly (`php -r`: `'70' !== 70` → `true`). The exemption therefore never fires for numeric account codes, i.e. for essentially every chart-of-accounts code.
Meanwhile `validateAccounts()` does **not** reject `parent_code === code`: `:283` only checks `isset($byCode[$account->parent_code])`, which a self-parent satisfies. And such a row is insertable — I probed the exact composite-FK shape from `2026_08_11_100100_…:32-34` under `PRAGMA foreign_keys=ON` and the self-referencing insert succeeded (the dangling-parent control failed, as expected); PostgreSQL's AFTER-row FK trigger behaves the same.
*Failure scenario:* a draft contains `code='70', parent_code='70'`. Insert legal → `validateAccounts()` passes → `publish()` succeeds → `assign()` succeeds (it re-runs the same validator). `cloneToDraft()` then never finds an insertable row for `'70'`, makes no progress, and throws `DomainException('Template account hierarchy contains a cycle or unresolved parent.')`. Since spec §4.1 makes clone→edit→publish→re-point the **only** way to change a published template, that template is permanently uncorrectable — the same failure class R1-1 was raised to close, surviving in a narrower form through the fix's own broken guard. Fix is two-fold and small: compare `(string) $code`, and add the self-parent (cycle) rejection to the publish gate's structural checks with a covering negative test.

**2 — P3 — `apps/api/tests/Feature/CountryDefaults/TemplateLifecycleRaceTest.php:265-270` — CONFIRMED**
The evidence offered for the R1-4 fix is `assertSame(2, substr_count($publishingSource, 'if ($updated !== 1)'))` — a source-text ratchet, not a behavioural test. It never reaches the guard, never asserts a `DomainException`, goes red on a purely stylistic rewrite (`if (1 !== $updated)`), and would go green on two occurrences in dead code. Same class as the pre-existing `test_lifecycle_services_declare_assignment_rows_before_sorted_template_locks` (`:238-263`). Acceptable as a ratchet, but it should not be counted as behavioural coverage of the affected-row invariant.

**3 — P3 — `docs/sessions/2026-08-11-country-defaults-m2-p3-hardening.md` (R1-9 ticket) vs `ProvisioningRequiredPurposesV1.php:12` — CONFIRMED**
The recorded remedy "Replace the publish-gate `REQUIRED` string literal with the manifest constant" is not actionable as written: `REQUIRED` is declared `private const` (`:12`), so `TemplatePublishingService.php:289` cannot reference it. The ticket needs restating (expose a public classification constant or a backed enum) or it will be closed as impossible.

**4 — P3 — `apps/api/app/Modules/CountryDefaults/Domain/ValueObjects/CertificationScope.php:65-68` + `TemplatePublishingService.php:298-300` — CONFIRMED, forward-risk for M5 (treasury lens)**
`includesTimbreCountry()` returns `false` for the wildcard scope, so the publish gate **forbids** `SalesStampDutyPayable` on any `*` template, and `allowsAssignment()` (`:74-76`) restricts a `*` template to the `*` assignment row only. The rule is internally consistent for M2. But it means the global fallback template structurally cannot carry the stamp-duty account: if M5's 4-step resolver falls back to `*` for a TN company that has no `TN` assignment, that company provisions with no `SalesStampDutyPayable` — precisely the absorber-selection hazard the rule exists to protect. Not an M2 defect; record it as a named M5 gate item.

**5 — P3 — carried forward, unchanged.** R1-5 (dead normalization branch, `CountryTemplateAssignment.php:34`), R1-6 (`CentralConnectionUnderTenancyTest.php:59` stubs `tenancy.bootstrappers` instead of exercising the prescribed `DatabaseTenancyBootstrapper` swap — it still proves the invariant that matters), R1-7 (missing indexes), R1-10's residual fixed `usleep(400_000)` handshake window (`:340,410`), R1-11 (`outsideCentralTransaction()` leaks `super_admins`/`admin_audit_logs` rows), R1-12 (typed exceptions deferred to M4/M5). All are in the hardening doc; none blocks.

## Bypasses attempted that FAILED (the code held)

- **Multi-row parent cycle (A→B, B→A) smuggled past the gate:** blocked by the immediate composite FK — my SQLite probe confirms the dangling-parent insert fails, so no two-row cycle can ever be built. Only the self-cycle survives, which is finding 1.
- **Re-running the R1-1 ordering attack** with a three-level inverted hierarchy: `cloneInsertionOrder()` now emits 7061→706→70 and preserves each source `sort_order`, so `UNIQUE(template_id, sort_order)` still holds and the canonical hash is unchanged.
- **Dangling external parent in a clone:** now a typed `DomainException` instead of a raw `QueryException`/500.
- **Publish/assign hash divergence via row-fetch order:** both fetch unordered (`:42`, `TemplateAssignmentService.php:99`), but `CanonicalCoaSerializer::serialize()` sorts by `sort_order` (`:85`) and rejects duplicates (`:63-66`), so `hash_equals` at assignment cannot spuriously fail.
- **Audit row escaping the central transaction / landing in a tenant DB:** `AdminAuditLog` carries `CentralConnection` (`app/Models/AdminAuditLog.php:34`), and `assertAuditTransaction()` guards the same connection instance returned by `DB::connection()`. Rollback proven in `TemplateAuditTransactionTest.php`.
- **Root migrations leaking into every tenant DB on the staging auto-deploy:** `config/tenancy.php:197` pins `tenants:migrate` to `database_path('migrations/tenant')`, and all three files additionally pin `Schema::connection(central)`.
- **Silent wrong-DB targeting if `tenancy.database.central_connection` is unset:** `DB::connection('')` throws — the migration aborts loudly rather than creating central tables in the wrong database. FK ordering also holds: `super_admins` is `2025_12_01_194614`, well before `2026_08_11_100000`.
- **Zero-row status transition after a dropped lock in publish/archive:** now throws (`:84-86,184-186`); assign/remove already checked.
- **Bulk-update bypass of the immutability guards:** the `app_path()` ratchet (`TemplateImmutabilityTest.php:230-251`) still holds — the round-2 `withoutEvents` fixtures live in `tests/`, outside the scanned tree.
- **`app()` / cache / `config()` in module production code:** `grep` over `app/Modules/CountryDefaults/` returns nothing; constructor injection throughout, and "provisioning-relevant reads are never cached" is trivially satisfied.
- **Lock-order deadlock across the four lifecycle paths:** assignments-first then id-sorted templates in `archive`/`delete`/`assign`; `publish` takes no assignment lock; `clone` takes only source template → its rows. No cycle.

**Red-first evidence:** present and substantive for the round-2 fixes — report `:1088-1097` records the pre-fix failures, the revert-mutation reproduction, the normalization mutation, and the affected-row ratchet failing 1/1 then again at one-of-two. Dual-engine counts are now honestly asymmetric.

**Verification limits:** I did not execute PHPUnit (house rule; no broad runs) and did not exercise PostgreSQL. `php -l` is clean on all four changed files; the working tree is unmodified. The SQLite FK probe ran in `/tmp` against a scratch database and was deleted. All findings are cited to source, schema, or a reproduced PHP/SQLite behaviour.

VERDICT: CHANGES-REQUIRED
