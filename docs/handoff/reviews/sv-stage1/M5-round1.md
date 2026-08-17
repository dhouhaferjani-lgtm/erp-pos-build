M5 whole-lane gate — round 1. Read the brief's M5 section, ran the full `df85d43f4..HEAD` diff (74 files, +3610/−203), re-ran the accumulated evidence myself, and applied all four lenses.

## Register

**1. P2 — CONFIRMED — deploy-ordering coupling between SV-9 (server) and SV-11 (device) is nowhere recorded**
`apps/api/database/migrations/tenant/2026_08_12_100000_enable_blind_cash_count_for_existing_settings.php:41` · `apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php:58,179`

SV-9 turns `require_blind_cash_count` on for every persisted row and every no-row fallback. Per the project's standing rule, a push to `origin/dev` auto-deploys and runs `tenants:migrate` — so the flip reaches every tenant through the API channel. The copy that makes blind counting survivable (`cash_count.count_instruction`, `apps/pos/src/locales/en/pos.json:908`, rendered at `apps/pos/src/components/pos/CashReconciliationSection.tsx:259`) ships in `apps/pos`, a separately built Tauri app.

Failure scenario: the API promotes first. IziPOS/retail tenants — who have **never** had blind counting (`defaultsForVertical` gave them `false`, and the 2026-04-25 seed persisted it) — get it enabled at their next settings sync while devices still run the pre-M2 build. Cashiers see no expected figure and no whole-drawer instruction, count takings only, and every close lands a variance equal to the opening float. With `require_manager_pin_above_hard = true` and a `20.0000` hard threshold, that forces a written reason plus a manager PIN on essentially every shift close, fleet-wide, until devices update.

The M5 evidence contract requires the deploy obligations to be recorded. `docs/sessions/codex-sv-stage1-report.md:699-701` and `docs/superpowers/tickets/2026-08-12-sv9-blind-count-default-deploy.md` record only `tenants:migrate` and the log token. Neither names the device build, nor states that SV-9 must not land ahead of SV-11.

**2. P2 — CONFIRMED — M4 shipped a hard fiscal-availability block that exceeds SV-10's narrow-fix permission**
`apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:115-116,233-256` · `apps/pos/src/components/Header.tsx:114-118,239,248-256`

Before this wave, a device whose online fraud-settings fetch failed **and** whose `company_fraud_settings_cache` row was absent fell through with `fraudSettings === null` → `cashCountEnabled` false → the EOD preview rendered and the shift closed with a Z report and no cash count. Now that exact state renders "Cannot close this shift: the cash-count policy has not been synced to this device" and the operator can close nothing and generate no Z until connectivity returns.

SV-10's acceptance is "under blind mode, no surface renders expected or variance magnitude before Commit Counts". In the null-policy state there is no blind mode and no cash-count section at all — this is policy-unknown fail-closed hardening, not an in-row magnitude leak. R-8 and M4's own scope line say *anything larger → ticket + report*. The implementer knows this: `docs/superpowers/tickets/2026-08-17-blind-count-policy-unavailable-close.md` opens with "Confirm that blocking close/Z generation is the approved NF525/fiscal behavior…" — yet the block ships **enabled** rather than ticketed-and-deferred. Combined with finding 1, a fleet-wide fiscal-availability change rides an unattended migration. The direction is safe for disclosure; the availability cost is owner-owed and unruled.

**3. P3 — CONFIRMED — the six-line reveal does not sum to the "Expected in drawer" it sits above**
`apps/pos/src/lib/offline/endOfDayPreview.ts:453-465` · `apps/pos/src/lib/decimal.ts:14`

`cash_sales_net` rounds through its own `bcsub` chain while `expected_cash` rounds through a different nesting; `Big.RM = 1` re-rounds half-up at each step. The wave's own test pins the divergence (`apps/pos/src/lib/offline/__tests__/endOfDayPreview.test.ts:279-303`): opening `100.00` + cash sales `10.00` + movements `0.00` = `110.00`, but `expected_cash` = `110.01`. Money is `decimal(N,3)` at rest, so a `change_due` of `0.005` is representable. A cashier who counts `110.00` sees a 0.01 SHORT with no line explaining it, and the register demands a written reason. Recorded as the "one-minor-unit fractional display caveat"; SV-12 (Stage 5) owns the rounding line — but the mismatch is visible now and was not before M2.

**4. P3 — CONFIRMED — the SV-9 deploy ticket's device-cache note is stale after M4**
`docs/superpowers/tickets/2026-08-12-sv9-blind-count-default-deploy.md` (Device cache note)

It states that before the first successful settings sync "the end-of-day cash-count section is withheld by its null-settings gate." After M4 the **whole preview** is withheld and the close is blocked (`EndOfDayPreviewModal.tsx:233-256`). A deployer reading this ticket under-estimates the blast radius of an unsynced device — which is exactly the audience that needs finding 2.

**5. P3 — CONFIRMED — hand-rolled three-level `ar` merge is a partial-translation trap**
`apps/web/src/lib/i18n.ts:401-412`

`ar/compliance.json` is merged into `enCompliance` by spreading three fixed levels. A future `ar` key at a sibling path (e.g. `fraudSettings.fields.*`) would replace the whole English `fields` object instead of merging, silently dropping untranslated fallbacks. Every other namespace in the file registers as a whole file, and no test guards the merge shape. The blind-count label itself renders correctly — I ran it green.

**6. P3 — CONFIRMED — `defaultsForVertical(bool $_isAutomotive)` is a dead-parameter API kept alive for one caller**
`apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php:197`

It now ignores its argument and returns `getDefaults()`; the sole caller (`database/migrations/tenant/2026_04_25_100001_…php:38`) still computes and passes `$isAutomotive`. A method whose *name* asserts vertical-awareness that no longer exists is precisely the archaeology hazard M1 exists to stop, and underscore-prefixing an unused parameter is not a convention used elsewhere in this codebase.

## Bypasses I tried that FAILED (the implementation held)

- **Stage-2+/Lane-A0 contamination:** zero `app/Modules/Fiscal/**`, zero `app/Modules/POS/Commands/**`, zero `**/Domain/Events/**` in the diff.
- **Flag flip:** `shift_variance_gl_enabled => (bool) env('TREASURY_SHIFT_VARIANCE_GL_ENABLED', false)` is byte-identical; only the comment block changed.
- **Falsify the rewritten gate reason:** `grep -rn cash_drawer_operations apps/api/app` returns only POS files; `grep -rn opening_cash apps/api/app/Modules/Treasury` returns nothing. "Float + drawer ops unbooked in Treasury (SV-3/SV-4)" is factually true — the new stated gate is real.
- **Audit listing only pre-defended surfaces:** spot-checked three "clean" verdicts (`CashReconciliationSection.tsx:293,312`, `CashCountTable.tsx:63-64`, `ZReportListPage.tsx:78-118`); all three match the code.
- **Vacuous migration test:** seeds one `false` + one `true` row, asserts `changed=1/skipped=1` then `changed=0/skipped=2` in the same test, plus a real `information_schema.columns` default assertion. Ran on live PG (`autoerp_sv_stage1_m5rev_test`): 3/3, exit 0.
- **Silently-weakened red-by-design test:** `CompanyFraudSettingsVerticalDefaultsTest.php:43` renames the case *and* carries an explicit "SV-9 red by design" docblock.
- **Rule 19 drift:** no `parseFloat` / `Number(` / `toFixed` / `(float)` added anywhere in the diff.
- **New ar file regressing the web i18n ratchet:** `arLocaleCoverage.test.ts` was already 3-failed at base and does not enumerate `compliance`; failures are `common` / `workshop-technicians` / `vehicles`, unrelated.
- **Green-claim reproduction:** POS focused suite 89/89; web `FraudSettingsPage` 2/2; backend compliance/resolver/controller set 22 passing (92 assertions) on live PG; PHPStan level 8 on all six changed production PHP files → `[OK] No errors`; Pint → `pass`.

Findings 1 and 2 are P2 (close before merge). Finding 1 is a deploy-note fix; finding 2 needs an explicit owner ruling — the wave is handback-only, so this is the right moment to take it.

VERDICT: CHANGES-REQUIRED
