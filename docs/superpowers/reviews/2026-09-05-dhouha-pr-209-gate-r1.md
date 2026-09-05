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

---
---

# Gate r2 — PR #209 fix round 1

| Field | Value |
|---|---|
| Re-gated sha | `7d0beb28371719f9bd0a3d54677836b0c782b7f9` (branch `gate/pr-209`) |
| Fix commit | `afc97db0a` `fix(compliance): honest chain verdict + translated diagnostics (PR #209 gate r1)` |
| Handback | `docs/superpowers/reviews/2026-09-05-dhouha-pr-209-fix-round-1-handback.md` (in-worktree) |
| Delta vs r1 gate sha `4e9e3d5bc` | 10 code/test/locale files + 1 doc (new `lib/chainDiagnostics.ts` + test, `i18n.ts`, en/fr/ar `compliance.json`) |
| Reviewer | Fable adversarial merge gate, 2026-09-05 |

## Verdict: **CHANGES**

Four of the five workstreams in this round are genuinely excellent and verified
against primary sources: the Z-column claim, the diagnostics mapping, the envelope
rework, and the i18n deep-merge all hold up under adversarial checking. But the
remedy chosen for r1 finding 1 **over-corrects into a second, opposite
misstatement**: the panel now tells an NF525 auditor that the event-chain arm "is
not covered by this check" — and the backend proves it *is* covered. On the normal
modern fleet that copy fires on **every** terminal, painting a permanently amber
"Not covered" panel over a chain that was just fully verified. One MAJOR blocks;
everything else is closed.

---

## Finding-by-finding re-check

### r1 finding 2 (MAJOR, raw English diagnostics) — **CLOSED, and well done**
New `apps/web/src/features/compliance/lib/chainDiagnostics.ts:32-56` maps five stable
prefixes to `compliance` keys. I re-derived the backend inventory independently —
`grep` for `error:` in `Nf525DataProvider.php` returns exactly five literals, at
`:404`, `:430`, `:471`, `:481`, `:522` — and every one is covered:

| `Nf525DataProvider.php` | prefix matched | key |
|---|---|---|
| `:404` `'Fiscal-events chain break: canonical_bytes rehash or linkage mismatch (see structured log entry for diagnostic)'` | `Fiscal-events chain break:` | `…errors.fiscalEventsChainBreak` |
| `:430` `'Chain linkage broken: previous_hash mismatch'` | `Chain linkage broken:` | `…errors.legacyLinkageBroken` |
| `:471` `'sealed_hash_algorithm anomaly: NULL after backfill completion for this terminal'` | `sealed_hash_algorithm anomaly:` | `…errors.sealedHashAlgorithmAnomaly` |
| `:481` `'Fiscal hash mismatch: receipt data may have been tampered with'` | `Fiscal hash mismatch:` | `…errors.fiscalHashMismatch` |
| `:522` `'Z-report chain break: canonical z_session chain or legacy Z-report linkage mismatch'` | `Z-report chain break:` | `…errors.zReportChainBreak` |

**Complete: 5/5, no invented sixth.** No prefix is a prefix of another, so the
`.find(...startsWith)` at `chainDiagnostics.ts:63-67` cannot mis-dispatch.
`chainDiagnostics.test.ts:9-33` pins all five literals **verbatim**, so a backend
reword goes red here instead of silently degrading — exactly the right guard.
Unknown strings return `null` (`:66`) and render as `<code class="font-mono break-all">`
behind a translated `technicalDetail` label (`ChainVerificationPanel.tsx:52-57`),
which is the compromise the r1 report asked for. Both the receipt cell (`:85`) and
the Z cell (`:250-252`) route through it.

### r1 finding 3 (MINOR, `verified_at` / duplicated verdict) — **CLOSED**
`complianceApi.ts:87-102` now returns the whole normalised envelope with every field
defensively coerced (`company_id`/`verified_at` via `typeof === 'string'`,
`all_chains_valid` via `=== true`, `terminals` via `Array.isArray`), so a malformed
body degrades without a crash **and** without inventing a `true` verdict — note
`all_chains_valid` defaults to `false`, which is the safe direction for an integrity
panel. `ChainVerificationPanel.tsx:133` uses the backend's `all_chains_valid` instead
of the client-side `every/some` pair, and `:169-175` renders `verified_at` through
`formatDateTime` (`lib/format.ts:301-316`, which returns `''` on an unparseable date
— no `Invalid Date` leak).

### r1 finding 4 (MINOR, dead `company_id` body) — **CLOSED**
`complianceApi.ts:96` → `api.post('/compliance/nf525/verify-chains', {})`, parameter
removed. Re-verified safe: `Nf525ExportController::verifyChains` resolves the company
solely via `$this->companyContext->requireCompanyId()` (`Nf525ExportController.php:82`)
and never reads `$request->input('company_id')`; the class docblock at `:29-36`
states body `company_id` "is no longer accepted" (it was a cross-tenant exfiltration
vector). The panel keeps its `if (!currentCompanyId) return` guard
(`ChainVerificationPanel.tsx:117`), so "no company selected" behaviour is unchanged.
`complianceApi.test.ts:70-80` asserts the empty body — a genuine contract test.

### Z-column claim — **VERIFIED**
The lane asserts `verifyZReportChain` counts every Z-report, so the Z column may keep
the `chainLength` label. Confirmed at `Nf525DataProvider.php:500-502`:
`ZReport::where('terminal_id', $terminalId)->orderBy('z_number')->get()` — **no**
`whereNull(...)`, **no** `is_training` filter — and `totalRows: $zReports->count()`
at `:519` / `:528`. The Z "Chain Length" is honest. Keeping the two columns on
different labels is correct, not an inconsistency.

### i18n deep-merge — **VERIFIED, scoped, and audit-clean**
`apps/web/src/lib/i18n.ts:435-445` adds an explicit `chainVerification` (and nested
`errors`) merge inside the **`ar.compliance`** resource only. Three things checked:
1. **Ordering is correct** — the explicit `chainVerification:` key appears *after*
   `...enCompliance, ...arCompliance` (`:431-434`), so it wins over the shallow spread.
2. **No other namespace's behaviour changes** — `git diff` on `i18n.ts` is +11/-0, all
   inside the `compliance:` object; it mirrors the pre-existing `fraudSettings` merge
   at `:446-449`. Nothing else in the 55-namespace resource tree is touched.
3. **The hazard she describes is real** — without the merge, `...arCompliance`
   (shallow) would have replaced the whole English `chainVerification` object with the
   partial Arabic one, blanking the 15 pre-existing keys in Arabic. Fixing it in the
   same round is correct, not scope creep.

`bash ../../scripts/i18n-baseline-authority.sh` → **exit 0**, and the counts move by
exactly the claimed amount: `en 9501 → 9513`, `fr 9518 → 9530`, `ar 5146 → 5158`
(+12 each). Known-gap count is **unchanged at 2816** and `git diff dev HEAD -- apps/web/tools/ scripts/`
is **empty** — the new Arabic keys were closed by *authoring translations*, not by
absorbing them into the baseline. That is the honest mechanism.
`src/lib/i18nRawKeyCoverage.test.tsx` (5) and `src/lib/__tests__/i18nPosZReportsShadowing.test.ts` (4)
were re-run because `i18n.ts` is shared: **9/9 green**.

### Falsifiability — claim holds, and is if anything understated
Reverting the panel render only:
- `:105` "renders a row per terminal" → count cells would be `'42'`/`'10'`, expected `'42 / 42'`/`'6 / 10'` → **RED**
- `:133` "translates a known backend diagnostic" → raw English present → **RED**
- `:149` "unknown diagnostic as technical detail" → rendered in a `<span>`, `tagName` assert fails → **RED**
- `:172` "does NOT show a green Valid for an empty legacy arm" → old cell shows `Valid` → **RED**
- `:218` "renders the verification timestamp" → `verified_at` never rendered → **RED**
- `:118` "shows the failing terminal", `:203` "keeps the plain all-valid banner" → green
**5 of 8 red**, exactly as claimed. On the API side, reverting `verifyChains` reddens
the envelope test (`:64`), the empty-body test (`:70`) and both degrade tests — i.e.
**at least 3**, not the 1 the handback conservatively claims. Understating your own
coverage is the harmless direction.

---

## New findings in r2

### 1. MAJOR (BLOCKING) — the new copy asserts the event-chain arm was **not** verified; the backend proves it was
`apps/web/src/locales/en/compliance.json` →
`chainVerification.legacyArmOnlyNote`: *"No legacy-arm receipts to verify. **The event-chain arm is not covered by this check.**"*
and `chainVerification.allValidWithLegacyGap`: *"…this check covers the legacy arm only, and **the event-chain arm is not covered**."*
Rendered at `ChainVerificationPanel.tsx:99-101` (with a caution `Not covered` badge,
`:98`) and `:183-189` (fleet banner). Same assertion in `fr` and `ar`.

**That is false.** Read the call order in
`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php`:
```php
// (1) Fiscal-events arm — delegate to the rebuilt verifier.   :396-407
if ($terminal !== null) {
    $fiscalEventsArmOk = $this->receiptHashService->verifyTerminalChainFiscalArm($terminal);
    if (! $fiscalEventsArmOk) { return /* isValid: false, error: 'Fiscal-events chain break: …' */ }
}
// (2) Legacy arm                                              :409-418
if ($legacyReceipts->isEmpty()) {
    return new Nf525ChainVerificationResult(isValid: true, totalRows: 0, verifiedRows: 0, …);
}
```
The event arm runs **before** the legacy early-return, and it is not a stub:
`ReceiptHashService::verifyTerminalChainFiscalArm`
(`apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:267-340`) joins
`fiscal_events` to `pos_receipts`, groups into one stream per
`(company_id, chain_context)` (`:316-321`) and walks/rehashes every row
(`:322-333`). So `is_valid: true, total_receipts: 0` means **"the event chain was
verified and passed, and there were additionally no legacy rows"** — not "nothing was
checked". The handback itself records the correct fact ("the fiscal-events arm
returns only a boolean") and then draws the opposite conclusion in the copy.

Consequences, in the common case:
- The legacy arm is explicitly described as ageing out post-Phase-1
  (`Nf525DataProvider.php:359-363`), so a **new tenant has zero legacy receipts on
  every terminal**. Every row gets an amber `Not covered` badge and the fleet banner
  is permanently the caution `allValidWithLegacyGap` variant — a standing amber alarm
  over a fleet whose fiscal chain has just been fully verified. That trains operators
  to ignore the panel (owner signal-overload principle) and would send an NF525
  auditor chasing a gap that does not exist.
- The one case where "nothing was verified" is genuinely true — a terminal with **no
  fiscal_events rows at all**, where `inspectFiscalEventsArm` returns `(true, 0)`
  vacuously (`ReceiptHashService.php:292-294`) — is **indistinguishable from the busy
  Phase-1 terminal in this payload**. The response carries no event-arm count, so the
  FE cannot tell them apart, and picking the pessimistic reading and stating it as
  fact is not a neutral choice.

**Fix (FE-only, this round):** say only what the payload supports, and drop the alarm
tone for the normal case.
- `legacyArmOnlyNote` → something like *"No legacy-arm receipts on this terminal. This check reports a verified-row count for legacy receipts only; event-chained receipts are not counted here."* — describes the **count**, does not deny the **verification**.
- The badge should not be a caution tone when the backend returned a pass: keep the `valid` badge (the backend's verdict) with the note beside it, or introduce a neutral/informational tone, not amber.
- Fleet banner: keep the plain `allValid` and, if a footnote is wanted, make it neutral — do not replace a green pass with an amber caveat on the standard fleet.

**Durable fix (backend follow-up lane, cite it in the handback):** the event-arm count
already exists — `ReceiptChainArmVerificationResult` carries `count` /
`inspectedCount` (`ReceiptHashService.php:296-300, 335-339`) and is simply discarded
by `verifyTerminalChainFiscalArm`'s `->isValid` (`:269`). Plumbing those two numbers
into `Nf525ChainVerificationResult` and the controller payload lets the panel state
the truth with a number instead of guessing.

### 2. MINOR — a terminal with zero receipts of any kind still reads as an anomaly
Same code path (`ChainVerificationPanel.tsx:95-104`). A brand-new terminal that has
simply never sold anything gets the caution treatment. Folding this into the finding-1
rewording (neutral tone + count-scoped wording) resolves it too.

### 3. MINOR — the row-level `is_valid` is now the only unused field
`complianceApi.ts:29`. `all_chains_valid`, `verified_at`, `company_id` and `verified`
are all consumed after this round; `is_valid` is not. Either render it or drop it —
a declared-but-unread field is how the original `chain_length` drift started.

### 4. MINOR (carried, unchanged) — no generated DTO for this endpoint
`grep 'ChainVerification' packages/shared/types/generated.d.ts` → still no hits,
because the controller builds a raw array literal (`Nf525ExportController.php:91-110`).
The prefix table in `chainDiagnostics.ts` is now a **second** untyped coupling to the
same backend. Both are pinned by tests, which is the best available FE-side mitigation,
but the backend `#[TypeScript]` DTO + `error_code` follow-up is now owed twice over.

### 5. MINOR (carried) — `exportJetXml` still posts a dead `company_id`
`complianceApi.ts:66`. Correctly declared out of scope in the handback; recorded so it
is not lost.

---

## Guardrail evidence (verbatim, re-run by the reviewer at `7d0beb283`)

`./node_modules/.bin/vitest run src/features/compliance/api/complianceApi.test.ts src/features/compliance/components/ChainVerificationPanel.test.tsx src/features/compliance/lib/chainDiagnostics.test.ts`:
```
 ✓ src/features/compliance/lib/chainDiagnostics.test.ts (7 tests) 2ms
 ✓ src/features/compliance/api/complianceApi.test.ts (5 tests) 4ms
 ✓ src/features/compliance/components/ChainVerificationPanel.test.tsx (8 tests) 342ms
 Test Files  3 passed (3)
      Tests  20 passed (20)
   Duration  2.41s (transform 483ms, setup 1.65s, collect 698ms, tests 348ms, environment 1.41s, prepare 224ms)
```

Remaining compliance files:
```
 ✓ src/features/compliance/pages/FraudSettingsPage.test.tsx (2 tests) 445ms
 ✓ src/features/compliance/__tests__/tenantScope.test.tsx (13 tests) 545ms
 ✓ src/features/compliance/components/CashDrawerControlsSection.test.tsx (8 tests) 507ms
 ✓ src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx (4 tests) 745ms
 Test Files  4 passed (4)
      Tests  27 passed (27)
```
(47 compliance tests total, up from 33 in r1.)

Shared-file regression check (`src/lib/i18n.ts` was modified):
```
 ✓ src/lib/__tests__/i18nPosZReportsShadowing.test.ts (4 tests) 4ms
 ✓ src/lib/i18nRawKeyCoverage.test.tsx (5 tests) 55ms
 Test Files  2 passed (2)
      Tests  9 passed (9)
```

`./node_modules/.bin/eslint <7 touched files incl. src/lib/i18n.ts>`:
```
/…/complianceApi.test.ts
  12:30  warning  A method that is not declared with `this: void` … @typescript-eslint/unbound-method
/…/complianceApi.ts
   69:10  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion
   92:20  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion
  115:10  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion

✖ 4 problems (0 errors, 4 warnings)
```
**0 errors**, and warnings are **down 6 → 4**: the two `prefer-optional-chain`
warnings on `ChainVerificationPanel.tsx` are gone with the rewrite. The new
`chainDiagnostics.ts`, `chainDiagnostics.test.ts`, `ChainVerificationPanel.tsx`,
`ChainVerificationPanel.test.tsx` and `i18n.ts` are all clean.

`./node_modules/.bin/tsc --noEmit`:
```
TSC EXIT: 0
```
(no output — the new `BadgeTone` records, the `caution` token family
(`designTokens.ts:285-304`, real amber classes, no interpolated variant/opacity) and
the deep-merged JSON types all compile)

`bash ../../scripts/i18n-baseline-authority.sh`:
```
I18N EXIT: 0
i18n completeness OK — 55 namespaces, authored keys: en=9513, fr=9530, ar=5158 authored (1921 behind aliases); 2816 known gap(s) held at the baseline.
  English-aliased namespaces — ar: 21 ns / 1921 keys served in English
```

Audits:
```
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```
Baseline honesty: `git diff dev HEAD --stat -- apps/web/tools/ scripts/` is **empty**.
Mechanism audit: the i18n numbers improved by authoring 12×3 real strings, not by
baseline absorption; the design-system count is unchanged despite a substantially
rewritten component, and the new tones come from the existing
`semanticColorTokens.intent.caution` family rather than literal classes. No alias
table, no suppression comment, no renamed-equivalent literal.

Scope: FE-only, 10 files, all inside `features/compliance/` + `src/lib/i18n.ts` +
three locale files. The `i18n.ts` edit is the minimum needed to keep the new Arabic
keys from blanking the English siblings — justified, not creep.

## Could not verify
- **The DEV-QA registry is still not in this repo** (`grep -rl 'DEV-QA-035'` → nothing).
- **No live call to `POST /compliance/nf525/verify-chains`.** Finding 1 rests on
  reading `Nf525DataProvider.php:396-418` and `ReceiptHashService.php:267-340`, not on
  observing a running tenant. A browser pass on a POS tenant with sealed receipts
  would show the amber panel directly and is the fastest way to settle it.
- **Arabic copy quality not assessed.** The 12 `ar` strings are machine-authored by
  the fix round and self-declared as needing native review; I cannot judge them. The
  three Arabic strings carrying the finding-1 claim
  (`legacyArmOnlyNote`, `allValidWithLegacyGap`, and the `notCovered` badge) will need
  re-authoring anyway once the wording is corrected.
- Still **not manually recette'd in-browser**; the white-screen fix and the new render
  are proven by unit test only.
- No PHP executed (backend untouched); no POS/Compliance PHPUnit run.

## Merge to local dev: **NO** — blocked on r2 finding 1 (copy + badge tone). Findings 2-3 are cheap to fold into the same round.

---
---

# Gate r3 — PR #209 fix round 2

| Field | Value |
|---|---|
| Re-gated sha | `9d22ce9bbc8d468bdd60d9d3d25135baee9282a0` (branch `gate/pr-209`) |
| Fix commit | `1a9706aaa` `fix(compliance): scope the legacy-row caveat to the COUNT, not the verification (PR #209 gate r2)` |
| Handback | `docs/superpowers/reviews/2026-09-05-dhouha-pr-209-fix-round-1-handback.md` → "Fix round 2" (in-worktree) |
| Delta vs r2 sha `7d0beb283` | 5 code/test/locale files + 1 doc; `ChainVerificationPanel.tsx` +23/-32, `.test.tsx` +40/-16, en/fr/ar `compliance.json` +1/-3 each |
| Reviewer | Fable adversarial merge gate, 2026-09-05 |

## Verdict: **MERGE**

The r2 MAJOR is closed at the root, not papered over: the alarm branch is *deleted*
rather than re-tuned, and the replacement caveat is a statement about the **figure**
that I could verify true against the backend. Every claim in the handback was checked
against source and holds. Remaining items are the same three MINOR follow-ups carried
from r2 plus two unreachable edge cases recorded below — none blocking.

---

## Claim-by-claim verification

### 1. `BadgeTone` back to `valid | broken`; `notCovered` tone deleted — **VERIFIED**
`ChainVerificationPanel.tsx:10` `type BadgeTone = 'valid' | 'broken'`; `TONE_CLASSES`
(`:12-15`) and `TONE_LABEL_KEYS` (`:17-20`) each carry exactly two entries. `grep -n
'caution' ChainVerificationPanel.tsx` → **zero hits**: the panel no longer references
`semanticColorTokens.intent.caution` at all.

### 2. No code path can turn a backend pass into an alarm — **VERIFIED by exhaustion**
I enumerated every tone/banner decision point in the file
(`grep -n 'tone=\|allValid\|hasBroken\|total_receipts'`). There are exactly four:
- `ChainVerificationPanel.tsx:86` — `tone="broken"`, reachable only inside `if (!isValid)` (`:84`)
- `:99` — `tone="valid"`, the unconditional else
- `:219` — `tone={result.z_report_chain.is_valid ? 'valid' : 'broken'}`
- `:164` / `:171` — `allValid && (green)` / `!allValid && (danger)`, where `allValid = report?.all_chains_valid === true` (`:126`)

So the only alarm inputs are `receipt_chain.is_valid === false`,
`z_report_chain.is_valid === false`, and `all_chains_valid !== true` — all three
backend-authored. `hasUncoveredReceiptArm` is gone (`grep` → no hits), and
`ReceiptChainStatus` no longer receives `totalReceipts` (`:207-210` passes only
`isValid`, `failedAtSequence`, `error`). `total_receipts` now appears in exactly one
place outside comments — the count cell at `:214-216` — where it selects between
`noLegacyRows` and `verified / total` and has **no tone effect**.

### 3. The caveat wording is TRUE against `Nf525DataProvider.php:396-418` — **VERIFIED**
`en/compliance.json:33` `legacyRowsCountNote`: *"This column counts legacy-arm receipts
only. Event-chained receipts are verified by this check but are not counted in the figure."*
- **Clause 1 is true**: `Nf525DataProvider.php:390-394` scopes the counted set to
  `Receipt::where('terminal_id',…)->whereNull('fiscal_event_id')->where('is_training', false)`,
  and `totalRows` is that collection's `count()` (`:401`, `:420`, `:432`, `:473`, `:483`, `:489`).
- **Clause 2 is true**: `:396-407` invokes
  `receiptHashService->verifyTerminalChainFiscalArm($terminal)` **before** the legacy
  early-return at `:409-418`, and that method is real work —
  `ReceiptHashService.php:267-270` → `inspectFiscalEventsArm` (`:272-340`) joins
  `fiscal_events` to `pos_receipts`, partitions into one stream per
  `(company_id, chain_context)` (`:316-321`) and walks/rehashes each stream (`:322-333`).
  Event-chained receipts are verified; they are simply absent from `totalRows`.

Crucially the sentence describes the **scope of the check**, not the contents of any
one terminal — which is exactly what the payload can support, since it carries no
event-arm count. This is the honest ceiling of what the FE can say.

### 4. The vacuous `(true, 0)` case is not misrepresented — **VERIFIED**
`ReceiptHashService.php:292-294`: a terminal with **no `fiscal_events` rows at all**
returns `ReceiptChainArmVerificationResult(true, 0)` vacuously, and with an empty legacy
set the endpoint reports `is_valid: true, total_receipts: 0` — identical to a busy
Phase-1 terminal. The panel now renders both as: green `Valid` badge + `None` in the
legacy-count column + the scope caveat under the table.
- For the **busy Phase-1** terminal that is straightforwardly correct.
- For the **genuinely empty** terminal it is also not a false statement: an empty chain
  is trivially valid, the count column says `None` rather than implying volume, and the
  caveat asserts nothing about how many event-chained receipts this terminal has.
There is no sentence anywhere on the panel that would be false in either case. The two
remain *indistinguishable* — see carried finding C.

### 5. Removed keys leave no dangling `t()` — **VERIFIED**
`grep -rn 'notCovered\|legacyArmOnlyNote\|allValidWithLegacyGap' src/` → **zero hits**,
in code *and* in `src/locales/` (all three files cleaned, not just `en`).
`legacyRowsCountNote` exists in `en:33`, `fr:33`, `ar:7` and is referenced once in
production (`ChainVerificationPanel.tsx:245`) and once in test (`:199`).
`src/lib/i18n.ts` was **not** touched this round; its deep-merge is key-generic
(`chainVerification` + nested `errors`), so removing keys needed no merge change — and
`tsc` exit 0 confirms no JSON-typed reference dangles.

### 6. New tests + falsifiability both directions — **VERIFIED by reasoning**
- `ChainVerificationPanel.test.tsx:178-203` "renders a zero-legacy-row terminal as
  VERIFIED, not as an anomaly": asserts the success badge inside the receipt verdict
  cell, `container.querySelector('[class*="amber"]')` is `null`, the count cell equals
  `noLegacyRows`, the caveat is present, and the fleet banner is the plain `allValid`.
  The amber guard is meaningful because `semanticColorTokens.intent.caution.*` really is
  the `amber-*` family (`designTokens.ts:285-304`) — it is a structural check, not a
  string match on copy.
- `:206-219` "never claims the event-chain arm went unverified": whole-panel
  `container.textContent` guard, `not.toMatch(/not covered/i)` and
  `not.toMatch(/covers the legacy arm only/i)`. This is the right shape of regression
  guard for this class of mistake — node-independent, and it would catch the copy
  drifting back even in a different component.
- **Direction A** (r1 test file vs r2/r3 code): the deleted assertion
  `queryByText(valid)).toBeNull()` now finds the badge → 1 red. Matches the handback's
  pasted `expected <span …(1)></span> to be null`.
- **Direction B** (r1-style amber branch re-introduced under the current tests):
  `getByText(valid)` fails in the verdict cell **and** the text guard matches
  `/not covered/i` → 2 red. Matches the handback's pasted output.
The claim is bidirectional and internally consistent; I accept it.

### 7. i18n audit clean, baseline untouched — **VERIFIED**
`bash scripts/i18n-baseline-authority.sh` exit 0, `en 9513 → 9511`, `fr 9530 → 9528`,
`ar 5158 → 5156` — exactly −3 +1 per locale as claimed (net **+10 vs `dev`**).
Known-gap count **unchanged at 2816**; `git diff dev HEAD --stat -- apps/web/tools/ scripts/`
is **empty**. The `fr` file kept its `\uXXXX` escaping (the diff is a surgical 4-line
edit, not a re-dump), so no encoding churn was smuggled in.

---

## Carried / new MINORs (none blocking)

### A. MINOR (new, unreachable in practice) — a malformed envelope shows the danger banner over green rows
`complianceApi.ts:99` coerces `all_chains_valid: payload?.all_chains_valid === true`, so
an absent field yields `false` → `ChainVerificationPanel.tsx:171` renders the danger
`hasBroken` banner while every row badge is green. This cannot convert a backend **pass**
into an alarm — the controller always emits the field (`Nf525ExportController.php:119-127`) —
it can only fire on a malformed body, where erring toward "do not claim a pass" is the
right direction for an integrity panel. Recorded, not actioned.

### B. MINOR (new, unreachable in practice) — the caveat assumes the terminal row exists
`Nf525DataProvider.php:396` guards the event arm with `if ($terminal !== null)`. If
`Terminal::find` missed, the event arm would be skipped and clause 2 of the caveat would
not hold for that row. Unreachable: the terminal list comes from
`listTerminalsForCompany($companyId)` and the lookup uses the same id.

### C. MINOR (carried from r2, unchanged) — busy Phase-1 vs genuinely-empty terminal remain indistinguishable
Neither is misrepresented (see §4), but the panel still cannot say *how much* was
verified on the dominant modern shape. The data exists and is discarded:
`ReceiptChainArmVerificationResult` carries `count` / `inspectedCount`
(`ReceiptHashService.php:296-300, 335-339`) and `verifyTerminalChainFiscalArm` throws
them away with `->isValid` (`:269`). **Backend follow-up lane:** plumb both into
`Nf525ChainVerificationResult` + the controller payload, then the caveat can be replaced
by a real number.

### D. MINOR (carried from r2) — row-level `is_valid` still unused
`complianceApi.ts:29`. The panel reads `receipt_chain.is_valid` (`:208`) and
`z_report_chain.is_valid` (`:219-220`) but never the composed row flag. Declared-but-unread
fields are how the original `chain_length` drift began.

### E. MINOR (carried from r2) — no generated DTO; `exportJetXml` still posts a dead `company_id`
`grep -c 'ChainVerification' packages/shared/types/generated.d.ts` → **0**; the FE type
and the `chainDiagnostics` prefix table remain two untyped couplings to one backend, both
pinned only by tests. `complianceApi.ts:63-70` still sends `company_id` on the JET export.
Both correctly declared out of scope in the handback.

---

## Guardrail evidence (verbatim, re-run by the reviewer at `9d22ce9bb`)

Full compliance suite, by file:
```
 ✓ src/features/compliance/lib/chainDiagnostics.test.ts (7 tests) 4ms
 ✓ src/features/compliance/api/complianceApi.test.ts (5 tests) 9ms
 ✓ src/features/compliance/pages/FraudSettingsPage.test.tsx (2 tests) 328ms
 ✓ src/features/compliance/__tests__/tenantScope.test.tsx (13 tests) 432ms
 ✓ src/features/compliance/components/CashDrawerControlsSection.test.tsx (8 tests) 489ms
 ✓ src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx (4 tests) 804ms
 ✓ src/features/compliance/components/ChainVerificationPanel.test.tsx (9 tests) 651ms
 Test Files  7 passed (7)
      Tests  48 passed (48)
   Duration  4.21s (transform 1.81s, setup 5.60s, collect 5.48s, tests 2.72s, environment 7.31s, prepare 1.06s)
```
**48/48** — the claimed count, and the panel file is 8 → 9 tests.

Shared-file regression check (`src/lib/i18n.ts` unchanged this round, re-run anyway):
```
 ✓ src/lib/__tests__/i18nPosZReportsShadowing.test.ts (4 tests) 12ms
 ✓ src/lib/i18nRawKeyCoverage.test.tsx (5 tests) 135ms
 Test Files  2 passed (2)
      Tests  9 passed (9)
```

`./node_modules/.bin/eslint <7 touched files incl. src/lib/i18n.ts>`:
```
/…/complianceApi.test.ts
  12:30  warning  A method that is not declared with `this: void` … @typescript-eslint/unbound-method
/…/complianceApi.ts
   69:10  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion
   92:20  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion
  115:10  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion

✖ 4 problems (0 errors, 4 warnings)
```
**0 errors**; unchanged from r2 (all four are on `complianceApi.*`, none on the rewritten panel).

`./node_modules/.bin/tsc --noEmit`:
```
TSC EXIT: 0
```
(no output)

`bash ../../scripts/i18n-baseline-authority.sh`:
```
I18N EXIT: 0
i18n completeness OK — 55 namespaces, authored keys: en=9511, fr=9528, ar=5156 authored (1921 behind aliases); 2816 known gap(s) held at the baseline.
  English-aliased namespaces — ar: 21 ns / 1921 keys served in English
```

Audits:
```
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```
Baseline honesty: `git diff dev HEAD --stat -- apps/web/tools/ scripts/` **empty** across
the whole branch. Mechanism audit: the design-system count is unchanged even though the
round *deleted* a token usage (`intent.caution`) — no substitution of a neighbouring
shade, no literal class introduced, no suppression comment. The i18n numbers moved by
removing three false keys and adding one true one, with the pinned baseline untouched.

Scope: FE-only, 5 files. No backend change, no new dependency, no unrelated file.
Commit subject accurately describes the change ("scope the legacy-row caveat to the
COUNT, not the verification").

## Could not verify
- **The DEV-QA registry is still not in this repo** (`grep -rl 'DEV-QA-035'` → nothing).
- **Still no live call to `POST /compliance/nf525/verify-chains`** and no in-browser
  pass. The r3 conclusions rest on reading `Nf525DataProvider.php:390-418` and
  `ReceiptHashService.php:267-340`. A browser check on a POS tenant with sealed receipts
  remains the cheapest confirmation that the panel now reads green on a healthy fleet.
- **Arabic copy quality still unassessed.** `ar/compliance.json:7` `legacyRowsCountNote`
  is machine-authored and states a compliance fact to an auditor; the handback flags it
  and I concur — it needs a native reader before it ships to an Arabic tenant. Same for
  the five `errors.*` strings.
- The bidirectional falsification runs pasted in the handback were **reproduced by
  reasoning**, not by re-running reverted trees (read-only review; no code modified).
- No PHP executed; backend untouched in all three rounds.

## Merge to local dev: **YES**
