# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r1 / dev `a5520f23c`
Runner: Claude (Fable 5), mechanical precheck session · Date: 2026-08-12

Verification tree: `/Users/houssamr/Projects/syneriva/apps/erp`, branch `dev`, tip `a5520f23c` — **identical to the brief's own stated verification base**, so every citation was checked against exactly the tree the brief claims to describe. No drift excuse applies.

| # | Check                         | Result    | Findings |
|---|-------------------------------|-----------|----------|
| 1 | Revision-log truth            | PASS      | 0 (r1 first draft; banner internally consistent; no fix-claims exist yet) |
| 2 | Exhaustive claims proven      | PASS      | 0 FAIL, 2 observations |
| 3 | Test contracts executable     | PASS      | 0 FAIL, 1 observation |
| 4 | Behavior claims cited         | **FAIL**  | **2** (R0-1, R0-2) |
| 5 | Permission keys verified      | PASS      | 0 (brief names no permission/module keys — grep empty) |
| H | Hygiene (pipes, banner)       | PASS      | 0 |

**VERDICT: FAIL** (single-FAIL-fails-all). Two findings, both wording-level and trivially fixable; everything load-bearing in the brief verified exactly. Fix, bump to r2, re-run round 0 in full.

---

## Findings

- **[R0-1] Check 4, MINOR-but-mechanical — §2 Deliverable 1, "the existing `tests/Architecture/*.php` use `ParserFactory` + visitors and none use `RefreshDatabase` — verified".** Half true. `grep -l 'RefreshDatabase' tests/Architecture/*.php` → empty (that half verified). But `grep -L 'ParserFactory\|PhpParser'` shows only **4 of 16** test classes use PHP-Parser AST (`AuthLifecycleTest`, `BroadcastChannelTenantContextTest`, `TenantScopedExistsRulesTest`, `TenantScopedFindCallsTest`); the other 12 use `token_get_all`/`preg_match`/other static file scans (e.g. `ControllerTenantContextTest`, `QueueJobTenantContextTest`, `TreasuryBalanceWritePortTest`). The universal "the existing tests use ParserFactory + visitors" is false as stated. The deliverable direction (PHP-Parser AST is *a* house pattern, no DB) survives, but a claim marked "verified" must be accurate. Fix: reword to "several of the existing tests (e.g. `TenantScopedFindCallsTest`) use `ParserFactory` + visitors; none use `RefreshDatabase`".
- **[R0-2] Check 4, MINOR-but-mechanical — §1 Mission, quote presented as verbatim: *the plan's own words: "Cementing guard (last): architecture test forbidding undocumented JE/StockMovement/StockLevel writes"*.** The source — `docs/superpowers/audits/2026-08-08-document-per-action-violation-sweep.md:136-139` — actually reads: "**Cementing guard (after fixes):** architecture test forbidding `JournalEntry::create` / `StockMovement::create` / `StockLevel` writes outside document-keyed services with non-self `source_*` linkage (PHPStan rule or deptrac layer + pinned test), so this class can't regrow." Meaning preserved (guard scheduled after remediation — the brief's inversion argument stands), but a paraphrase must not wear quotation marks and the label "the plan's own words". Fix: either quote verbatim with the file:line, or drop the quotation marks and say "paraphrasing §7 of the sweep plan".

## Observations (non-failing)

- **[O-1] Check 2 — counts sourced outside the repo tree:** "10 violations + 3 GL-gap grays" and "~25–30 dev-days" cite the DPA audit register/plan, and "25+ live C6 violations" cites UI Wave 0 T7. Not mechanically re-runnable here; the brief self-flags the register-path gap (F-5) and makes the register cross-check a deliverable (P1 D4), which is the honest handling. The underlying mechanism claim for C6 IS verified (see appendix: `STATUS_RE` at `audit-design-system.mjs:59-63` matches `Colors|Classes|Maps|Styles|Config|Badge` suffixes, no `Tone`).
- **[O-2] Check 2 — sweep-plan wording:** the source says "Cementing guard (after fixes)" — supports the brief's "scheduled last" characterization; see R0-2 for the quote-form issue only.
- **[O-3] Check 3 — placeholder identifiers in P3 acceptance** (`--filter '^…UnbalancedGuard…$'`, `<path-to-country-chart-completeness-test>`): commands are for tests the package itself authors; form is exact and runnable once instantiated, `tests/Feature/Treasury` exists. Acceptable for a dispatch brief; the executor's handback must paste the concrete commands.
- **[O-4] `docs/handoff/reviews/` does not exist yet** — harmless: `scripts/adversarial-review.sh:47` does `mkdir -p "$(dirname "$OUT")"`.
- **[O-5] Current-state confirmations the executor's conditionals depend on, as of `a5520f23c`:** UI Wave 0 T2 NOT landed (`gen-route-manifest.mjs:31-34` `WRAPPERS` lacks `KeyedByRouteId` — grep exit 1); T3(b) NOT landed (no `check-manifest-drift` in any workflow); T7 NOT landed (`STATUS_RE` still lacks `Tone`). All three of the brief's "check whether it landed" branches will take the "not landed" path if the base is today's tree.

---

## Evidence appendix — every re-verified claim

### Code citations (Check 1/4 census — all exact unless noted)

| Brief claim | Re-verified | Result |
|---|---|---|
| S0 seam: `StockAdjustmentService::recordMovement` at `:1700`, params `?StockMovementReferenceType $referenceType = null` / `?string $referenceId = null` at `:1715-1716`, `assertReferenceLinkagePaired` at `:1719` | `sed -n '1695,1725p'` on the file | EXACT |
| `AccountingService` injects `DoubleEntryValidator` `:54`; `assertLegsBalance` `:313-356`; call sites `:503` (invoice), `:716` (credit note), `:929` (cancellation); uses `isSumBalanced` | grep + sed; `isSumBalanced` at :333, body ends :356 | EXACT |
| `GeneralLedgerService` `postEntry` `:2874`, `postEntryNow` `:3450` (at `app/Modules/Accounting/Domain/Services/`) | grep -n | EXACT |
| `requiredPurposes()` at `SystemAccountPurpose.php:160` returns **13** (11 + `CostOfGoodsSold` + `GeneralExpense`), with the "France booked zero COGS silently (PostCOGSOnInvoice swallows the miss)" comment | sed -n '160,182p'; counted 13 cases | EXACT |
| `expectedAccountType()` at `:~186` | actual :187 (tilde-qualified) | OK |
| `ChartOfAccountsService::validateCompanyAccounts()` iterates the list at `:51` | `foreach (SystemAccountPurpose::requiredPurposes() ...)` at :51 | EXACT |
| `TreasuryReceiptBridge` local required-purposes lists `:446`, `:528` | `$requiredPurposes = [` at :446 and :528 | EXACT |
| `journal_entries(source_type,source_id)` not globally unique | `TreasuryReceiptBridge.php:323` comment states exactly this | OK |
| Writers: `TreasuryReceiptBridge`, `TreasuryAccountChargeBridge`, `RepositoryAdjustmentService`, `InstrumentLifecycleService`, `ReverseWriteOffService` create JEs | 3 reference `JournalEntry` directly; `TreasuryReceiptBridge` + `ReverseWriteOffService` post via injected `GeneralLedgerService` (`:153` / `:58`) | OK |
| BatchStock writers `FEFOInventoryService`, `BatchWriteOffService`, `GroupedWriteOffService`, `ReverseWriteOffService` exist | all found (GroupedWriteOffService at `Application/Services/`) | OK |
| Model paths: `JournalEntry.php`, `StockMovement.php`, `StockLevel.php`, `BatchStock.php`, `StockMovementReferenceType.php` | all exist at cited paths | EXACT |
| `phpunit.xml:17-18` defines suite `Architecture` → `tests/Architecture` | lines 17-18 | EXACT |
| `tests/Architecture` tests "use ParserFactory + visitors, none RefreshDatabase" | 4/16 use PHP-Parser; 0 use RefreshDatabase | **R0-1 FAIL** |
| `TenantScopedFindCallsTest` header documents `#[Group('sweep-progress')]` informational pattern | :26 comment, :35 attribute | EXACT |
| `tests/Architecture/fixtures/` exists | plus BroadcastFixtures/ControllerFixtures/WebhookFixtures | OK |
| Sweep-plan quote "Cementing guard (last): …" | source says "(after fixes)" + different wording | **R0-2 FAIL** |
| `PLAN-p0-fix-lanes-pre-production-2026-08-05.md` §W-6 at `:53` (D1a DoubleEntryValidator; D1b −19.000 campaign test money, not a code fix) | W-6 D1a at line 53, verbatim match incl. D1b disposition | EXACT |
| `AGENTS.md:16` commit format `Phase <major.minor.patch>: <imperative summary>` | line 16 | EXACT |
| `usePermissions.ts:130-134` `canAccessModule` fails open (checklist trap, for the record) | `if (!requiredPermissions) return true` | EXACT |

### CI workflow citations (`.github/workflows/ci.yml`)

| Brief claim | Result |
|---|---|
| Job anchors: `backend-lint` :27 · `backend-analyse` :105 · `backend-architecture` :143 · `backend-test` :180 · `frontend-lint` :853 · `frontend-typecheck` :893 · `frontend-test` :918 · `frontend-build` :977 · `types-drift` :1012 · `all-checks-pass` :1090 | ALL EXACT |
| Only `--testsuite` invocation is `Unit` at `:275` | EXACT (only other mentions: comment :287, comment :298) |
| `tests/Feature/Security` whole-dir at `:294`, inside `backend-test` (so not on PR→dev); celebration comment `:284-293` | EXACT |
| `backend-test` skip comment `:180-185` ("Heavy job… Skipped on PR→dev — local preflight is the gate") | EXACT (comment :183-184, `if:` :185) |
| pgsql service "for future Feature-suite coverage that opts into it explicitly" `:226-230` | comment tail at :226 — in range |
| Allowlist at `:629` = **93** class names | re-counted: 92 pipes + 1 = **93** — MATCH (`AnalyticsTest` and `ExpenseAnalyticsTest` both present, substring-shadow claim mechanically plausible: `--filter` is unanchored) |
| Second allowlist at `:726` = **16** entries | 15 pipes + 1 = **16** — MATCH (`BackfillChartPurposesMigrationTest` confirmed in the :629 list, as §4 3(b) claims) |
| Directory-inclusion pattern `:844-847` (`tests/Feature/Treasury`, `tests/Feature/Accounting`) | EXACT; both dirs exist |
| `all-checks-pass` skipped on PR→dev, `:1090-1103` | `if:` at :1103 says exactly that (`needs` at :1104, one past the cited range — tolerable) |
| Architecture suite "runs in NO automatic CI lane; full suite only manual `workflow_dispatch`" | the single `tests/Architecture` hit at :316 is inside the step gated `if: github.event_name == 'workflow_dispatch'` (:303) — CONFIRMED |
| deptrac ratchet command `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` in `backend-architecture` | at :178 — EXACT |
| Route-manifest drift check: `scripts/preflight.sh:193-195`, zero workflow references | preflight :193-195 exact; `grep -rn manifest .github/workflows/` hits only the unrelated saleReceipt chokepoint-manifest — CONFIRMED |

### Frontend citations

| Brief claim | Result |
|---|---|
| `apps/web/package.json:10` lint chain `lint:eslint && audit:keys && audit:design-system && audit:quantity && test:eslint-rules` | EXACT; `audit:keys` (:13) = `node tools/audit-tanstack-keys.mjs` (TanStack, not i18n — confirmed) |
| `gen-route-manifest.mjs:31-34` `WRAPPERS` set, `KeyedByRouteId` absent | EXACT; grep for `KeyedByRouteId` exits 1 |
| ESLint RuleTester coverage: tests exist for `no-dead-tailwind-token-interpolation`, `no-hardcoded-step`, `no-literal-decimal-places`; none for `no-parsefloat-on-money.js`, `no-untranslated-literal.js`, `no-hardcoded-entity-route.js` | EXACT (ls of `eslint-rules/`; `test:eslint-rules` script confirms the three) |
| `tools/__tests__/` exists with design-system/quantity/tanstack tests | CONFIRMED (6 test files) |
| `audit-quantity-display.mjs` `partitionViolationsByBaseline` `:342-358`; stale-entry ratchet messaging `:449-461` | function at :342; shrink-only stale-entry output in :449-461 — EXACT |
| No i18n completeness check anywhere; `setup.ts:2` imports real `lib/i18n`; `fallbackLng: 'en'` at `i18n.ts:440`; `ns` array `:442`; `ar` en-fallback spreads `:~430`; only key guard is `i18nRawKeyCoverage.test.tsx` | ALL CONFIRMED (no i18n tool in `tools/`; spreads `{...enNotifications, ...arNotifications}` at :429-430; coverage test file exists) |
| C6: `STATUS_RE` never matched `Tone`/`Tones` | `audit-design-system.mjs:59-63` suffix alternation is `Colors?|Classes?|Maps?|Styles?|Config|Badge` — no Tone. CONFIRMED (T7 not landed) |

### Harness / process citations

| Brief claim | Result |
|---|---|
| `docs/handoff/SELF-REVIEW-HARNESS.md`, `scripts/adversarial-review.sh` exist; flags `--brief/--milestone/--lenses/--range/--out/--round`; `max_fix_rounds` default 5 | ALL CONFIRMED |
| `docs/handoff/progress/` ledger dir with in-flight lane files | CONFIRMED (7 progress YAMLs incl. wave3-3c-3d, ui-wave0, country-defaults-phase-a) |
| `docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md` T2 (:197), T3 (:226, includes the drift CI job), T7 (:350) as described | CONFIRMED — T2 cites the same `WRAPPERS :31-34` evidence |
| Commits `bc67530a6` (Wave-3 merge) and `77d07de3c` (3C M1 resume) exist locally | `git cat-file -t` → commit, both |
| Base state: brief verified against tip "near `a5520f23c`" | tip IS `a5520f23c` — exact |

### Check 3 — test-contract executability

- P1 block: `cd apps/api && ./vendor/bin/phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php` — exact form, path is the deliverable itself; tamper/ratchet proofs are fixture-based (no mock-of-subject); `git diff --stat` guard-only proof — runnable.
- P2 block: `pnpm lint`, `node tools/audit-i18n-completeness.mjs` (deliverable), `node --test tools/__tests__/` (dir exists; Node 20 accepts directory args), `pnpm test:eslint-rules` — runnable.
- P3 block: by-path PHPUnit with placeholders for to-be-authored tests (O-3); `./vendor/bin/phpstan` with live-DB caveat disclosed in §5 — runnable.
- No contract asserts through a mock of the thing under test anywhere in the brief; tamper tests are planted-violation fixtures throughout. No impossible interleavings or lazy-row barriers proposed.

### Check 5 — permission/module keys

`grep -oE "permission:[a-z._-]+|module:[A-Za-z]+"` over the brief → empty. The brief names no permission or module keys; nothing to verify against `RolesAndPermissionsSeeder.php` / `MODULE_PERMISSIONS`. PASS (n/a).

### Hygiene

- GFM pipe check (scripted, code-fences excluded, backtick spans masked): all table rows in every table have consistent cell counts; zero literal `|` inside code spans within table cells.
- Banner: `Revision: r1 — 2026-08-12, first draft, NOT yet brief-gated` — no revision log exists yet to contradict it; sequencing/UNVERIFIED flags (F-5) are honestly labeled. PASS.

---

**Disposition:** FAIL on Check 4 → per the hard rule, fix R0-1 and R0-2 (both are one-sentence rewordings), bump the brief to r2 with an honest revision-log entry, and re-run round 0 from the top before dispatching the brief gate.
