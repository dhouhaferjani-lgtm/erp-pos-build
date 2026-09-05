# Fix round 1 handback — PR #209 "fix(compliance): correct verify-chains response shape + panel field mapping (DEV-QA-035)"

| Field | Value |
|---|---|
| Gate report answered | `docs/superpowers/reviews/2026-09-05-dhouha-pr-209-gate-r1.md` (verdict **CHANGES**) |
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-209` |
| Branch | `gate/pr-209` (merge base `d56d62535` + PR merge `4e9e3d5bc`) |
| Fix commit | `afc97db0a` — `fix(compliance): honest chain verdict + translated diagnostics (PR #209 gate r1)` |
| Author of fix round | Claude Opus 5, 2026-09-05 |
| Findings addressed | 1 (MAJOR), 2 (MAJOR), 3 (MINOR), 4 (MINOR) |
| Findings deliberately left | 5, 6, 7 (documented follow-ups below) |
| Backend changed | **none** — FE only, as scoped |
| Merge decision | **not merged** — handed back for re-gate |

---

## Finding 1 (MAJOR) — "Chain Length" presented a legacy-arm-only count as a verified chain

**Premise re-verified against the backend before acting.**
- `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:382-395` —
  the docblock states *"Total rows + verified rows are scoped to the legacy arm for
  backward compatibility with the DTO + UI surface"*, and the query is
  `Receipt::where('terminal_id', …)->whereNull('fiscal_event_id')->where('is_training', false)`.
- same file `:410-418` — when that set is empty the method early-returns
  `isValid: true, totalRows: 0, verifiedRows: 0, error: null`. On a Phase-1 terminal
  (every receipt carries `fiscal_event_id`) that is a green verdict over zero inspected rows.
- The fiscal-events arm returns only a boolean (`verifyTerminalChainFiscalArm`, `:396-407`).
- **Not true of the Z column:** `verifyZReportChain` (`:494-500`) counts *every*
  `ZReport` for the terminal, so `total_reports` really is that chain's length. The Z
  column therefore **keeps** the `chainVerification.chainLength` label; only the receipt
  column was mislabelled.

**Changes** (`apps/web/src/features/compliance/components/ChainVerificationPanel.tsx`):

1. Receipt count column header `:200-203` — `chainVerification.chainLength` →
   `chainVerification.legacyRowsVerified` ("Legacy Rows Verified" / "Lignes Héritées
   Vérifiées"). The two columns no longer share one ambiguous key.
2. Receipt count cell `:222-226` — renders `verified / total_receipts`
   (e.g. `42 / 42`, `6 / 10`) instead of a bare total, or the translated
   `chainVerification.noLegacyRows` ("None" / "Aucune") when the total is 0.
3. New `ReceiptChainStatus` component `:60-107` — when `is_valid && total_receipts === 0`
   the cell renders a **caution-toned `Not covered` badge** plus the translated
   `chainVerification.legacyArmOnlyNote` ("No legacy-arm receipts to verify. The
   event-chain arm is not covered by this check.") instead of the green `Valid` badge.
4. Fleet banner `:176-196` — when the verdict is valid but at least one terminal's
   receipt arm was empty, the green `allValid` banner is replaced by a caution
   `allValidWithLegacyGap` banner carrying the same caveat. Terminals that really had
   legacy rows still get the plain green banner (asserted by its own test).

The token used for the new tone is `semanticColorTokens.intent.caution.*` (amber) —
an existing token family, no literal colour class (rule 18).

---

## Finding 2 (MAJOR) — raw English backend diagnostics rendered as operator copy

**Change.** New module `apps/web/src/features/compliance/lib/chainDiagnostics.ts`
maps the stable literal prefix of each backend diagnostic to a translation key:

| Backend literal (verbatim source) | Key |
|---|---|
| `Fiscal-events chain break:` — `Nf525DataProvider.php:404` | `chainVerification.errors.fiscalEventsChainBreak` |
| `Chain linkage broken:` — `:430` | `chainVerification.errors.legacyLinkageBroken` |
| `sealed_hash_algorithm anomaly:` — `:471` | `chainVerification.errors.sealedHashAlgorithmAnomaly` |
| `Fiscal hash mismatch:` — `:481` | `chainVerification.errors.fiscalHashMismatch` |
| `Z-report chain break:` — `:522` | `chainVerification.errors.zReportChainBreak` |

`ChainDiagnostic` (`ChainVerificationPanel.tsx:44-58`) renders `t(key)` for a known
diagnostic. An **unknown** string is never passed off as operator copy: it renders as
`<code class="font-mono break-all">` behind a translated
`t('chainVerification.technicalDetail')` label. Both the receipt and the Z-report cell
route through this component (`:85`, `:238-240`).

Backend untouched — no `error_code` was added. The gate's suggested backend follow-up
(stable machine code from `Nf525DataProvider`) is still owed; the prefix table is the
interim contract and `chainDiagnostics.test.ts` pins the five literals verbatim so a
backend reword goes red here rather than silently degrading to "technical detail".

---

## Finding 3 (MINOR) — `verified_at` dropped, verdict recomputed client-side

**Change.** `apps/web/src/features/compliance/api/complianceApi.ts:80-102` —
`verifyChains` now returns the whole normalised `ChainVerificationResponse` instead of
just the terminals array, defensively coercing every field (`company_id`, `terminals`,
`all_chains_valid`, `verified_at`) so a malformed body still degrades gracefully.

The panel (`ChainVerificationPanel.tsx:130-138`) now:
- renders `verified_at` as an "as of" stamp through `formatDateTime`
  (`t('chainVerification.verifiedAt', { timestamp })`, `:169-175`);
- uses the backend's own `all_chains_valid` for the fleet verdict instead of the
  client-side `results.every(...)` / `results.some(...)` pair — one verdict, one source.

---

## Finding 4 (MINOR) — dead `company_id` request body

**Change.** `complianceApi.ts:96` — `api.post('/compliance/nf525/verify-chains', {})`;
the `companyId` parameter is gone. Verified safe before removing:
`Nf525ExportController::verifyChains` resolves the company **only** via
`$this->companyContext->requireCompanyId()` and never reads `$request->input('company_id')`;
the class docblock (`Nf525ExportController.php:28-36`) states body `company_id` "is no
longer accepted" because it was a cross-tenant exfiltration vector. The panel keeps its
`if (!currentCompanyId) return` guard, so behaviour for "no company selected" is unchanged.

`exportJetXml` still posts `company_id` (`complianceApi.ts:66`) — pre-existing, out of
scope for this round, listed as a follow-up.

---

## i18n

Keys added under `compliance.chainVerification`: `legacyRowsVerified`, `noLegacyRows`,
`notCovered`, `legacyArmOnlyNote`, `allValidWithLegacyGap`, `technicalDetail`,
`verifiedAt`, and `errors.{fiscalEventsChainBreak, legacyLinkageBroken,
sealedHashAlgorithmAnomaly, fiscalHashMismatch, zReportChainBreak}` — **12 keys in
`en`, `fr` AND `ar`**.

**`ar` wiring is a SPREAD, not an alias** (`apps/web/src/lib/i18n.ts:432-434`:
`compliance: { ...enCompliance, ...arCompliance, … }`), so `pnpm audit:i18n:local`
holds `ar|compliance` to its own JSON file and reported all 12 new keys as **NEW
findings** (not covered by the pinned baseline, which already holds the 15 pre-existing
`ar|compliance|missing|chainVerification.*` entries). They are fixed by authoring
Arabic, not by touching the baseline.

That exposed a latent hazard: `...arCompliance` is a **shallow** spread, so an `ar` file
carrying a partial `chainVerification` object would have replaced the entire English one
and blanked all 15 existing keys in Arabic. `i18n.ts:432-445` now deep-merges
`chainVerification` (and its nested `errors`) exactly the way it already did for
`fraudSettings`. Baseline file untouched.

The Arabic strings are machine-authored by this fix round and have **not** been reviewed
by a native speaker — flagged for the Arabic backfill lane.

---

## Verbatim guardrail evidence (this worktree, after the fix commit)

`cd .worktrees/pr-209/apps/web && ./node_modules/.bin/vitest run $(find src/features/compliance -name '*.test.ts*' | sort)`
```
 ✓ src/features/compliance/lib/chainDiagnostics.test.ts (7 tests) 4ms
 ✓ src/features/compliance/api/complianceApi.test.ts (5 tests) 7ms
 ✓ src/features/compliance/pages/FraudSettingsPage.test.tsx (2 tests) 235ms
 ✓ src/features/compliance/__tests__/tenantScope.test.tsx (13 tests) 329ms
 ✓ src/features/compliance/components/CashDrawerControlsSection.test.tsx (8 tests) 331ms
 ✓ src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx (4 tests) 567ms
 ✓ src/features/compliance/components/ChainVerificationPanel.test.tsx (8 tests) 419ms
 Test Files  7 passed (7)
      Tests  47 passed (47)
   Start at  15:17:19
   Duration  2.75s (transform 1.08s, setup 4.26s, collect 3.87s, tests 1.89s, environment 3.85s, prepare 583ms)
```
(The gate measured 33 compliance tests across 6 files; this round adds 14 — 5 panel
cases, 2 api cases, 7 diagnostics cases — for 47 across 7 files.)

**Falsifiability — reverting the four render changes** (zero-legacy state, `verified/total`
cell, translated diagnostics, `verified_at` + caveat banner) while keeping the new tests:
```
   × ChainVerificationPanel > renders a row per terminal from the real backend payload without crashing 123ms
     → expected '42' to be '42 / 42' // Object.is equality
   ✓ ChainVerificationPanel > shows the failing terminal with its failed sequence 42ms
   × ChainVerificationPanel > translates a known backend diagnostic instead of printing its English text 20ms
     → expected <span …(1)></span> to be null
   × ChainVerificationPanel > marks an UNKNOWN diagnostic as a labelled technical detail, not operator copy 18ms
     → expected 'SPAN' to be 'CODE' // Object.is equality
   × ChainVerificationPanel > does NOT show a green "Valid" verdict for a terminal whose legacy arm is empty 37ms
     → expected <span …(1)></span> to be null
   ✓ ChainVerificationPanel > keeps the plain all-valid banner when every receipt arm actually had rows 15ms
   × ChainVerificationPanel > renders the verification timestamp as an "as of" stamp 18ms
     → Unable to find an element with the text: /As of .*/…
   ✓ ChainVerificationPanel > degrades gracefully on an empty payload (no TypeError, shows no-terminals) 15ms
      Tests  5 failed | 3 passed (8)
```
Additionally `complianceApi.test.ts` case *"sends NO company_id in the body"* is red
against the old body (`expect(mockedPost).toHaveBeenCalledWith(url, {})`), and the
`chainDiagnostics.test.ts` cases pin the five backend literals verbatim.

`./node_modules/.bin/eslint <6 touched compliance files> src/lib/i18n.ts`
```
/…/complianceApi.test.ts
  12:30  warning  A method that is not declared with `this: void` … @typescript-eslint/unbound-method
/…/complianceApi.ts
   69:10  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion
   92:20  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion
  115:10  warning  Unsafe assertion from `any` detected … @typescript-eslint/no-unsafe-type-assertion

✖ 4 problems (0 errors, 4 warnings)
```
**0 errors.** All 4 warnings are the pre-existing ones the gate already recorded
(line numbers shifted by the docblock). The gate's two
`prefer-optional-chain` warnings on `ChainVerificationPanel.tsx:44,47` are **gone** —
that code was replaced. `chainDiagnostics.ts`, `chainDiagnostics.test.ts`,
`ChainVerificationPanel.tsx`, `ChainVerificationPanel.test.tsx` and `i18n.ts` are clean.

`./node_modules/.bin/tsc --noEmit`
```
TSC EXIT: 0
```

Audit tools:
```
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```

`pnpm -s audit:i18n:local`
```
i18n completeness OK — 55 namespaces, authored keys: en=9513, fr=9530, ar=5158 authored (1921 behind aliases); 2816 known gap(s) held at the baseline.
  English-aliased namespaces — ar: 21 ns / 1921 keys served in English
```
(en +12, fr +12, ar +12; the 2816 baselined gaps are unchanged.)

React Doctor on the fix commit: **93/100**, 1 warning
(`prefer-module-scope-static-value` on the badge tone maps) — fixed by hoisting
`TONE_CLASSES` / `TONE_LABEL_KEYS` to module scope before the commit was amended.

**Baseline honesty.** No baseline file in the diff. `git show --stat afc97db0a` is
10 files: 6 under `features/compliance/`, `lib/i18n.ts`, and the three
`locales/*/compliance.json`. No `--write-baseline`, no suppression comment, no alias
indirection, no `eslint-disable`.

---

## Deliberately NOT changed (follow-ups for a separate lane)

1. **Gate finding 5 — no generated counterpart for `ChainVerificationResult`.**
   `Nf525ExportController::verifyChains` still builds a raw array literal instead of a
   `#[TypeScript]` DTO, so nothing type-checks FE against BE. **Backend lane** — this
   round is FE-only by instruction.
2. **Gate finding 6 — name collision.** `ChainVerificationResult` is exported from both
   `features/compliance/api/complianceApi.ts` and `features/pos/api/reportApi.ts:100-104`
   with different shapes. Pre-existing; untouched.
3. **Gate finding 7 — Arabic compliance namespace.** Still 15 pre-existing
   `chainVerification.*` keys missing in `ar` (baselined). The 12 keys added by this
   round are present in `ar`, so the panel is now a *mixed* Arabic/English surface until
   the backfill lane lands. The Arabic copy authored here needs native review.
4. **`exportJetXml` still posts `company_id`** (`complianceApi.ts:66`) — the same dead
   body field, on the sibling endpoint. Not touched (out of this PR's diff).
5. **The panel bypasses TanStack Query entirely** (`useState` + manual `await`), so
   convention 05 does not apply and there is no query key to tenant-scope. Pre-existing.

## Not verified in this round
- **No live call to `POST /compliance/nf525/verify-chains`.** The Phase-1 "Valid / 0"
  scenario is still reasoned from `Nf525DataProvider.php:382-395,410-418`, not observed
  on a running tenant. A browser/API pass on a POS tenant with sealed receipts remains owed.
- **No browser recette.** The new caution states are proven by unit test only.
- **No PHP was run and no PHP file was changed** (the POS/Compliance PHPUnit suites were
  not executed — nothing in the diff touches them).
- **RTL/Arabic rendering not checked in a browser.** The new markup uses logical
  properties (`ms-2`, `text-start`) consistent with the existing panel.
- The Arabic strings are machine-authored and unreviewed.
