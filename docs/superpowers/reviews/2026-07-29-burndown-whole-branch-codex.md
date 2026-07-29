# Codex adversarial review — treasury burn-down, FINAL WHOLE-BRANCH gate

Diff: `origin/dev...HEAD` @ `cc09a2ffe` · 34 commits · 48 files · +4723/−520
Reviewer: Codex CLI (`codex exec`, read-only sandbox) · 2026-07-29
Transcribed by the orchestrator; controller disposition inline.

## Verdict: APPROVE-WITH-FIXES — **no blocking findings**

The review was scoped to what per-task rounds structurally cannot see (cross-task
interference, exhaustive parity/dangling sweeps, the lane's fiscal-payload freeze, binding
`CLAUDE.md` rules, merge safety), and was given the full list of already-adjudicated items
with instructions not to re-raise them. It did not re-raise any.

---

## Findings

### Blocking
None.

### Non-blocking

1. **[Minor]** `apps/web/src/locales/fr/treasury.json` — `movementsProduced_many` missing.
   French selects the CLDR `many` category for values ≥ 1e6, so i18next would fall back to
   **English** (`fallbackLng: 'en'`, `apps/web/src/lib/i18n.ts:427`) in a French UI.
2. **[Minor]** `apps/api/tests/Feature/Accounting/SeedChartsCommandTest.php` —
   `test_dry_run_reports_created_then_real_run_creates` asserted only the generic
   `Chart provisioning:` marker, so it would pass even if the dry-run dishonestly reported
   `0 created` — contradicting the test's own name.

### Cross-task / integration findings (no defects)

- **T1 + T2 compose correctly.** Allocation input is capped at 3 decimals at
  `AllocateStatementLineRequest.php:37`, while suggestion arithmetic resolves currency scale
  once and stays on bcmath (`StatementSuggestionService.php:81,206`).
- **T3 is a genuine module boundary.** Expense receives the Shared read port by constructor
  injection (`SyncExpenseOnInstrumentLifecycle.php:18`); only the Treasury adapter imports
  `PaymentInstrument`, with tenant/company/direction scoping
  (`EloquentOutboundInstrumentPaymentLinkResolver.php:28`).
- **T8 does not touch T2's deleted models** — snapshots use only `Bank` and `Account`.
- Structural i18n parity otherwise holds: `en` and `fr` each 741 leaf keys; `ar` has all of
  them plus its 21 locale-required plural forms.

### Checks performed and PASSED (per the reviewer)

- Exhaustive sweep of app/tests/factories/seeders/migrations/docs/frontend/shared-TS for both
  deleted model names: **no live reference**. The cutover guard asserts their absence
  (`BankReconciliationCutoverTest.php:14`); surviving mentions are historical migrations and
  the deliberately immutable audit label on `ReconciliationCompleted.php:9` (rule 8).
- **Re-rendered the permissions map in memory from `RolesAndPermissionsSeeder`** — exact match
  with the committed artifact. T4's renamed key is consumed at `Sidebar.tsx:277`; backend
  routes enforce the corresponding permissions at `Treasury/Presentation/routes.php:267`.
- Rule 12 middleware pattern verified (`routes.php:35`). Treasury needs no `module:` gate — it
  is a core default module for every vertical (`vertical-module-gating.md:60`).
- **Fiscal-payload freeze passed:** no protected POS/fiscal projection, payload registry,
  canonical parser, shared payload type, or golden-vector path changed.
- Rules 3/13/14/19 sweeps passed: no production `app()`, `mixed`, or TS `any`; no tenant
  query-key changes; no monetary float conversion.
- Test-integrity spot checks found effective regression assertions for Tier-1 fan-out overflow,
  over-scale validation, and stale permission-map detection.
- **No migrations ship.** The T8 commands are opt-in and fail before execution when their
  tables are unavailable; no changed runtime path depends on having run them.
- Zero overlapping changed paths and zero conflict markers against local `dev`.

---

## Controller disposition — both findings FIXED

### 1. French `_many` — FIXED (scoped to what this branch introduced)

Verified against CLDR: `Intl.PluralRules('fr')` maps `1000000 → many`, while `en` has no
`many` category at all (`1000000 → other`). **This is why the orchestrator's own en-vs-fr key
diff reported 0 missing and could never have caught it** — a locale-specific category has no
English counterpart to diff against. Codex's finding stands.

`git blame` scoping: T4 (`4b14a8f95`) converted `movementsProduced` from a flat key to
`_one`/`_other` across en/fr/ar, so **this branch introduced this particular gap** (Arabic
later received all six forms via T7). Fixed by adding `movementsProduced_many` to
`fr/treasury.json`.

**Generalized the finding and deliberately did NOT fix the rest.** A repo-wide sweep for
French bases having `_other` but no `_many` found **20 across 10 namespaces** — `batches`,
`customer-history-audit`, `documentIngestions`, `finance`, `inventory`, `purchases`, `sales`,
`stock-transfers`, `treasury` (5), `workshop-bundles`. Nineteen are **pre-existing** and
outside this branch. Fixing them here would violate rule 4 (no scope creep). 🎫 **Ticketed:
French `_many` plural backfill, repo-wide (19 remaining bases).**

### 2. Dry-run count assertion — FIXED, with a verified RED

Rewritten to capture `Artisan::output()`, regex the reported count, assert it is non-zero,
and assert it **equals what the real run subsequently creates**. RED-verified by injecting a
dishonest preview (`$dryRun ? 0 : $created`):

```
injected dishonest dry-run → 1 failed (4 assertions) at the "$previewed > 0" guard
restored                   → 8 passed (63 assertions)
```

### Gates after these fixes

```
php artisan test tests/Feature/Accounting/SeedChartsCommandTest.php \
                 tests/Feature/Treasury/BackfillBanksCommandTest.php
→ Tests: 18 passed (121 assertions)

pint --test    → {"result":"pass"}
phpstan analyse <2 commands + 2 tests> → [OK] No errors
  (the capture access needed `?? '0'`; assertArrayHasKey does not narrow the
   array{}|array{...} union at level 8)
vitest LinePanel.test.tsx (consumes movementsProduced) → 8 passed
```

## Independent orchestrator verification (run in parallel, before reading the verdict)

Cross-checked the same high-risk areas rather than accepting the review at face value; all
agreed with Codex, and the one divergence is documented above (the fr `_many` blind spot in
the en-vs-fr diff — Codex was right, the orchestrator's sweep was structurally incapable):

| Check | Result |
|---|---|
| Dangling refs to deleted models | none (3 hits = immutable audit string + cutover assertions) |
| Fiscal-payload freeze | respected — 0 matching paths |
| i18n en/ar/fr structural parity | fr 0/0; ar 0 missing (+21 CLDR plurals) |
| Permissions map + T4 rename | guard 3/3 incl. drift detection; separate maps, no orphan |
| Rule 19 on T2 money validation | `string`+`numeric`+`regex:/^\d+(\.\d{1,3})?$/` |
| Rule 13 `app()` in production | none in changed `apps/api/app/**` |
| Rule 14 tenant-scoped keys | `audit:keys` 0 violations |
| Migrations on branch | none |
| Changed API tests (8 files) | 59 passed / 313 assertions |
| Changed web tests (5 files) | 68 passed |
| `pnpm typecheck` | clean |
