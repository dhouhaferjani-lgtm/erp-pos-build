I ran the full four-lens whole-lane gate myself: re-read the brief's M5 section, ran the `df85d43f4..HEAD` diff (79 files), re-derived every negative, and re-ran the accumulated evidence on this machine rather than trusting the report.

## Independent evidence I produced (not quoted from the report)

| Check | Command / result |
|---|---|
| POS path-scoped suites | `vitest run` × 6 files → **97/97 passed** |
| Web compliance page | `vitest run FraudSettingsPage.test.tsx` → **2/2 passed** |
| Backend on live PG (`127.0.0.1:5432`, fresh DB) | 7 files → **31 tests / 8,896 assertions**, no failures; migration file alone **exit 0**, 3 tests / 10 assertions, the `[PG]` `information_schema` default test **not skipped** |
| Pint | 8 changed PHP files → `{"result":"pass"}` |
| PHPStan level 8 | 6 changed production PHP files → `[OK] No errors` |
| Typechecks | `tsc --noEmit` web **0**, POS **0** |
| Deptrac | HEAD = **116 violations / 12,914 allowed** — matches the report's honest "globally red, wave adds zero" (allowed count drops by exactly 1, consistent with `CompanyFraudSettingsService` shedding its `Shared/Contracts` dependency) |

## Register

**1. P3 — CONFIRMED — the pasted `BASE_SHA..HEAD` file list is 3 files stale and the stated count is wrong**
`docs/sessions/codex-sv-stage1-report.md:632-711`, `:770`

The M5 evidence contract's named antidote is "the file list is **pasted and read**, not asserted." `git diff --name-only df85d43f4..HEAD` now returns **79** files; the pasted block contains 76, and the prose at `:770` still says "76 files". `comm` against the live list shows exactly the missing three: `M5-round2.md`, `M5-round3.md`, `M5-round4.md`. Failure scenario: the orchestrator reads the report's list as the merge inventory and merges three untracked-in-inventory files. Harmless in substance — all three are this reviewer's own outage registers, added after the paste — but the contract is a *pasted and current* list. Refresh at handback closes it.

**2. P3 — CONFIRMED — the six-line reveal can fail to sum to the "Expected in drawer" it sits above**
`apps/pos/src/lib/offline/endOfDayPreview.ts:453-465,490-492` · `apps/pos/src/components/pos/molecules/CashDrawerRevealSummary.tsx:47-73` · `apps/pos/src/lib/decimal.ts:14,22-28`

`cash_sales_net` rounds through `bcsub(bcsub(tendered, change), refundImpact)` while `expected_cash` rounds through a differently nested chain; `Big.RM = 1` re-rounds half-up at every step, so sub-scale inputs diverge. The wave's own test pins it (`apps/pos/src/lib/offline/__tests__/endOfDayPreview.test.ts:279-303`): opening `100.00` + `cash_sales_net 10.00` + movements `0.00` ≠ `expected_cash 110.01`. A cashier counting `110.00` sees a 1-cent SHORT that no displayed line explains, then owes a written reason. **In-brief by design** — M2's rendering contract explicitly defers the rounding line to SV-12 (Stage 5) — and recorded at report `:452`. Carried, not a blocker.

**3. P3 — CONFIRMED — hand-rolled three-level `ar` merge is a partial-translation trap with no guard**
`apps/web/src/lib/i18n.ts:401-412`

`resources.ar.compliance` is assembled by spreading exactly three fixed levels. A future `ar` key at a sibling path (e.g. `fraudSettings.fields.*`) would *replace* the English `fields` object rather than merge it, silently dropping fallbacks. Every other `ar` namespace in the file registers as a whole file; no test pins the merge shape. Carried from round 1, **still has no ticket in the tree** — only the register file and the one-line summary at report `:723`. Verified the current key renders: the Arabic DOM assertion at `FraudSettingsPage.test.tsx:97-118` ran green.

**4. P3 — CONFIRMED — `defaultsForVertical(bool $_isAutomotive)` is a dead-parameter API kept alive for one caller**
`apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php:197-200` · `apps/api/database/migrations/tenant/2026_04_25_100001_seed_company_fraud_settings_for_existing_companies.php:35-38`

The method ignores its argument and returns `getDefaults()`; the sole caller still computes `$isAutomotive` from `$company->tenant?->vertical` and passes it. A method whose *name* asserts a vertical-awareness that no longer exists is precisely the archaeology hazard M1 exists to kill — the docblock at `:514` literally says "this annotation stops a fifth [reader]". Carried from round 1, **still no ticket** (the `orphan-company-vertical-query-contract` ticket covers the contract, not this signature).

**5. P3 — CONFIRMED — progress YAML M5 `commit:`/`updated:` point at the round-3 record, not the round-4 one**
`docs/handoff/progress/sv-stage1.progress.yaml` (M5 block)

`verdict: M5-round4.md` but `commit: 33f323a34` / `updated: 33f323a34`, while `M5-round4.md` was added in `f7b9fffeb`. Deliverable item 2 requires the YAML to reflect reality. Bookkeeping only.

**6. P3 — CONFIRMED — a fourth artefact was touched beyond SV-1's list of three**
`apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:254-263`

R-2 fixes the artefact list at three. This Accounting comment was also rewritten (it cited `buildExpectedPerMethod` as the canonical row-fan-out precedent). Comment-only, zero behavioural delta, and leaving a stale cross-reference to a now-`@deprecated` symbol would have been worse. Noted for the "nothing else was touched" ledger, not a defect.

## Round-1 P2s — both closed, verified against code, not prose

- **P2 #1 (deploy ordering)** → `docs/superpowers/tickets/2026-08-12-sv9-blind-count-default-deploy.md` now opens with a *hard promotion precondition* (SV-11 device fleet-rollout evidence before any promotion that can run `tenants:migrate`), mirrored at report `:701-711`.
- **P2 #2 (fail-closed availability)** → owner ruling recorded (Option 1 approved) in the ticket and report `:725-742`; `docs/pos-operations/install.md:48-54,104-113` makes one successful policy sync a provisioning gate. `git diff --name-only 384dbbea8..HEAD -- apps/` is **empty**, so the resume is genuinely documentation-only, as claimed.
- **P3 #4 (stale device-cache note)** → fixed in the same ticket's "Device cache note".

## Bypasses I tried that FAILED (the implementation held)

- **Stage-2+/Lane-A0 contamination:** zero `Domain/Events/**`, zero `Modules/Fiscal/**`, zero `POS/Commands/**`. `git log df85d43f..HEAD -- <es-wave-a0 paths>` returns only `dc1899552` + `a5520f23c` — the pre-dispatch administrative pin, exactly as M0 accepted.
- **Flag flip:** `git show` on both sides — `'shift_variance_gl_enabled' => (bool) env('TREASURY_SHIFT_VARIANCE_GL_ENABLED', false)` byte-identical (line 29→28, comment-only).
- **Event churn:** zero-context grep for `Event::`/`dispatch(`/`class *Event` over added/removed production lines → nothing.
- **Rule 19 drift:** grep for added `parseFloat` / `Number(` / `toFixed(` / `toLocaleString` / `(float)` / `floatval` / `number_format` across `apps/` → **zero hits**. New `app()` calls → zero.
- **Falsify M1's *new* stated gate:** `grep -rln "CashDrawerOperation\|cash_drawer_operations\|opening_cash" app/Modules/Treasury` → **empty**; `grep opening_cash app/Modules/Accounting app/Modules/Treasury` → **empty**. "Float + drawer ops unbooked in Treasury (SV-3/SV-4)" is factually true.
- **Falsify the refuted premise's removal:** grep for `"sums receipt payments"` / `"658/758 on every"` → **zero hits in production code**; survivors are only the brief, the dossier, and review registers (historical). The G-2 verbatim quote now carries the explicit supersession sentence M1-round1 demanded.
- **Falsify "no shipped client" for `buildExpectedPerMethod`:** the live call site is `ReportGenerationService.php:234`, gated `$cashCountInputs !== null`; `assertServerReportAuthoringAllowed(…, 'Z_REPORT')` + `fiscal_schema_version >= 3` retires v3, and the chokepoint test pins `ZReportData` as exactly `{ terminal_id: string }` on both shipped clients. Reaching it requires a hand-rolled v2 API call. The `@deprecated` text is accurate.
- **Spot-check an audit "clean" verdict (different ones from round 1):** `ReportsMenu.tsx:59-65` — `xReport`/`cashDrawer`/`zList` are `managerOnly: true` and filtered by `isManager`, `todaySales`/`transactionHistory` are not: matches the audit's split exactly. `CashCountTable.tsx:63-64` — `showExpected`/`showVariance` both `!blindMode || committed`, gating headers *and* cells. `ZReportModal` has no production JSX caller (only its test + a barrel re-export).
- **Vacuous migration test:** seeds one `false` + one `true` row, asserts `changed=1/skipped=1` then `changed=0/skipped=2` in one test, plus a table-absent self-guard and a real `information_schema` default flip (`false`→`true`) that I confirmed **runs, not skips**, on live PG.
- **Silently-weakened red-by-design test:** `CompanyFraudSettingsVerticalDefaultsTest.php:43` renames the case *and* carries the explicit `SV-9 red by design` docblock.
- **M2 exact-string antidote:** `CashReconciliationSection.test.tsx:242-246` asserts the rendered French line verbatim with a real amount — *"Comptez tout l'argent présent dans le tiroir, y compris le fonds de caisse de 50.00."* — **and** that `'Le montant attendu inclut le fonds de caisse.'` is absent before Commit Counts. Non-vacuous.
- **Second modal-open path:** `setShowEndOfDay(true)` occurs once, inside `handleOpenEndOfDay`, which clears all five policy tokens first. No unguarded entry.

Six P3s, no P1, no P2. The four lenses all apply and all pass: `fiscal-pos` (reveal boundary, chokepoint, no event/sealed-byte reach), `treasury` (config + listener docblocks now state a gate I verified is real; flag byte-identical), `frontend-conventions` (tokens, `t()` en+fr device / en+fr+ar web, no raw money math, typecheck+lint clean, query-key audit 0/0), `tenancy-authz` (tenant-dir migration, per-tenant token logging, settings surface gating unchanged, cross-tenant guard green).

VERDICT: ACCEPT
