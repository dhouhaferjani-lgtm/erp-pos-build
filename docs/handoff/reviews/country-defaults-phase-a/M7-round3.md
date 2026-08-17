## M7 Adversarial Merge-Gate Review — Round 3
**Base:** `7d85232cc` → **HEAD:** `98f7dd53d` · **Lenses:** treasury, tenancy-authz, frontend-conventions
**Owner authority:** `docs/handoff/reviews/country-defaults-phase-a/OWNER-RESUMPTION-M7-2026-08-17.md` (accepted; it bounds the fixture to a disposable DB and leaves G1–G5 open)
**Brief section:** `docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:582-800` (M7 manifest + items 5b/8/9/10/11)

### What I verified myself at this HEAD (not read from the report)

- **Round-3 delta is documentation-only and code is frozen at the specialists' snapshot.** `git diff --name-only c6dc598c5..HEAD` = 6 files, all under `docs/` — zero `apps/`, `scripts/`, `.github/` bytes. `git status --porcelain` empty before and after every command I ran. So the specialist verdicts pinned to `c6dc598c5` and round 2's executable evidence apply verbatim to `98f7dd53d`.
- **Union completeness re-derived mechanically.** Extracted 57 literal paths from `$PHASE_A_TESTS` — all 57 exist on disk; the §1.1 table and the manifest block match (the single apparent diff was my own regex dropping the digit in `…PurposesV1Conformance…`). Every changed test file in `git diff --name-only 7d85232cc..HEAD -- apps/api/tests` (65) is in the manifest except 8 non-runnable artifacts (6 golden fixtures + `tests/Support/Attributes/UsesFrozenSeederFixture.php` + `tests/Support/CountryDefaults/M4Fixtures.php`). R1's finding 1 is closed by construction, not by assertion.
- **My own green.** `php artisan test tests/Unit/CountryDefaults tests/Feature/CountryDefaults/{ProvisioningFlagMatrixTest,DefaultsEditorLoginFlagTest,NoExistingCompanyMutationTest,FrozenSeederProvisioningIsolationTest}.php` → **83 passed / 775 assertions** on SQLite at HEAD.
- **Assertion 8(6) non-vacuous — proved by experiment.** `COUNTRY_DEFAULTS_PROVISIONING_ENABLED=true COUNTRY_DEFAULTS_EXTERNAL_EDITORS_ENABLED=true` makes **both** default-off assertions fail: `ProvisioningFlagMatrixTest.php:38` ("Failed asserting that true is false") and `DefaultsEditorLoginFlagTest.php:18`. `config/country_defaults.php:6-7` defaults both to `false`.
- **`ext-intl` (R1 finding 5) fully closed, including the half nobody checked:** all 8 `setup-php` blocks carry `intl` (`ci.yml:40,118,156,234,376,678,798,1030`), the branch's entire `ci.yml` diff *is* that addition, `apps/api/Dockerfile:73` installs it, **and `composer.lock` was regenerated** — `platform: {ext-intl: *, php: ^8.2}`, `composer validate` → "is valid". A stale lock would have made the hard requirement decorative; it isn't.
- **Standing checks.** Rule 19: no `(float)`/`floatval`/`number_format`/`round(` added anywhere in `apps/api` in the whole branch diff; Phase A is non-monetary. No `app(` introduced in `apps/api/app` production code (`VerifyCountryDefaultsCommand.php:26-30` is constructor-injected). No `onQueue` added → no Horizon coverage obligation. `routes/api.php` diff is **exactly** the sanctioned one-line `super_admin` → `central_admin` widening of the nested logout/`me` group (`:44`); the full-admin group is untouched. N-G honored: `apps/api/docker/entrypoint.sh` unchanged. Migrations sit top-level (`database/migrations/2026_08_11_1000/1001/1002/1003…`), no `central/` subdir, and `config/tenancy.php:195-199` scopes `tenants:migrate` to `database/migrations/tenant` — so the central DDL cannot be replayed per tenant. i18n: backend `country_defaults` 10 keys en/fr with zero asymmetry; FE `adminCountryDefaults` **192/192** en/fr, registered in all three `i18n.ts` places plus the `ns` array; `ar` falls back to English exactly as `stock-transfers`/`stock-adjustments` already do (`i18n.ts:428-436`).
- **FE gates re-run by me:** `audit-tanstack-keys.mjs` → Gate C 0 unscoped keys, 0 new/stale; `audit-design-system.mjs` → 735 acknowledged, **0 new / 0 stale**. FE bytes unchanged since `ec414c4a9`, the commit the report says the frontend specialist approved.

### Round-2 register — closure status

| # | R2 severity | Status |
|---|---|---|
| **1** | **P2 (blocking) — specialist verdicts unattached** | **CLOSED** — three files now committed at the harness `--out` path (`M7-specialist-{treasury,tenancy-authz,frontend-conventions}-final.md`), each naming its reviewer and frozen HEAD `c6dc598c5`; I independently re-verified their substantive claims (see below) |
| 2 | P3 ledger drift | **CLOSED** — `country-defaults-phase-a.progress.yaml:91-96` now `status: review`, `fix_rounds: 3`, `commit: d7ba40a6…`, `verdict: …/M7-round2.md` |
| 3, 4, 5, 6 | P3 notes | carried forward below (4 now bounded much more tightly) |

---

## Findings register (round 3)

**1 — P3 · PLAUSIBLE · `docs/sessions/codex-country-defaults-phase-a-report.md:1815-1820` vs the three committed verdict files (7 / 14 / 28 lines)**
The report calls these "**the complete specialist outputs**". They are terse summaries in the report's own voice with **zero `file:line` citations** — unlike every other register in this wave (`M5-round5.md`, `M6-round4.md` cite dozens). Whether they are the reviewers' verbatim output or the implementer's paraphrase is not decidable from the branch.
*Failure scenario:* a promoter treats three paraphrases as three reviewer transcripts. Non-blocking because manifest item 9 requires *verdicts*, not transcripts; the verdicts now carry reviewer identity and a frozen SHA; and I re-verified their load-bearing claims myself (code frozen since `c6dc598c5`; runner guards fail-closed at `scripts/phase-a-authenticated-verifier-fixture.sh:22-29,55-75,116-131,145-170`; FE/type bytes frozen; audits 0-new). *Fix (cheap):* drop "complete" or attach the raw output.

**2 — P3 · CONFIRMED · `docs/handoff/reviews/country-defaults-phase-a/M7-specialist-frontend-conventions-final.md:6`**
"unchanged from the prior approved commit" names no commit. Resolved by me: the last FE-touching commit is `916efdc9a` (`TemplateListPage.tsx` + test, the `lifecycleMutationPending` guard); `git diff ec414c4a9..HEAD -- apps/web packages/shared` is **empty**, so the referent is `ec414c4a9`, which *includes* that change — the verdict is true but only after external reconstruction.

**3 — P3 · CONFIRMED · `docs/handoff/progress/country-defaults-phase-a.progress.yaml:95-99,17-19`**
At HEAD the ledger still reads `last_verdict: CHANGES-REQUIRED` and wave `status: in_progress` (correct pre-round-3 state), and owner gate `spec-read-through` is `status: open`. Whoever promotes must read the round-3 register, not the YAML, and must not read an M7 ACCEPT as closure of the owner gates — G1–G5 (human HTTP certification + `country-defaults:verify` on staging **and** production before the Release-2 flip) remain open per `OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md:94-100` and the resumption record.

**4 — P3 · CONFIRMED (carry-over R1#8/R2#4, now bounded) · `apps/api/database/seeders/ExpenseCategorySeeder.php:75-110`**
The loud-failure conversion still ships un-flag-gated in Release 1. I pushed harder than prior rounds on whether it can break live company creation, and it cannot on any standard path: every mapped code exists in its chart (`6130/6170/6250/6256` in `GenericChartOfAccountsSeeder`, `613/615/616/624/626/6061/6064` in both `Tunisia…` and `France…`), `GeneralExpense` is seeded in all three, and every caller seeds the chart first — `TenantInitializationService.php:81` before `:239`, `CompanyController.php:156` before `:165`, `ParapharmacySeeder.php:622` before `:646`, `TunisianParapharmacySeeder.php:76` before `:103`. Residual exposure is genuine certification drift only (custom template lacking a mapped class-6 code), which is the intended semantics. Disclosed at checklist §G5 with an M5 P3 ticket.

**5 — P3 · CONFIRMED (carry-over R2#6) · `scripts/phase-a-authenticated-verifier-fixture.sh:22-29`**
Destructive allowlist is name-only; `PGHOST` is unconstrained, so a `autoerp_country_defaults_*_test|scratch` database on a **remote** host would be `migrate:fresh`-ed. Every other guard in the runner is fail-closed (URL rejection `:55-60`, config-cache refusal `:64-70`, full field pinning `:78-97`, effective-config jq assertion `:116-131`, dual live `current_database()` check `:145-170`).

**6 — P3 · CONFIRMED (carry-over R2#5) · `apps/api/tests/Feature/Accounting/DocumentCancellationGlReversalTest.php:326-347` vs `.github/workflows/ci.yml:848`**
Re-verified independently: `grep -c DocumentCancellationGlReversalTest .github/workflows/ci.yml` = **0**; `tests/Feature/Accounting` runs under the default SQLite config, where the forked-concurrency case self-skips. The reworked `connectionsToTransact()` narrowing and the PG-only destructive `migrate:fresh` tearDown execute in no CI lane. Pre-existing gap; this branch's only `ci.yml` edit is the `intl` addition.

**7 — P3 · CONFIRMED · `docs/sessions/codex-country-defaults-phase-a-report.md:1658-1676`**
The report retains superseded round-0 figures (36-file union / 86 static files / 41 FE tests / "210-case inventory") in narrative position ahead of the final ones (57 / 93 / 42). A promoter quoting the first numbers they find would cite the evidence set R1 proved incomplete. Chronology only, no code impact.

## Bypasses I tried that FAILED to find a defect

- Union escape: 57 manifest paths vs §1.1 table vs all 65 changed test files — **no runnable suite outside the union** (first attempt gave a false negative because my `git diff` pathspec was relative to `apps/api`; re-run from repo root).
- Flag-default vacuity: forced both env flags true → **both** default-off assertions fail (not just the editor half, as in R1).
- `ext-intl` enforcement hole: checked whether `composer.lock` was left stale so the platform requirement never binds → lock regenerated, `platform.ext-intl` present, `composer validate` passes.
- Central DDL replayed per tenant by the auto-deploy `tenants:migrate` → `config/tenancy.php:197` pins `--path` to `migrations/tenant`; top-level central migrations are unreachable there.
- Auth-surface over-widening beyond the sanctioned S-3 edit → `routes/api.php` diff is one line; the `Route::prefix('admin')` group is byte-identical.
- Release-1 live break from the un-gated expense loud failure → all mapped codes present in all three charts, `GeneralExpense` present, chart-before-categories ordering in all four callers.
- Rule 19 / `app()` / new named queues / `entrypoint.sh` drift in the branch diff → none.
- FE convention drift: ran both audit ratchets myself → 0 new, 0 stale; en/fr key parity 192/192; `ar` fallback precedented.
- FE re-approval gap: hunted for FE bytes changed after the frontend specialist's snapshot → none since `ec414c4a9`.

## Lens coverage

- **treasury** — applied. No COA/purpose/GL bytes changed in rounds 2–3; the union now executes `InstrumentAccountResolverTest`, `PayableInstrumentAccountsTest`, `SeedChartsCommandTest`, `CompanyTaxProvisioningServiceTest` and the four `Backfill*` suites. Protected-code and capability-authority guards are AST/token-level and non-vacuous (`CapabilityAuthoritySingularityTest.php:31-66` asserts a *singleton file set* for `implements CountryAccountingCapabilities`, `STAMP_DUTY_COUNTRIES`, `CAPABILITY_VERSION` plus a semantic renamed-authority fixture). `country-defaults:verify` fails closed on missing bootstrap keys, missing TN/FR/`*` assignments, unpublished/domain-mismatched templates, stale `capability_registry_version`, scope violations and content-hash drift (`VerifyCountryDefaultsCommand.php:35-120`) — assertion 8(3) is real.
- **tenancy-authz** — applied. One-line sanctioned auth widening; `EnsureCentralAdmin` retains the active check; three central migrations additive on the central connection, discovered by the production top-level path, unreachable from `tenants:migrate`; no new queues; the only central-admin-adjacent new artifact is the fixture runner, whose destructive path is fail-closed on URL inheritance, config cache, effective config, and dual live database identity.
- **frontend-conventions** — applied. Nothing changed since `ec414c4a9`; canonical components, tokens, `t()`-only strings, both lint ratchets 0-new, 42-test scope, en+fr complete.

## Disposition

Round 2's sole blocking finding is closed at the artifact level and its substance re-verified independently. Nothing in rounds 1–3 remains at P1 or P2. All seven surviving items are P3 — five are carry-overs the harness permits to ship with a recorded ticket, two are documentation-fidelity nits (findings 1, 7) worth a one-commit cleanup. **M7 passes as a merge gate; it does not close owner gates G1–G5, and the branch must not be promoted as "certified" — Release 1 ships with both flags off, human HTTP certification and `country-defaults:verify` on staging *and* production still owed before the Release-2 flip.**

VERDICT: ACCEPT
