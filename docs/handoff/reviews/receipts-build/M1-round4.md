I completed a fresh round-4 pass: re-read the brief's M1 section and the spec's wave-1 contents, re-verified the round-3 P1 repair by running it, swept the whole diff independently, and hunted for new defects including the merge path into `dev`.

---

# M1 / Wave 1 — adversarial merge-gate register (round 4)

Range reviewed: `7d85232cc54abd6a6b2135f476205ab434e71a66..54351b6a6` (18 commits; `7d85232cc` confirmed a true ancestor, `git merge-base --is-ancestor` → yes, `rev-list HEAD..base` = 0). Repair under review this round: `af12e82fd` (Phase 1.1.17) + `54351b6a6` (register/YAML record).

Lenses: **frontend-conventions** ✔ applied · **tenancy-authz** ✔ applied · **treasury** ✔ applied (currency + no-aggregate half only; wave 1 has no GL, payment, or partial-write path, so that half of the lens does not apply — stated, not skipped) · **general** ✔ applied.

## Round-3 finding — verification of the repair

| Round-3 finding | Status | Evidence I ran |
|---|---|---|
| **P1-1** — archived (soft-deleted) terminal → HTTP 500 on `GET /pos/receipts` | **CLOSED — CONFIRMED** | Fixed at source: `apps/api/app/Modules/POS/Domain/Receipt.php:311` is now `belongsTo(Terminal::class,'terminal_id')->withTrashed()`, so the eager-load at `ReceiptController.php:79` resolves and `:194` no longer dereferences null. Red-first case added: `tests/Feature/POS/ReceiptIndexArchivedTerminalTest.php:12-22` archives the terminal via the same `delete()` the archive endpoint uses, then asserts `200` **and** that the archived terminal's `code` still renders on its historical row. I ran it: `./vendor/bin/phpunit tests/Feature/POS/ReceiptIndexArchivedTerminalTest.php` → `OK (1 test, 3 assertions)`. |
| P3-5 (legacy `receipt_type` axis bypassed the training bound) | **CLOSED** | `ReceiptController.php:119,151` — `$usesLegacyReceiptType` now forces the `training_flag = false` predicate on the legacy arm; locked by `ReceiptIndexTypeFilterTest.php:60-70`. |
| P3-6 (BT-1's `['SALE']+include_training=true` arm was vacuous) | **CLOSED** | `ReceiptIndexTrainingExclusionTest.php:104-106` now seeds a real `TRAINING` row in the byte-identical provider, so `['SALE']+true` can actually falsify. |

Regression check on the fix's blast radius (the relation change is global, not local to this controller): I swept every consumer of `Receipt::terminal()` — `ReceiptPdfService.php:162`, `ReceiptHashService.php:98,497` — each is strictly improved (they previously fataled on an archived terminal), and there is **no** `whereHas('terminal')` / `has('terminal')` anywhere on `Receipt`, so no existing query was silently widened. The `Shift::terminal()` relation (the many `$shift->terminal` sites) is untouched.

Wave-1 suite green on my own run: the 11 new/changed classes → `OK (50 tests, 248 assertions)`; scoped FE → `7 files, 48 tests passed`.

## P1 — blocks

**None.** No finding this round rises to P1.

## P2 — fix before merge

**1. The committed `permissionsMap.generated.ts` source hash cannot survive the merge into `dev`, and one plausible conflict resolution silently drops an admin permission. `apps/web/src/hooks/permissionsMap.generated.ts:3`. CONFIRMED.**

The branch regenerated the map against its own base: `// Source hash: sha256:8546d743…` (was `a74119e0…`). Since the base SHA, `dev` has **independently** changed the same seeder and regenerated the same file:

```
git diff 7d85232cc..dev -- apps/api/database/seeders/RolesAndPermissionsSeeder.php
  @@ -498,6 +498,7 @@   +            'settings.fiscal.update',
git diff 7d85232cc..dev -- apps/web/src/hooks/permissionsMap.generated.ts
  -// Source hash: sha256:a74119e0…
  +// Source hash: sha256:73a4c4aa…
  +  'settings.fiscal.update': ['admin'],
```

The seeder bodies auto-merge (dev's hunk is at `:498`, the branch's at `:796-797`), but line 3 of the generated map is changed on **both** sides — a textual conflict — and, whichever side is taken, the surviving hash (`8546d743…` or `73a4c4aa…`) will not match the *merged* seeder, which is base + both edits. `scripts/preflight.sh:137-161` and CI treat that as a hard drift failure.

Failure scenario: the parent merges, resolves the line-3 conflict by taking one side (the natural reflex on a generated file), and pushes to `origin/dev` — which auto-deploys. Best case CI fails on map drift. Worse case: the conflict is resolved by taking the branch's file wholesale, in which case `'settings.fiscal.update': ['admin']` disappears from the FE map and admins are silently denied the fiscal-settings surface on the frontend while the backend still grants it.

To close (parent action; the executor correctly never merges): after merging, re-run `(cd apps/api && php artisan permissions:export-frontend-map)` and commit the regenerated map in the merge commit, then confirm the result carries **all four** deltas — `settings.fiscal.update: ['admin']`, `deliveries.view` incl. `accountant`, `pos.view_receipts` incl. `accountant`, `pos.view_reports` incl. `accountant`.

Note for **F-1**: the STOP condition does **not** fire. `dev`'s seeder edit is one line in `permissionNames()`; the `accountant` block is untouched on `dev` and none of the three assigned keys is present there. This is mechanical regeneration, not a contested edit — do not re-open the seeder question with the owner.

## P3 — note (may ship with a ticket)

2. **The OP-23 re-gate makes `/settings/compliance/fraud-settings` reachable by the accountant as a fully editable form whose Save and Reset both 403.** `apps/web/src/features/compliance/pages/FraudSettingsPage.tsx:409-417` (submit) and `:195-204` (reset) are gated on nothing; only the cash-drawer sub-section honours a permission (`:42,404`, and on the unrelated `pos.configure_cash_count`). The backend requires `can:fraud-settings.update` (`Compliance/Presentation/routes.php:27,31`), which the accountant does not hold (`permissionsMap.generated.ts:87` → admin/manager). Pre-existing bug class — `viewer` already reached this route through the old `moduleKey="settings"` gate — so this branch widens the exposure by one role rather than creating it, and Addendum A(a) scoped OP-23 to the re-gate only. The handback records the IA-home residual (F-3) but not this one; worth adding to the OQ-10 ticket.

3. **S-7's frozen ordering contract is implemented but never asserted.** `ReceiptFilterOptionsController.php:65` (`orderBy('code')`) and `:83-84` (`orderBy('cashier_name')`, `orderBy('cashier_id')`) match the spec's *"Terminals by `code ASC`; cashiers by `name ASC`, then `id ASC` as the tiebreak. **Deterministic, no DB-default ordering**"* — but `ReceiptFilterOptionsTest.php` asserts membership and exclusion only, never sequence, and its one multi-terminal fixture has the second terminal filtered out of scope. A future refactor could drop either `orderBy` with the suite still green.

4. **`ReceiptReportingTestCase` grants `pos.view_receipts` ad hoc rather than through the real seeder** (`Support/ReceiptReportingTestCase.php:53-55`: `Permission::findOrCreate` + `givePermissionTo`), against brief §6's *"PG-backed, `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`"*. Mitigated where it matters — BT-9 (`ReceiptAuthorizationTest.php:26-33`) and BT-11 (`AccountantReceiptPermissionsTest.php:18`) both run the real seeder and the seeded `accountant` role — so no permission claim rests on the ad-hoc grant.

5. **Playwright flow 1 and the M1 screenshots still have not run.** `apps/web/e2e/pos/receipts-permissions.spec.ts` compiles and uses `loginAsRole` from `e2e/money-campaign/helpers` (correct — not the mocking `e2e/fixtures.ts`); the local API was not up at `127.0.0.1:8010`. Disclosed in the handback §Verification and the YAML `findings:`, exactly as brief §7 requires of an environment-blocked stage. Unchanged from rounds 2 and 3; not closable by the executor. It remains the only live proof of the A-1 nav → `/settings/compliance/export` path as a real accountant.

6. **The exact preflight invocation still never completes as one command** — it stops at repository-wide Pint drift on `tests/Feature/POS/ZReportListTest.php`, genuinely outside the lane and correctly untouched. Baseline ticket, not a lane defect. Carried from rounds 2 and 3.

7. **Three scoped vitest failures persist and are all pre-existing** (`useAnalytics.tenantScope`, `reportPages.tenantScope`, `POSPage`). Re-confirmed by attribution rather than assertion: `git diff --name-only 7d85232cc..HEAD` touches none of those subjects or their helpers.

## Bypasses I tried that FAILED (no defect found)

- **The `location_name` twin of the round-3 P1** — the exact hazard class that produced last round's blocker: `Location` carries **no** `SoftDeletes` (`grep SoftDeletes app/Modules/Company/Domain/Location.php` → 0 hits) and **no** `addGlobalScope`/`booted()` on `Location`, `Terminal` or `Receipt`, so `$receipt->location->name` (`ReceiptController.php:192`) cannot resolve null.
- **The same hazard on the other unguarded dereferences** in the row map: `fiscal_status` is `string(20) NOT NULL DEFAULT 'fiscalized'` (`2026_04_18_211150_add_offline_sync_fields_to_pos_receipts.php:20`), `cashier_id`/`cashier_name` are `NOT NULL` (`create_pos_receipts_table.php:51-54`), `receipt_type` is `NOT NULL DEFAULT 'sale'` — none can 500 the enum/`->value` reads at `:188,190,195-196`.
- **A cross-receipt aggregate anywhere in the diff** (OI-3, Addendum A(c) rule 2, brief §2): `git diff -U0 … | grep -E '^\+' | grep -E 'SUM\(|\.reduce\(|onQueue\(|Math\.round'` over `apps/api/app`, `apps/api/database`, `apps/web/src`, `apps/pos` → **zero hits**. `meta.total` and `receipts.length` (`ReceiptListPage.tsx:187-188`) are row counts, not money; a count is not a sum.
- **Rule 19 on added production lines**: same sweep for `parseFloat|Number\(|: any|<any>|toFixed|\(float\)|app\(` → **zero hits**. Money is `CurrencyScale::bcformatStrict((string) $receipt->total, $this->currencyScaleResolver->getScale($receipt->currency))` at the **receipt's** currency with a constructor-injected resolver (`ReceiptController.php:178,197`; constructor `:55`).
- **A company-currency leak on the FE** (Addendum A(c) rule 1): `formatCurrency(receipt.total, { currency: receipt.currency })` (`ReceiptListPage.tsx:170`) through `lib/format.ts:118` — the only implementation that does not default to `'EUR'`, and it never floats the string. Backend twin asserted with a genuinely differing-currency fixture (`ReceiptIndexEnvelopeTest.php:33,61,76-77`: company `EUR`, receipt `TND` → emits `TND` / `12.345`).
- **The BT-1 training matrix being vacuous** — I read the providers rather than trusting the count: `trainingCodeSets` covers all 8 TRAINING-containing subsets under both `null` and `false` (422) and under `true` (200, honoured verbatim); `legalNonTrainingCodeSets` covers all 7 non-TRAINING subsets **twice** with a byte-identical `getContent()` comparison, including r5's three mixed cases. Plus `[]` → 422, blank entry → 422, out-of-domain → 422. Mirrors the frozen §3.a table one-for-one.
- **Merge-time drift on every other file this branch touches**: `comm -12` of `git diff --name-only 7d85232cc..dev` against the branch's file list yields 8 overlaps; `generated.d.ts` and `routes/index.tsx` are non-adjacent hunks that merge cleanly, and the four handoff docs plus `scripts/adversarial-review.sh` are **byte-identical** to dev's copies (`git diff --quiet dev HEAD -- <file>`). Only the generated permission map is a real conflict — P2-1.
- **GATE-5 mis-keying / fail-open**: every POS child matches the frozen §4.2.1 table row-for-row including `tables → pos.manage_tables` (the N-3 trap) and `vouchers` deliberately left on the `pos` alias; all six identity keys plus the composite `compliance` key are present in `MODULE_PERMISSIONS` (`usePermissions.ts:57-63`), so no sidebar `permission:` on this branch can fail open. I also checked the "empty group header" hazard the spec asks to assert: every permission in the `pos` alias maps to at least one module-unconstrained child, so the header cannot render childless.
- **`fallback={<></>}` silently falling through to the redirect** on the three panel gates (`ComplianceExportPage.tsx:18-27`): `RequirePermission.tsx:66-69` tests `if (fallback)`, and a Fragment element is an object — truthy. A partial holder renders nothing, not a bounce.
- **OP-23 re-gate keyed to the wrong permission names**: `fraud-settings.view` / `fraud-alerts.view` match the backend `can:` middleware exactly (`Compliance/Presentation/routes.php:24,37`), and both are seeded to `accountant` (`permissionsMap.generated.ts:86,88`). Verified by real mount, not grep — `ComplianceRoutePermissions.test.tsx:57-79` renders `AppRoutes` under `MemoryRouter` and asserts each route mounts on its exact permission and redirects a `settings.view`-only user; I ran it (`6 tests passed`).
- **Addendum A(b)**: no `reports.financial` grant added and `/finance/lane-separation` untouched by this branch (dev added that route independently).
- **A dangling reference after the CL-1 deletion**: `grep -rn 'ShiftReceiptsList\|ShiftReceiptsListProps'` over `apps/web/src` + `apps/web/e2e` → zero; the design-system baseline entry was removed in the same change; `getShiftReceipts` correctly **kept** with the `@see` pointer (CL-2).
- **A stale `receiptSearch` consumer after the i18n rename**: `grep -rn receiptSearch` over `src` + `e2e` → zero. EN/FR key sets verified identical by parsing both JSON files; `filters.from`/`filters.to` (used at `ReceiptListPage.tsx:206,214`) exist in both.
- **Route shadowing**: `/pos/receipts/filter-options` is registered before `/pos/receipts/{id}` inside the group carrying `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` (`POS/routes.php:42,144-146`) — rule 12 satisfied.
- **Migration safety under the auto `tenants:migrate` deploy**: `CREATE INDEX IF NOT EXISTS` / `DROP INDEX IF EXISTS`, no `CONCURRENTLY`, filed under `migrations/tenant/` — additive and unattended-safe; BT-16 asserts both `pg_indexes` definitions, the partial predicate, `up()` idempotency and `down()`.
- **Pagination page-leak on filter change**: `useTableState.setFilter`/`removeFilter`/`clearFilters` all reset to page 1 (`useTableState.ts:175,185,191`), so a filter change from page 3 cannot strand the user on an empty page.
- **A conditional-hook violation on the new page**: all four hooks in `ReceiptListPage` precede both early returns; the register lives in a separate component; query keys go through `locationScopedKey` → `tenantScopedKey`.

Nothing else in the wave-1 scope (S-1/S-2/S-3/S-6/S-7/S-9/S-11 index+options/S-13, screen (a), A-1 + nav + OP-23, GATE-3, GATE-5, A-2, CL-1/2/5/6/7, i18n rename, BT-1…BT-5/BT-8…BT-12/BT-16/BT-18, FT-1…FT-3/FT-10/FT-11/FT-14…FT-16) showed a defect on this round. The round-3 P1 is genuinely closed, and M1's own named invariants and required tests are present and non-vacuous. The sole P2 is a merge-integration action owned by the parent, not a milestone defect — per brief §7 it is close-before-merge, not close-before-milestone-pass.

VERDICT: ACCEPT
