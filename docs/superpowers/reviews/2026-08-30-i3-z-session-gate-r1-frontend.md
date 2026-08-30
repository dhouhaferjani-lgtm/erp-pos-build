# Gate r1 — Session I lane I-3 (campaign legs L5b + L9, z_session chain)

- **Commit**: `40a97a8ba` in `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/i3-z-session` (branch `feat/i3-z-session-leg`, base `155686736`)
- **Brief r4**: `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I3-z-session-leg-BRIEF.md`
- **Lens**: frontend/e2e conventions (CLAUDE.md 1–5, 11, 17, 19, 20, 22; conventions 08/09/10/11)
- **Reviewer**: adversarial frontend-conventions gate, r1
- **Diff size**: 19 files, +1780 / −36

---

## 0. Guardrails re-run by me (nothing trusted as reported)

| Command | Result |
|---|---|
| `pnpm --dir apps/web campaign:fiscal-test` | **3 test files / 5 tests passed**, 327 ms, no network. Golden hashes reproduced: `SESSION_OPEN 74d7d69b…36e3`, `SESSION_CLOSE ad584a13…5742`, `Z_REPORT 352d1969…23ca` |
| `pnpm --dir apps/web typecheck:e2e` | **exit 0** |
| `pnpm --dir apps/web exec eslint e2e/campaign` | **exit 0, zero output** (0 errors / 0 warnings) |
| `pnpm --dir apps/web lint:eslint` (worktree) | `✖ 6458 problems (0 errors, 6458 warnings)` |
| `pnpm --dir apps/web lint:eslint` (main checkout, `dev` @ `efb543ab1`) | `✖ 6458 problems (0 errors, 6458 warnings)` — **delta 0** |
| `git diff --check 40a97a8ba~1..40a97a8ba` | exit 0 |
| `actionlint .github/workflows/onboarding-campaign.yml` (`/opt/homebrew/bin/actionlint`) | exit 0 |
| `tsc -p e2e/tsconfig.json --listFiles` | all 3 new `*.test.ts` **and** `e2e/campaign/vitest.config.ts` are inside the typecheck program (12 campaign files) |

`pgrep -fl eslint` was empty before both lint runs, so the repo-wide counts are uncontended.

**Live-evidence correction.** The ledger at `/private/tmp/claude-501/-Users-houssamr-Projects-syneriva-apps-erp/3cb82a5c-3461-4ae2-9d3e-61cf12813d58/scratchpad/ledger-run-24.json` (runId `20260830081241-55800`) is **not** "L0a–L9 PASS". It is:

`L0a PASS · L0 PASS · L1 PASS · L2 **FAIL** · L3 PASS · L4 PASS · L5 PASS · L5b PASS · L6 PASS · L7 PASS · L8 PASS · L9 PASS · L10 FAIL`

L2 FAIL and L10 FAIL are the pre-existing, documented I2-F2 unit-resolver finding (`docs/qa/ONBOARDING-CAMPAIGN.md:69`, owner Session G) — not a regression from this lane. The claim to carry into the merge thread is **"every leg this lane owns (L5b, L9) PASS; L6/L7 still PASS on the threaded session id; the run is red only on the pre-declared I2-F2 finding."** Do not restate it as a clean L0a–L9.

---

## 1. Brief-scoped verification results

**(1) No new UI selectors — CLEAN.** `git diff … | grep -E '^\+.*(page\.locator|getByRole|getByTestId|getByLabel)'` returns nothing. The only `.locator(` in the whole campaign tree remains the justified file input at `apps/web/e2e/campaign/selectors.ts:138`. The six additions to `selectors.ts:55-60` are all `apiRoutes` entries, matching brief §3's route list, and `/pos/shifts/{id}/receipts` is correctly absent.

**(3) Rule 19 — CLEAN.** No `parseFloat`, `Number(`, `toFixed`, `parseInt`, or arithmetic on money anywhere in the added lines. Money is scale-3 string literals throughout (`zSession.ts:254-258`), validated by an exact-scale regex (`zSession.ts:402-405`), and compared with the existing `assertMoneyEqual`. `Date.parse` appears only on timestamps (`zSession.ts:380-385`, `onboarding.campaign.ts:815-818`), which is correct.

**(4) Journey state typing — CLEAN.** The 12 new `journeyState` fields (`journey.ts:103,115,118-122,125-128`) are all optional `string`/`number`. Zero `any`, zero `as any`, zero `@ts-` and zero `eslint-disable` in the diff. `requiredState` still runtime-narrows to a non-empty string (`onboarding.campaign.ts:1177-1181`) and the new numeric accessor `requiredSequenceState` (`:1183-1191`) is keyed on a 3-literal union rather than widening `requiredState`'s contract — correct.

**`ensureSession` still valid.** `journey.ts:209` and `onboarding.campaign.ts:79` both widened `/^L\d+a?/` → `/^L\d+[a-z]?/`, so `L5b` resolves to leg `L5b` (greedy `\d+` takes `5`, `[a-z]?` takes `b`) and both L5b and L9 get `ensureSession`. `L10` still resolves to `L10`. Verified by the run-24 ledger, which carries a distinct `L5b` row.

**Reuse-mode preflight — PRESENT and correct.** `onboarding.campaign.ts:529-536` issues the three POS reads in parallel and asserts `200` with a message naming 403/"reuse principal lacks POS reads", before any ingest. Reuse mode cannot trip the new `operatorName` non-empty guard (`zSession.ts:349-351`) because `journey.ts:284` sets `name: 'reused tenant'`.

**(5) Docs — accurate.** `docs/qa/ONBOARDING-CAMPAIGN.md:35-39` legs table matches `journey.ts:78-86` and the test titles; the L9 `NOT_SCRIPTABLE` row is gone; the I3-F1..F5 known-red table (`:72-79`) with owners `POS/fiscal — owner routing` is exactly what brief §6 prescribes. `apps/web/e2e/campaign/README.md:19-21` leg list matches the code. The workflow is actionlint-clean.

**(6) Hygiene — mostly clean.** No whitespace errors, no `TODO`, no commented-out code. Residual `NOT_SCRIPTABLE` text: see MINOR-6 and MINOR-7 below.

**(2) Vitest config leakage — NO LEAK, confirmed by reading the configs.** `apps/web/vitest.config.ts:12` sets `include: ['src/**/*.{test,spec}.{ts,tsx}', 'tools/**/*.{test,spec}.{ts,mjs}']`, so `e2e/campaign/**/*.test.ts` cannot enter `pnpm test`. There is no `vitest.workspace.*` / `vitest.projects.*` in `apps/web`, so vitest never auto-discovers the nested `e2e/campaign/vitest.config.ts`; it is reachable only through the explicit `--config` in `package.json:20`. Confirmed empirically: the three new files are only picked up by `campaign:fiscal-test`.

**(2, cont.) Wrapper fail-fast — the claim is FALSE. See MAJOR-1.**

---

## Findings

### MAJOR-1 — The wrapper's "fail fast" comment is untrue: a broken builder does not fail `campaign-onboarding.sh`

`scripts/campaign-onboarding.sh:50-54`:

```bash
set +e
# Network-free contract tests for the vendored fiscal builders run first (fail fast, convention 08).
pnpm --dir apps/web campaign:fiscal-test
pnpm --dir apps/web campaign:onboarding
campaign_status=$?
set -e
```

`set -e` is explicitly disabled on line 50, and `campaign_status=$?` on line 54 captures the exit status of **line 53** (`campaign:onboarding`), not line 52. The fiscal-test exit code is discarded and the script exits with the campaign's status. Proven: `bash -c 'set -u; set +e; false; true; s=$?; set -e; echo $s'` → `0`.

So the comment asserts a guarantee the code does not provide — the same class the owner rules against for UI copy, applied here to a guard. A red contract test is silently swallowed on every local and staging run.

**Fix**: capture and short-circuit, e.g.
```bash
pnpm --dir apps/web campaign:fiscal-test || { echo "Campaign aborted: fiscal builder contract tests failed" >&2; exit 1; }
```
placed **before** `set +e` (line 50), leaving the existing `set +e`/`campaign_status` block for the Playwright run alone.

### MAJOR-2 — Convention 08 liveness: the new contract tests run in NO always-on CI lane

`campaign:fiscal-test` has exactly two call sites (grep across `*.yml`/`*.json`/`*.sh`):

- `.github/workflows/onboarding-campaign.yml:56-57` — inside a job gated by `.github/workflows/onboarding-campaign.yml:35` `if: github.event_name == 'workflow_dispatch' || vars.ONBOARDING_CAMPAIGN_ON_PUSH == 'true'`. The file's own header (`:1-2`) states push runs remain **inert** until the owner flips that variable. So on push→`dev` the whole job — including this step — is skipped.
- `scripts/campaign-onboarding.sh:52` — exit code discarded (MAJOR-1).

Everywhere else it is dead: it is not in the `lint` chain (`apps/web/package.json:10`), not in `scripts/preflight.sh` (grep: no `campaign` match), and not reachable from `pnpm test` (MAJOR-2's own §2 finding: root include excludes `e2e/**`). `frontend-lint` (`.github/workflows/ci.yml:2384`, ungated, runs on PR→dev) runs `pnpm test:eslint-rules && pnpm test:tools` at `:2454` but not this. `frontend-test` (`:2490`) runs `pnpm test` but is `if:`-gated to PR→main / push→main / dispatch at `:2494`, and would not collect these files anyway.

This is verbatim the pattern `docs/conventions/08-DETECTOR-LIVENESS.md` was written to prevent ("`pnpm test:eslint-rules` ran in no GitHub Actions workflow at all"). Partial mitigation: the L0a leg re-asserts the three golden hashes and the 15-key wrapper inside the Playwright run (`onboarding.campaign.ts:145-152`), so *hash drift* is still caught by a live campaign. But the **key-set contracts** (`zSession.test.ts:111-129`, the 13/28/32-key assertions that encode brief §1's server DTO shapes) and the **only negative test** exist solely in the vitest lane and are therefore CI-dead today.

**Fix**: append `&& pnpm campaign:fiscal-test` to the `frontend-lint` detector step at `.github/workflows/ci.yml:2454` — that job is ungated, already carries the "these suites were CI-dead" rationale in its own comment (`:2448-2454`), and inherits `all-checks-pass` membership without a new `needs` entry.

### MAJOR-3 — Builder negative coverage is 1 of ~12 validation branches, and it is the lane's only negative mechanism

Brief §4 rules out negative probes on the campaign terminal and states: *"Negative shapes are covered only as network-free contract assertions on the builders (wrong shape → the builder refuses)."* That makes the builder guards the lane's designated negative coverage — and convention 08 requires each guard to have a test proving it fires.

`zSession.ts:326-405` implements roughly twelve independent refusal branches: six `assertUuid` calls (`:327-336`), `sessionId !== shiftId` (`:337-339`), `businessDate` date pattern (`:340-342`), `eventTimeDevice` second precision (`:343-345`), currency-code shape (`:346-348`), non-empty `operatorName`/`terminalLabel` (`:349-351`), `assertHash` on the genesis/previous/sale/refund/open/close hashes, `assertMoney` exact scale (`:402-405`), `shiftNumber` positive integer (`:66-68`), operational sequence 1→2 (`:359-361`), period millisecond precision (`:362-369`), reporting-window containment (`:386-388`), event ordering (`:389-391`), and session sequence 1→2 (`:176-178`).

`zSession.test.ts:215-223` tamper-tests exactly **one** of them (millisecond outer timestamp). Eleven refusals have never been proven to fire — including the ones that protect immutable bytes from a wrong-scale money string or a swapped `sessionId`/`shiftId`.

**Fix**: add a `it.each` table in `zSession.test.ts` with one planted-violation row per refusal branch, asserting the specific `Error` message; target ≥1 firing test per `throw` site in `zSession.ts`.

### MINOR-1 — Semantic vector: 5 of 13 top-level groups are never cross-checked against the builder

`zSession.dry.test.ts:24-33` (and the identical block at `onboarding.campaign.ts:155-164`) `toMatchObject`s eight groups from `zSession.semantic.json` against `zReport.payload`. Not asserted: `cash_count`, `voids_totals`, `z_number`, `formatted_z_number` (all four exist verbatim as `zReport.payload` keys — `zSession.ts:183-191, 229, 230, 200`) and `event_times`. The citation-completeness check (`zSession.dry.test.ts:22-23`) only proves every leaf has *a* citation, not that the pinned value still equals what the builder emits. Those five can drift silently from the code they document.

**Fix**: add `cash_count`, `voids_totals`, `z_number`, `formatted_z_number` to the `toMatchObject` in both places, and assert `event_times` against `fixedZSessionCoordinates`.

I did spot-check the citations and they are real, not hallucinated: `zSessionAuthoring.ts:689-696` is the `sessionEventRange` literal, `zReportService.ts:748-754` is `operationalEventRange`, `:895-901` is the refund branch that keeps refunds out of the sale-only headline, and `:931-946`/`:1063-1084` are the `bcsub` netting that legitimately produces `payment_method_totals.total_amount = 0.000` / `transaction_count = 2` and the all-zero rate-19 VAT row. Those "zero" totals are device-faithful, not a defect.

### MINOR-2 — Three helpers duplicated across the new files

- `withMilliseconds` — `apps/web/e2e/campaign/fiscal/events.ts:340` and `apps/web/e2e/campaign/fiscal/zSession.ts:411` (byte-identical), plus a third variant `withMillisecondsTimestamp` at `apps/web/e2e/campaign/onboarding.campaign.ts:1207` (same transform, adds an input guard).
- The scale-3→scale-2 money truncator — `zSession.ts:407-409` (`scaledMoney`) and `onboarding.campaign.ts:1203-1205` (`fiscalMoney`), both `value.slice(0, -1)`.
- `semanticLeafPaths` — `onboarding.campaign.ts:1247-1255` and `zSession.dry.test.ts:62-71`, byte-identical.

**Fix**: hoist all three into `e2e/campaign/fiscal/` (a small `money.ts`/`time.ts`, or export from `events.ts`) and import; one surface per concept (`docs/conventions/11`).

### MINOR-3 — `slice(0, -1)` truncates rather than rounds when narrowing scale 3 → scale 2

`zSession.ts:408` and `onboarding.campaign.ts:1204` both drop the last character. It is correct today only because every literal ends in `0` (`zSession.ts:254-258`). A future `23.805` would silently become `23.80`. Not a rule-19 violation (no float, string in / string out) but a latent precision trap in a money path.

**Fix**: assert the dropped digit is `'0'` before slicing, or derive both scales from separate literals.

### MINOR-4 — Boolean-trap parameter silently disables a day-one invariant, and the evidence line overstates what held

`onboarding.campaign.ts:985` calls `assertDayOneCensus(await census(page, companyId), false)`; the parameter (`:1031`, guarding `:1042-1044`) turns off *"only the company's cash register and safe are provisioned"*. The relaxation is legitimate — L4 creates a third repository itself (`onboarding.campaign.ts:437-444`, `type: 'bank_account'`) — but the call site is a bare `false` with no comment, and the L9 evidence string at `:992` ends `"day-one census holds"`, which reads as if the full L0 census re-passed. It didn't; the strongest clause was skipped, and the campaign now has no assertion that *no unexpected* repository appeared between L4 and L9.

**Fix**: replace the boolean with an expected count (`assertDayOneCensus(census, { repositoryCount: 3 })` — cash register + safe + the campaign's own bank) so the invariant is kept rather than disabled, and amend the evidence string to `"day-one census holds (3 repositories: seeded drawer + safe + campaign bank)"`.

### MINOR-5 — The pinned known-defect assertion does not carry its finding id

`onboarding.campaign.ts:880`: `expect(zDetail['is_first_z_report'], 'known previous_z_hash legacy-surface defect').toBe(false)`. Pinning the *defect* value is explicitly ruled by brief §5 ("do not assert `true`"), so I am not re-litigating it — but when I3-F5 is fixed, L9 goes red with a message that does not name the doc row that explains why.

**Fix**: change the message to `'I3-F5 (previous_z_hash legacy surface) — flip to true when the projection stops writing the close hash; see docs/qa/ONBOARDING-CAMPAIGN.md'`.

### MINOR-6 — Stale ledger row: D-J0-5 still says L9 is `NOT_SCRIPTABLE`

`docs/handoff/LEDGER.md:226` reads *"I-3 follow-up — campaign leg L9 (cash count + Z) is NOT_SCRIPTABLE … OPEN — brief I-3 after I-2"*. This commit closes it. Also `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md:13` still frames declared gaps as expected — accurate as generic wording, no change needed there.

**Fix**: flip `docs/handoff/LEDGER.md:226` to CLOSED with `40a97a8ba` (orchestrator's file, not the lane's — noting it so it is not lost).

### MINOR-7 — `NOT_SCRIPTABLE` branches in `journey.ts` are now unreachable

With `journey.ts:148-150` changed to always seed `result: 'PENDING'`, no code path can set `NOT_SCRIPTABLE`, leaving the guards at `journey.ts:213` and `journey.ts:263` dead. Harmless and arguably deliberate scaffolding for a future declared gap (the `LegStatus` union at `:19` and the docs still describe the concept).

**Fix**: leave as-is, or add a one-line comment at `journey.ts:19` that the status is reserved for future declared gaps so a later reader does not read it as rot.

### MINOR-8 — `async` builders that never `await`

`buildSessionOpenEnvelope`/`buildSessionCloseEnvelope`/`buildZReportEnvelope` (`zSession.ts:60, 105, 163`) are `async` and return `Promise<AuthoredEnvelope>`, but `authorFiscalEnvelope` (`events.ts:227`) is synchronous. ESLint's `@typescript-eslint/require-await` is off for this tree (`apps/web/eslint.config.js:429`, pre-existing override, not added here), so it passes. It mirrors the existing `buildSaleEnvelope` signature, so consistency is the argument for keeping it.

**Fix (optional)**: none required; noted so a future reader does not assume async I/O in the sealing path.

### MINOR-9 — Two scale accessors for the same value

`onboarding.campaign.ts:550` and `:800` use `supportedFiscalScale()` (narrowed `2 | 3`), while `:599` and `:671` use the raw `currencyScaleForCountry(campaignCountry())` for the same country. `buildSaleEnvelope` then re-validates internally (`events.ts:328-330`). Not a bug; one accessor is clearer.

**Fix**: use `supportedFiscalScale()` at `:599` and `:671` too.

---

## Cross-cutting checks

- **Second-of-everything (`docs/conventions/09`)**: not triggered. This diff introduces no catalogue entity, no table, no unique key. The terminal it creates already had a create path in the pre-diff L6; L5b only relocates it. L0's existing second-company census (`onboarding.campaign.ts:180-209`) is untouched and still passes on run 24 (company 2 census present in the ledger evidence).
- **One surface per concept (`docs/conventions/11`)**: no new nouns escape the glossary — `session`/`shift`/`Z report` all pre-exist and the code correctly treats `session_id == shift_id` as one identity, enforced at `zSession.ts:337-339`. The only violation is the helper duplication (MINOR-2).
- **Benchmark-first (`docs/conventions/10`)**: satisfied upstream — the brief opens with the B1–B6 baseline table (`LANE-I3-z-session-leg-BRIEF.md:18-23`), every MATCH row is honoured, and each DEFER row is carried into the known-red table as I3-F1..F5.
- **Data-meaning tests**: strong. L9 asserts balances, projections, counts and idempotency (`onboarding.campaign.ts:840-843, 878-880, 934-935, 940, 949-953, 969, 976, 985`), not status codes — including the drawer-unchanged invariant against the *post-L8* `2250.500`, which is exactly the trap brief §5 (I3-R1-06) warned about, and the replay shape via the new `assertReplayedFiscalResult` (`:1158-1163`).
- **Owner UI rulings**: no user-facing surface touched; not applicable.
- **Baseline/mechanism audit**: `apps/web/tools/audit-design-system-baseline.json` untouched; no detector was weakened, no alias table, no suppression comment containing detector keywords, no `--write-baseline`. The one detector-adjacent change is the census relaxation (MINOR-4), which I traced to a campaign-created row rather than evasion. Repo-wide warning count is byte-identical to `dev` (6458/6458, 0 errors).

---

## Verdict

**APPROVE-WITH-FIXES** for merge into local `dev` (batch 3).

The lane delivers its contract: L5b and L9 both PASS live, L6/L7 still PASS on the threaded session id, the three builders reproduce pinned golden hashes deterministically, the 13/28/32 key sets match brief §1, the semantic vector's citations resolve to real device code, typecheck/eslint are 0/0, and the repo-wide warning ratchet is unmoved. Rule 19, rule 3 (no `any`), and the "no new UI selectors" constraint are all clean.

**Required before merge**: MAJOR-1 (one-line shell fix — a comment must not claim a guarantee the code does not provide).

**Required as a tracked follow-up in the same batch's ledger, not blocking the merge**: MAJOR-2 (wire `campaign:fiscal-test` into `frontend-lint` so the key-set contracts stop being CI-dead) and MAJOR-3 (tamper tests for the remaining eleven builder refusal branches, which are this lane's only negative coverage by brief §4's own design).

MINOR-1..MINOR-9 are optional polish; MINOR-4 and MINOR-6 are the two worth doing opportunistically.

**Also correct the merge-thread claim**: run 24 is not "L0a–L9 PASS" — L2 and L10 are FAIL on the pre-declared I2-F2 finding.
