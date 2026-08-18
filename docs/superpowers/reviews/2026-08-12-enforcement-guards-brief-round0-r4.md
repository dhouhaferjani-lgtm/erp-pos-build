# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r4 / dev `a5520f23c`
Runner: Claude (Fable 5), mechanical precheck session (full re-run from the top after the R0-3 fix) · Date: 2026-08-12

Verification tree: `/Users/houssamr/Projects/syneriva/apps/erp`, branch `dev`, tip `a5520f23c` — **identical to the r1/r2/r3 round-0 bases and to the brief's stated verification base** ("tip near `a5520f23c`"). `git status` shows `.github/workflows/ci.yml` and every cited code file unmodified in the working tree — the tree has NOT moved under any citation. Every check below was re-executed this run, nothing carried on trust (including the r3 report's own numbers — which is where the one finding comes from).

| # | Check                         | Result    | Findings |
|---|-------------------------------|-----------|----------|
| 1 | Revision-log truth            | PASS      | 0 — all 5 r4/R0-3 fix claims verified present (incl. all five H-3 locations + the three YAML line-5 comments); all 17 gate-r1 (r3) claims re-verified present in the r4 text; r2's 2 claims intact |
| 2 | Exhaustive claims proven      | PASS      | 0 — every census re-ran exact (93/16 allowlists, 16/4/0 architecture, 3-of-6 rule tests, 6 tools tests, 41-case 27/1/4/9 partition, 17 = 4C+11H+2M) |
| 3 | Test contracts executable     | PASS      | 0 FAIL — new r4 acceptance greps runnable as written (paths resolve from `apps/web`); O-3/O-8 placeholder observations carried |
| 4 | Behavior claims cited         | **FAIL**  | **1** (R0-4, MINOR severity, verdict-controlling) — three off-by-one step-line citations in the R0-3 fix text itself; every other citation (~75 re-checked) exact |
| 5 | Permission keys verified      | PASS      | 0 (brief names no permission/module keys — grep empty, exit 1) |
| H | Hygiene (pipes, banner)       | PASS      | 0 (4 tables, all pipe-consistent; banner r4 "NOT yet re-gated" = revision-log tail r4) |

**VERDICT: FAIL** (single-FAIL-fails-all, per the hard rule). One finding, mechanically trivial to fix (three characters in two lines), but it is a wrong point citation inside the very passage r4 exists to correct, so it cannot ride through to the gate. Everything else — including every conjunction the task ordered re-verified — is exact.

---

## Findings

- **[R0-4] Check 4, MINOR (verdict-controlling) — the three frontend-lint step-line citations are each off by one.** The brief cites the discrete steps as "`pnpm audit:keys` `:877`, `audit:design-system` `:880`, `audit:quantity` `:883`" in **two places**: the r4 revision-log entry (brief line 25) and the §3 P2 census row (brief line 184). The actual run-lines at `a5520f23c` (ci.yml unmodified in the working tree; three independent measurements — `grep -n`, `sed` window, `awk NR` — agree):
  - `run: pnpm audit:keys` → **`:876`** (`:877` is the blank separator line)
  - `run: pnpm audit:design-system` → **`:879`** (`:880` blank)
  - `run: pnpm audit:quantity` → **`:882`** (`:883` blank)
  The other anchors in the same sentences are EXACT: job span `:853-891` (next job `frontend-typecheck:` at `:893`), supersession comment at `:885` verbatim ("Supersedes a raw `pnpm lint`: …"), `run: pnpm lint:ratchet` at `:890`, `all-checks-pass` `needs` at `:1104` containing `frontend-lint`. The 2(c) range citation `:875-883` ("matching that job's existing step pattern") is fine — the region does contain the three name+run step pairs (`:875-882`).
  **Lineage (recorded for honesty, and because it is the R0-3 lesson repeating in miniature):** the identical wrong numbers appear in the r3 round-0 report (`docs/superpowers/reviews/2026-08-12-enforcement-guards-brief-round0-r3.md:21` and its appendix, marked "EXACT") — the r3 runner mis-derived them, and the r4 fix copied them from that report in good faith rather than re-deriving from the tree. A quoted report is not the tree.
  **Fix direction:** replace `:877`→`:876`, `:880`→`:879`, `:883`→`:882` in brief lines 25 and 184 (grep for `877` confirms no other occurrence in the brief; the three progress YAMLs cite only `:885`/`:1104`, both correct — grep exit 1). Bump to r5 with an honest log line, re-run round 0 from the top.

## Observations (non-failing)

- **[O-1] (carried, unchanged) Check 2 — counts sourced outside the repo tree:** "10 violations + 3 GL-gap grays", "~25–30 dev-days", "25+ live C6 violations". Not re-runnable here; the brief self-flags the register-path gap (F-5) and makes the register cross-check P1 deliverable 4.
- **[O-3] (carried) Check 3 — placeholder identifiers for to-be-authored artifacts:** P3's `--filter '^…UnbalancedGuard…$'`, `<path-to-country-chart-completeness-test>`, P1's `<architecture-job-id>` grep. Form exact, runnable once instantiated; `tests/Feature/{Treasury,BatchExpiry,Accounting}` all exist.
- **[O-4] (carried) `docs/handoff/reviews/` does not exist** — harmless: `scripts/adversarial-review.sh:47` does `mkdir -p "$(dirname "$OUT")"`.
- **[O-5] (carried, re-confirmed at `a5520f23c`) Executor conditionals' current state:** `gen-route-manifest.mjs:31-34` `WRAPPERS` still lacks `KeyedByRouteId`; `check-manifest-drift` in no workflow (grep count 0); `audit-design-system.mjs:59-63` `STATUS_RE` suffix alternation still lacks `Tone`; `ProvisioningRequiredPurposesV1` absent from HEAD (`git cat-file -e` exit 128 — correct, it is a P3-M0 precondition owned by the country-defaults lane, encoded as such).
- **[O-7] (carried) milestone-ID spelling:** YAMLs use bare `M0…Mn`, brief/bridge template uses `<package>-M<n>` — deliberate per the template's `--milestone` instruction, no mechanical break.
- **[O-8] (resolved in r4, noted for the gate)** the pre-r4 ambiguity about `pnpm lint`'s CI significance is fully closed: the acceptance block now labels it "LOCAL preflight parity only — the lint chain is NOT the CI wiring" (brief lines 235-236).

---

## Check 1 — revision-log truth

### r4 entry — the R0-3 fix, all 5 claims verified against the current text

| r4 claim | Where verified | Verified |
|---|---|---|
| (1) census row corrected to the true step list + supersession comment; `test:eslint-rules`/`tools/__tests__` CI-absence added to the runs-nowhere census | §3 census line 184 ("does NOT run `pnpm lint` … discrete steps … `:885` … LOCAL-ONLY") + line 186 ("added r4/R0-3 … `pnpm test:eslint-rules` … and `node --test tools/__tests__/`: zero workflow references … Every existing rule test and tools tamper test is CI-dead today; closing this is 2(d) deliverable 4") | **YES** (modulo R0-4's three line numbers inside the corrected row) |
| (2) 2(c) deliverable 3 rewritten — explicit discrete step in `frontend-lint`, never via the `lint` chain; acceptance greps `ci.yml`; H-9 `:1104` verify-at-base-wire-if-absent | §3 2(c) deliverable 3 (line 218) — "EXPLICIT DISCRETE STEP … NEVER via the `package.json` `lint` chain … a chain-only wiring ships a guard that runs in local preflight and never in CI"; acceptance block lines 240-244 carry both greps + the `:1104` re-verify instruction | **YES** |
| (3) 2(d) gains named deliverable 4 — discrete step or job (executor's choice, justified), running RuleTester suite AND tools tests, with planted-failing-rule-test tamper proof | §3 2(d) deliverable 4 (line 229), verbatim mechanism incl. "**Tamper proof (planted failing rule test):** plant a deliberately failing rule test … → it fails; revert → green; paste both outputs" | **YES** |
| (4) H-3 mechanism sentences updated in all five named locations (sequencing table, 2(c), P2 milestones, F-6, P2 YAML) | Sequencing table P2 row (line 39: "the job runs discrete steps, never `pnpm lint`, so the wiring is itself a workflow edit"); 2(c) deliverable 3 (line 218); P2 milestone list (line 255: "the previous 'pure additions…' label was false, and so was the pre-r4 mechanism"); F-6 (line 330: "mechanism corrected r4/R0-3"); `enforcement-p2.progress.yaml` line 13 (header comment) + M1 title (line 62) | **YES** — all five |
| YAML line-5 comments refreshed in all three files | `enforcement-p{1,2,3}.progress.yaml` line 5 each: "(r4 of the brief applies all 17 gate-r1 findings + the round-0 R0-3 fix; re-gate before dispatch)" | **YES** |

**Conjunction re-verification (the R0-3 lesson, ordered for this round):** the causal links themselves were checked in the tree, not just endpoints — (a) `frontend-lint` runs discrete steps and NOT `pnpm lint`: TRUE (job body `:853-891` contains four run-steps only: `:876/:879/:882/:890`; `grep -n 'pnpm lint\b' .github/workflows/*.yml` → only the `:885` comment and `:890` `lint:ratchet`); (b) `:885` supersession comment: EXACT verbatim; (c) `frontend-lint` ∈ `all-checks-pass` `needs`: TRUE at `:1104`; (d) **no other passage of the brief or any of the three YAMLs still relies on the `pnpm lint` chain as a CI mechanism**: full grep of `pnpm lint|lint chain` over all four files → every hit is either the corrected mechanism (lines 25/39/184/218/255; p2 YAML 13/62), a local-fact statement (line 213), or an explicitly-local acceptance command (lines 235-236; p2 YAML M4's local-evidence list). Zero residual false conjunctions.

### r3 entry — all 17 gate-r1 claims re-verified present in the r4 text (not carried from the r3 report)

The full brief was re-read this run; every one of the 17 named changes is present in the current text at the sections the r3 round-0 table recorded: C-1 (three YAMLs exist/parse/shape §below), C-2 (§4 3(a) rescope + green-at-base disqualifier verbatim), C-3 (§4 3(b) CONSUMER rewrite + DO-NOT-TOUCH frozen-seeder row + P3-M0 machine check), C-4 (§2 deliverable 3(c) anti-growth + §3 2(c) i18n inheritance), H-1 (never-foldable row + P1 YAML ancestry/S0 checks; `grep -in fold` → only removal statements), H-2 (2(a) four-branch ownership tree + OpenAPI reconciliation bullet), H-3 (reclassification present; mechanism now corrected), H-4 (write-method vocabulary + firstOrCreate fixture rows), H-5 (authored-locale provenance + alias/spread fixtures), H-6 (class-to-lane manifest + planted-class proof), H-7 (no-push everywhere; LEDGER S-14), H-8 (whole-package gates P1-M3/P2-M4/P3-M3 + lens→agent wiring, all 6 agent files exist), H-9 (aggregate membership deliverable + verify-branch check), H-10 (S-15 pointer-only in §0/§4/§6/§7), H-11 (three per-path phpunit lines), M-1 ("other static tests/scans" + OrphanedTypesCleanupTest gloss), M-2 (`git diff --stat <base_sha>..HEAD` + allowlist). The r2 entry's two claims (ParserFactory narrowed to the 4 users; verbatim cementing-guard quote) also intact and re-verified against source.

Banner ("r4 … R0-3 fixed … NOT yet re-gated") = revision-log tail. The r3 log entry still carries exactly 17 finding bullets = the gate register's 4 C + 11 H + 2 M.

## Companion artifacts

| Artifact | Exists | Parses (ruby psych) | Harness shape | Milestone `owner_gate:` | M0 commands runnable |
|---|---|---|---|---|---|
| `enforcement-p1.progress.yaml` | YES | YES | top-level `base_sha`/`branch`/`commit_series`/`owner_gates`/`milestones` (M0-M3) + pin `dpa_3c_merge_sha` | **NONE** | YES — dry-ran with HEAD substituted: `git merge-base --is-ancestor` OK; `git show HEAD:…StockAdjustmentService.php \| grep assertReferenceLinkagePaired` → 9 hits |
| `enforcement-p2.progress.yaml` | YES | YES | same + pin `quiet_window_ack` (M0-M4) | **NONE** | YES — null-tests + ownership-snapshot greps (ci.yml grep runs, 0 hits today as expected) |
| `enforcement-p3.progress.yaml` | YES | YES | same + pin `p1_landed_sha` (M0-M3) | **NONE** | YES — `git cat-file -e HEAD:…ProvisioningRequiredPurposesV1.php` exits 128 today (correct: precondition unmet; YAML says resolve-by-name if relocated) |
| es-wave-a0 / ui-wave0 (exemplars) | YES | YES | shape superset consistent | **NONE** | — |

Lens sets re-checked against §⚙️: P1 M1-M3 `stock-gl-interaction,inventory-costing`; P2 M1 `frontend-conventions`, M2 `+tenancy-authz`, M3 `frontend-conventions`, M4 both; P3 M1/M3 all four, M2 `treasury,fiscal-pos` — **all match exactly**. LEDGER: **S-14** (`docs/handoff/LEDGER.md:56`) and **S-15** (`:57`) present, wording matches (S-15 carries the verbatim "points here and carries no status of its own (gate-r1 H-10)"), pointers resolve both directions.

---

## Evidence appendix — full re-run at `a5520f23c`

### CI workflow (`.github/workflows/ci.yml`)

| Brief claim | Result |
|---|---|
| Job anchors `:27/:105/:143/:180/:853/:893/:918/:977/:1012/:1090` | ALL EXACT (`grep -n '^  [a-z-]*:$'`) |
| `frontend-lint` body `:853-891`; steps = 3 audits + ratchet, no `pnpm lint` | CONFIRMED — run-lines **`:876/:879/:882/:890`** (brief's `:877/:880/:883` = R0-4); comment `:885` verbatim; `working-directory: .` at `:891`; next job `:893` |
| Aggregate: `if:` `:1103`, `needs` `:1104` incl. `frontend-lint`; job `:1090-1107`; skipped-job comment block above `needs` | ALL EXACT |
| Only `--testsuite` invocation = Unit `:275`; `tests/Feature/Security` `:294`; Architecture only under `workflow_dispatch` (`:303` gate, `:316` ref); skip `if:` `:185` (+ comment `:183-184`); `frontend-test`/`frontend-build` `if:` `:922`/`:981`; pgsql provision comment `:226` | ALL EXACT |
| Allowlist `:629` = **93** entries (92 pipes+1), `AnalyticsTest` + `ExpenseAnalyticsTest` both present (substring-shadow mechanism stands); second allowlist `:726` = **16** (15 pipes+1) | MATCH |
| Serial-execution comment `:833-843`; Treasury/Accounting directory steps `:844-847` | EXACT |
| `test:eslint-rules` / `tools/__tests__` / `check-manifest-drift` in NO workflow | CONFIRMED (grep 0 hits each); `scripts/preflight.sh:193-195` invokes drift check locally; `scripts/factory/check-manifest-drift.sh` exists |

### Backend code

| Brief claim | Result |
|---|---|
| S0 seam: `recordMovement` `:1700`, params `:1715-1716`, `assertReferenceLinkagePaired` `:1719` (`StockAdjustmentService.php`) | EXACT |
| `StockLevel::firstOrCreate` `:1574-1590` (comment `:1574-1576`, call `:1578`, zero-valued defaults through `:1590`) | EXACT |
| `BatchStock::firstOrCreate` `StockAdjustmentService.php:1838-1863` (call at `:1847`); `BatchStockService.php:88-105` (`:94`), `:322-335` (`:325`) | ALL EXACT |
| `sealAndPersistEntry` `:3480` signature; `bccomp(...)!==0` → "Cannot post unbalanced journal entry" at `:3507-3509` (inside `:3480-3510`); `postEntry` `:2874` → `postEntryWithOptionalActor` `:2879`; `postEntryNow` `:3450`; `createPOSChargeEntry` returns Draft `:4072` | ALL EXACT |
| `TreasuryReceiptBridge` `postEntryNow` `:473,547,1387`; GLS injection `:153`; not-globally-unique comment `:323`; `$requiredPurposes = [` `:446`,`:528` | ALL EXACT |
| `InstrumentLifecycleService` `postEntryNow` `:274,456,488,847` | ALL EXACT |
| Writers/models exist: `ReverseWriteOffService` (GLS injection `:58`), `BatchWriteOffService`, `GroupedWriteOffService`, `FEFOInventoryService`, `TreasuryAccountChargeBridge`, `RepositoryAdjustmentService`, `AccountingService`, `JournalEntry.php`, `StockMovement.php`, `StockLevel.php`, `BatchStock.php` (BatchExpiry/Domain/Entities), `StockMovementReferenceType.php` (Shared/Domain/Enums) | ALL CONFIRMED |
| `phpunit.xml:17-18` Architecture suite; census **16** files / **4** ParserFactory users / **0** `RefreshDatabase`; `OrphanedTypesCleanupTest.php:15-29` filesystem assertion | ALL EXACT (re-counted) |
| `SystemAccountPurpose::expectedAccountType()` `:~186` (actual `:187`, tilde-qualified — OK); `ChartOfAccountsService::validateCompanyAccounts()` (`:47`) iterates `requiredPurposes()` at `:51` | EXACT |

### Frontend

| Brief claim | Result |
|---|---|
| `apps/web/package.json:10` lint chain verbatim (`lint:eslint && audit:keys && audit:design-system && audit:quantity && test:eslint-rules`); `:12` `test:eslint-rules`; `:13` `audit:keys` = TanStack (`audit-tanstack-keys.mjs`) | ALL EXACT |
| i18n: no completeness tool (`ls tools \| grep -ci i18n` → 0); `setup.ts:2` imports real `lib/i18n`; `ar` aliases `:392-405` (`catalog: enCatalog` … `smart-prompts`), `:416-424` (`refund-policies` … `stock-transfers`); spreads `:429-430`; `fallbackLng: 'en'` `:440` in init block `:435-442`; `ns` array `:442`; `i18nRawKeyCoverage.test.tsx` exists | ALL EXACT |
| `audit-quantity-display.mjs`: `partitionViolationsByBaseline` `:342` (consumed `:446`), writer `:418-442`, stale messaging `:449-461` | EXACT |
| RuleTester census: `.test.mjs` for exactly the 3 named rules; `no-parsefloat-on-money.js`/`no-untranslated-literal.js`/`no-hardcoded-entity-route.js` exist untested; `tools/__tests__/` = 6 files | EXACT |
| `gen-route-manifest.mjs:31-34` `WRAPPERS` lacks `KeyedByRouteId`; `audit-design-system.mjs:59-63` `STATUS_RE` lacks `Tone` | CONFIRMED |

### Harness / process / cross-lane

| Brief claim | Result |
|---|---|
| `SELF-REVIEW-HARNESS.md:49-50` populated milestone `owner_gate` = STOP; `:66-75` whole-branch final-milestone obligation (`:73-75` verbatim); schema `:12-19` | ALL EXACT |
| `adversarial-review.sh`: exit codes `:15`, `mkdir -p` `:47`, prompt heredoc `:49`, lenses-as-prose `:57` (`${LENSES:-general}`) — no agent file loaded, matching the brief | EXACT |
| All 6 named lens agent files exist in `.claude/agents/` | CONFIRMED |
| `CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:612-617` 3C gated+merged before 3D | EXACT |
| `CODEX-DISPATCH-ui-wave0-2026-08-11.md` T2 `:197` / drift hunk `:206`,`:239` / T3 `:226` / aggregate-needs contract `:239-247` / T7 `:350`; `ui-wave0.progress.yaml` M4 (`:64-65`) owns T3(b) explicitly | ALL EXACT |
| `HANDOVER-openapi-lane-orchestration-2026-08-07.md:32-36` lane live/running; a-to-z plan `:9-16,21-26` owns validation/coverage CI wiring | EXACT |
| Country-defaults dispatch: D-6 at `:57-66` (row `:64`); M1 `:289-318` ships `ProvisioningRequiredPurposesV1`, 27/1/4/9 partition verbatim `:301-302`; frozen-seeder markers `:314`; `country-defaults-phase-a.progress.yaml:33-40` M1 owns manifest | ALL EXACT |
| Sweep-audit quote `:136-139` verbatim; `PLAN-p0…:53` W-6 D1a/D1b; `AGENTS.md:16` Phase commit format; commits `bc67530a6`/`77d07de3c`/`7d85232cc` exist | ALL EXACT / CONFIRMED |

### Check 3 — test-contract executability

- P1 block: unchanged from r3 (phpunit by path, 5 tamper/ratchet proofs incl. C-4 anti-growth git-diff comparison, non-vacuous scope diff, aggregate grep with placeholder id — O-3). Runnable.
- P2 block: local commands runnable; **new r4 greps verified path-correct** — from `apps/web`, `../../.github/workflows/ci.yml` resolves to the repo-root workflow file; both greps exit 1 today (expected: they prove the deliverable once authored). No contract asserts through a mock of the thing under test.
- P3 block: three by-path phpunit lines + placeholders + phpstan (live-DB caveat disclosed §5). Runnable. No impossible interleavings, no lazy-row barriers.

### Check 5 / Hygiene

- `grep -oE "permission:[a-z._-]+|module:[A-Za-z]+"` over the brief → empty (exit 1). PASS (n/a).
- Scripted GFM check (fences excluded, backtick spans + escaped pipes masked): **4 tables (lines 36/71/96/119), 0 inconsistent rows**.
- Banner r4 = revision-log tail r4; "NOT yet re-gated" honest; UNVERIFIED items (F-5) still labeled.

---

**Disposition:** FAIL on R0-4 → per the hard rule, correct the three step-line numbers (`:876/:879/:882`) in brief lines 25 and 184, bump to r5 with an honest log line, re-run round 0 from the top. Everything else in r4 — including the entire R0-3 mechanism correction, all five H-3 locations, the new 2(d) deliverable 4, all conjunctions, and every census — verified exact; the gate-r2 dispatch is unblocked the moment these three numbers are fixed and r5 re-passes.
