# Gate r1 — PR #209 "fix(compliance): correct verify-chains response shape + panel field mapping (DEV-QA-035)"

| Field | Value |
|---|---|
| PR | #209 · `dhouhaferjani-lgtm` · head `fix/compliance-verify-chains-crash` · base `dev` |
| PR head sha | `b8f47ac7eb25b1053a2c1f60d8d20c8dd1296505` |
| Merged (gate) sha | `4e9e3d5bc8a125d651097c9b017e123bd9cc3a1d` (merge of local dev `d56d62535` + PR) |
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-209` |
| Reviewer | Fable adversarial merge gate, 2026-09-05 |
| Files | `apps/web/src/features/compliance/api/complianceApi.ts` (+ new `.test.ts`), `.../components/ChainVerificationPanel.tsx` (+ new `.test.tsx`) |

## Verdict: **CHANGES**

The diagnosis is correct and independently confirmed against the backend: the FE
was casting an **object** as an array and the field names were wholesale wrong.
The unwrap fix and the field realignment are exactly right. Merge is blocked on
two MAJOR issues the fix *introduces* by making the panel render for the first
time: it now displays a legacy-arm-only row count under a column labelled "Chain
Length" (an integrity panel overstating what was verified), and it renders raw
untranslated English backend diagnostic strings to the operator.

---

## Verified facts (with citations)

**The backend contract is exactly what the PR says it is.**
`apps/api/app/Modules/Compliance/Presentation/Controllers/Nf525ExportController.php:80-128`:
the response is `response()->json(['data' => ['company_id' => …, 'terminals' => $results, 'all_chains_valid' => …, 'verified_at' => …]])`
(lines 119-127), and each `$results[]` row (lines 91-110) carries exactly
`terminal_id`, `terminal_code`, `terminal_name`,
`receipt_chain{is_valid,total_receipts,verified,failed_at_sequence,error}`,
`z_report_chain{is_valid,total_reports,verified,failed_at_z_number,error}`,
`is_valid`. Route: `POST /compliance/nf525/verify-chains`
(`apps/api/app/Modules/Compliance/Presentation/routes.php:65`).

**The new FE type matches that shape field-for-field.**
`complianceApi.ts:11-30` vs the controller's array literal — every key present,
no invented key. `ChainVerificationResponse` (`complianceApi.ts:35-40`) matches the
envelope. The old type (`chain_length`, `first_receipt`, `broken_at_sequence`,
`verified_at` per sub-chain) matched **nothing** in the payload — the PR's claim
that rows would not have rendered even after an array fix is correct.

**Unwrapping is correct, not a double-unwrap.** `verifyChains`
(`complianceApi.ts:80-84`) uses the raw axios client `api.post`, so
`response.data` is the HTTP body `{data:{…}}` and `response.data.data.terminals`
is the right path. Single unwrap. This matches the file's existing convention for
non-standard envelopes (`exportJetXml` blob at `complianceApi.ts:63-70`,
`getReprintLog` meta-preserving at `:90-99`). Convention 01 satisfied.

**The crash was real.** Old line: `return (response.data as { data: ChainVerificationResult[] }).data`
returned the object; `ChainVerificationPanel.tsx:44` calls `results.every(...)` on
it → `TypeError` → white screen. The author's correction of the original report's
"unguarded `.every()`" diagnosis is accurate: line 44 *was* null-guarded
(`results !== null && results.every(...)`); the fault was the type/shape lie.

**TanStack key: N/A, not a violation.** The panel does not use React Query at all —
it is `useState` + a manual `await` (`ChainVerificationPanel.tsx:26-42`). So there
is no query key to scope. `audit-tanstack-keys.mjs` reports 0 new. (That the panel
bypasses convention 05 entirely is pre-existing and untouched by this PR.)

**i18n:** the PR adds **no new translation keys**. Every `t()` call in the touched
lines (`chainVerification.brokenAt` at `:127,141`) already exists in
`src/locales/en/compliance.json` and `fr/compliance.json`.

**Design tokens:** touched lines use `semanticColorTokens` only
(`ChainVerificationPanel.tsx:125,139`). No literal colour classes, no interpolated
variant/opacity onto a token. Design-system audit: 810 acknowledged, **0 new**.

---

## Findings

### 1. MAJOR — the "Chain Length" column now presents a **legacy-arm-only** count as the verified chain size (owner rule: UI must not overstate system guarantees)
`apps/web/src/features/compliance/components/ChainVerificationPanel.tsx:133-135`
(`{result.receipt_chain.total_receipts}`) rendered under the header
`t('chainVerification.chainLength')` = **"Chain Length" / "Longueur"**
(`ChainVerificationPanel.tsx:105-107`, `src/locales/en/compliance.json`).

The backend explicitly documents that this number is **not** the terminal's chain
length: `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:382-395`
— *"Total rows + verified rows are scoped to the **legacy arm** for backward
compatibility with the DTO + UI surface"* — and the query is
`Receipt::where('terminal_id',…)->whereNull('fiscal_event_id')->where('is_training',false)`
(lines 390-394). On a Phase-1 tenant (every receipt has `fiscal_event_id`) the
legacy set is empty, the method early-returns `isValid: true, totalRows: 0`
(`Nf525DataProvider.php:410-418`), and the panel will show a green **"Valid"**
badge next to **"Chain Length: 0"** for a terminal holding thousands of sealed
receipts — an authoritative-looking verdict over a count that means nothing to the
operator. The fiscal-events arm returns only a boolean
(`Nf525DataProvider.php:396-408`).

Note the mapping itself is faithful — the misleading semantics live in the backend
DTO — but this PR is what makes the number visible for the first time.
**Fix (FE, cheap):** stop rendering a bare count under "Chain Length". Either drop
the column, or render `verified / total_receipts` with a re-labelled key
(e.g. `chainVerification.legacyRowsVerified`) plus a t()'d caveat that Phase-1
receipts are verified as a single canonical-bytes pass. Do not ship a green
verdict whose only quantitative evidence is a legacy-subset zero.

### 2. MAJOR — raw untranslated English backend diagnostics are rendered into the operator UI (CLAUDE.md rule 11)
`apps/web/src/features/compliance/components/ChainVerificationPanel.tsx:129` and `:143`
```tsx
{result.receipt_chain.error !== null && <> {result.receipt_chain.error}</>}
```
The strings are hardcoded English technical text on the backend:
`'Fiscal-events chain break: canonical_bytes rehash or linkage mismatch (see structured log entry for diagnostic)'`
(`Nf525DataProvider.php:404`),
`'Chain linkage broken: previous_hash mismatch'` (`:430`),
`'sealed_hash_algorithm anomaly: NULL after backfill completion for this terminal'` (`:471`),
`'Fiscal hash mismatch: receipt data may have been tampered with'` (`:481`),
`'Z-report chain break: canonical z_session chain or legacy Z-report linkage mismatch'` (`:522`).
A French or Arabic NF525 auditor gets English developer prose — and
`"sealed_hash_algorithm anomaly: NULL after backfill completion"` is not operator
copy in any language. This is new user-facing text introduced by this PR.
**Fix:** have the backend emit a stable machine `error_code` and map it to
`compliance:chainVerification.errors.<code>` on the FE; until that exists, put the
raw string behind a t()-labelled "technical details" disclosure rather than inline
next to the status badge.

### 3. MINOR — `verified` and `is_valid` are typed but never rendered; the panel recomputes a verdict the backend already sent
`complianceApi.ts:18,25,29` declare `verified` and the row-level `is_valid`;
`ChainVerificationResponse.all_chains_valid` (`:38`) and `verified_at` (`:39`) are
declared too. None are used. `ChainVerificationPanel.tsx:44-49` recomputes
`allValid` / `hasBroken` client-side instead of using `all_chains_valid` — two
sources for one verdict (one-surface-per-concept), and `verified_at` is dropped so
the panel shows no "as of" timestamp for an audit artefact.
**Fix:** render `verified_at` (an integrity verdict without a timestamp is not
audit evidence), and either use `all_chains_valid` or delete the unused fields.

### 4. MINOR — dead `company_id` request body; the backend deliberately ignores it
`complianceApi.ts:81` posts `{ company_id: companyId }`. The controller resolves the
company **exclusively** from `CompanyContext` and explicitly refuses body
`company_id` as a cross-tenant exfiltration fix
(`Nf525ExportController.php:29-36`, `:82`). Sending it is harmless but implies the
client controls the scope. Same for `exportJetXml` (`complianceApi.ts:66`, pre-existing).
**Fix:** drop the body field (and the now-unused `companyId` parameter, or keep the
param only as the panel's "a company is selected" guard).

### 5. MINOR — the hand-rolled type has no generated counterpart; the drift that caused this bug can recur silently
`complianceApi.ts:11-30`. `grep 'ChainVerification' packages/shared/types/generated.d.ts` →
**no hits**, because `Nf525ExportController::verifyChains` builds a raw array
literal (lines 91-110) rather than a `#[TypeScript]`-attributed Spatie DTO. So this
is *not* a hand-rolled type shadowing a generated one (rule 7 is not violated as
written) — but nothing type-checks the FE against the BE, which is precisely how
`chain_length` / `broken_at_sequence` drifted in the first place. The author's
"do NOT rename them" comment (`complianceApi.ts:7-9`) is a comment, not a guard.
**Fix (backend follow-up lane):** return a `#[TypeScript]` DTO from `verifyChains`
and re-export the generated type on the FE.

### 6. MINOR — name collision: a second exported `ChainVerificationResult` in the web app
`apps/web/src/features/pos/api/reportApi.ts:100-104`
(`{is_valid, broken_at_z_number, broken_at_id}` for `POST /pos/reports/z/verify-chain`)
vs `complianceApi.ts:11`. Different endpoints and shapes, identical exported name.
Pre-existing, not introduced here; recorded under one-surface-per-concept so a
future import does not silently grab the wrong one.

### 7. MINOR (pre-existing) — Arabic compliance namespace is empty
`src/locales/ar/compliance.json` has **no** `chainVerification` object and no
`exportError` key (verified by parse). The whole panel falls back to English on an
Arabic tenant via `fallbackLng: 'en'` (`src/lib/i18n.ts:483`). Not caused by this
PR; belongs to the Arabic backfill lane.

---

## Falsifiability of the new tests (reasoned from the diff, not asserted)
- `complianceApi.test.ts:55-62` — with the old body (`return (response.data as {data: ChainVerificationResult[]}).data`) the returned value is the **object**, so `expect(Array.isArray(result)).toBe(true)` fails. **Red without the fix.** This is the root-cause guard and it is the right one.
- `ChainVerificationPanel.test.tsx:49-58` — `getByText('42')` requires `total_receipts` to be rendered; the old cell read `chain_length` (absent → renders nothing). **Red without the fix.**
- `ChainVerificationPanel.test.tsx:60-69` — the old broken-chain branch was gated on `broken_at_sequence !== null` (absent → `undefined !== null` is true, but the rendered value is `undefined` → nothing), and the `error` span did not exist at all, so `getByText(/hash mismatch at #7/)` fails. **Red without the fix.**
- `ChainVerificationPanel.test.tsx:71-79` (empty payload) — green before and after; a guard, not a falsifier. Fine, but do not count it as regression coverage.
- Fixture realism: `complianceApi.test.ts:16-48` reproduces the controller's array literal key-for-key, including the `data.data` nesting. This is a **real** fixture, derived from the backend — good practice and the opposite of the invented shape that caused the bug. The panel test mocks `verifyChains` itself, so the unwrap is only covered by the api test — acceptable split.

---

## Guardrail evidence (verbatim, re-run by the reviewer in the merged worktree)

`cd .worktrees/pr-209/apps/web && ./node_modules/.bin/vitest run src/features/compliance/api/complianceApi.test.ts src/features/compliance/components/ChainVerificationPanel.test.tsx`:
```
 RUN  v3.2.4 /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-209/apps/web

 ✓ src/features/compliance/api/complianceApi.test.ts (3 tests) 4ms
 ✓ src/features/compliance/components/ChainVerificationPanel.test.tsx (3 tests) 136ms

 Test Files  2 passed (2)
      Tests  6 passed (6)
   Start at  14:46:08
   Duration  2.23s (transform 516ms, setup 1.27s, collect 635ms, tests 140ms, environment 855ms, prepare 202ms)
```

Remaining compliance files (to check the "33/33 compliance tests" claim) —
`./node_modules/.bin/vitest run src/features/compliance/__tests__/tenantScope.test.tsx src/features/compliance/components/CashDrawerControlsSection.test.tsx src/features/compliance/pages/FraudSettingsPage.test.tsx src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx`:
```
 ✓ src/features/compliance/__tests__/tenantScope.test.tsx (13 tests) 379ms
 ✓ src/features/compliance/components/CashDrawerControlsSection.test.tsx (8 tests) 347ms
 ✓ src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx (4 tests) 711ms

 Test Files  4 passed (4)
      Tests  27 passed (27)
   Start at  14:46:24
   Duration  2.91s (transform 985ms, setup 3.25s, collect 2.60s, tests 1.74s, environment 2.15s, prepare 315ms)
```
27 + 6 = **33**. The author's "33/33 compliance tests pass" claim is **verified**.

`./node_modules/.bin/eslint <4 touched files>`:
```
/…/complianceApi.test.ts
  12:30  warning  A method that is not declared with `this: void` … @typescript-eslint/unbound-method
/…/complianceApi.ts
  69:10  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion
  82:20  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion
  99:10  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion
/…/ChainVerificationPanel.tsx
  44:20  warning  Prefer using an optional chain expression … @typescript-eslint/prefer-optional-chain
  47:21  warning  Prefer using an optional chain expression … @typescript-eslint/prefer-optional-chain

✖ 6 problems (0 errors, 6 warnings)
```
**0 errors.** Line 82 is a *new* warning on her line (the `response.data` assertion);
lines 69/99 and 44/47 are pre-existing.

`./node_modules/.bin/tsc --noEmit`:
```
TSC EXIT: 0
```
(no output — the `Array.isArray(payload?.terminals) ? payload.terminals : []` narrowing does compile under strict)

Audit tools:
```
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```
Baseline honesty: **no baseline file in the diff** (4 files, all under `features/compliance/`). No `--write-baseline` absorption, no alias/suppression indirection.

Merge cleanliness: `git diff-tree --cc 4e9e3d5bc` is **empty** → clean merge, no
conflict resolution. `git log 4d5b8812e..d56d62535 -- complianceApi.ts ChainVerificationPanel.tsx`
is empty → the dev-side eslint autofix commit `5524b9a69` did not touch these files.

Commit hygiene: single commit, conventional prefix, body accurately describes the
root cause and even corrects the original bug report. Scope: FE-only, 4 files, no creep.

## Could not verify
- **The DEV-QA registry is not in this repo.** `grep -rl 'DEV-QA-035'` across the
  checkout returns nothing; the ticket text/priority could not be read.
- **No live call to `POST /compliance/nf525/verify-chains`** was made. Finding 1
  (legacy-arm-only counts) is derived from `Nf525DataProvider.php:382-395,410-418`,
  not observed on a running tenant. A browser/API pass on a POS tenant with sealed
  receipts would confirm the "Valid / 0" rendering.
- **Not manually recette'd in-browser** (the author says the same); the white-screen
  fix is proven by unit test, not by clicking "Verify All Chains".
- No PHP was run (no backend change in the diff; the POS/Compliance PHPUnit suites
  were not executed).
- RTL/Arabic rendering of the panel not checked.

## Merge to local dev: **NO** — pending fix round on findings 1-2 (findings 3-4 are cheap to fold in).
