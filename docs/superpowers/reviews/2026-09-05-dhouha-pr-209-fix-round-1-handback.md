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

---
---

# Fix round 2 — answering gate r2

| Field | Value |
|---|---|
| Gate section answered | `docs/superpowers/reviews/2026-09-05-dhouha-pr-209-gate-r1.md` → "Gate r2 — PR #209 fix round 1" (verdict **CHANGES**, 1 MAJOR blocking) |
| Re-gated sha | `7d0beb283` |
| Fix-round-2 commit | `1a9706aaa` — `fix(compliance): scope the legacy-row caveat to the COUNT, not the verification (PR #209 gate r2)` |
| Author of fix round | Claude Opus 5, 2026-09-05 |
| Findings addressed | r2 finding 1 (MAJOR, blocking) + r2 finding 2 (MINOR, closed by the same change) |
| Findings deliberately left | r2 findings 3, 4, 5 (below) |
| Backend changed | **none** |
| Merge decision | **not merged** — handed back for re-gate |

## r2 finding 1 (MAJOR) — the r1 copy denied a verification that actually happened

**The gate is right, and I re-derived it from the source rather than accepting it.**

`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php`:
- `:396-407` — the **fiscal-events arm runs first**:
  `$fiscalEventsArmOk = $this->receiptHashService->verifyTerminalChainFiscalArm($terminal);`
  and returns a chain-break result if it fails.
- `:409-418` — **only then** does the legacy early-return fire
  (`if ($legacyReceipts->isEmpty()) return … isValid: true, totalRows: 0 …`).

`apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php`:
- `:267-270` — `verifyTerminalChainFiscalArm` is `inspectFiscalEventsArm($terminal)->isValid`.
- `:272-340` — `inspectFiscalEventsArm` joins `fiscal_events` to `pos_receipts`, groups
  rows into one stream per `(company_id, chain_context)` (`:316-321`) and walks/rehashes
  each stream via `inspectFiscalEventStream` (`:322-333`). It is not a stub.

So `is_valid: true, total_receipts: 0` means **"the event chain was verified and passed,
and there were additionally no legacy rows"**. My r1 copy asserted the opposite
("The event-chain arm is not covered by this check"), and since the legacy arm ages out
post-Phase-1 (`Nf525DataProvider.php:359-363`), that amber badge plus the
`allValidWithLegacyGap` banner would have fired on **every terminal of a normal modern
fleet** — a standing false alarm on a compliance surface, which is the same
signal-overload failure the original r1 finding was raised to prevent. My r1 handback
even recorded the correct fact ("the fiscal-events arm returns only a boolean") and then
drew the opposite conclusion in the copy. That is on me.

**Changes** (`apps/web/src/features/compliance/components/ChainVerificationPanel.tsx`):

1. `BadgeTone` is back to `'valid' | 'broken'`; the `notCovered` amber tone and its
   label key are gone (`:10-21`). The panel no longer references
   `semanticColorTokens.intent.caution` at all.
2. `ReceiptChainStatus` (`:60-99`) renders the **backend's verdict and nothing else** —
   broken → danger badge + `brokenAt` + diagnostics (unchanged); otherwise → the plain
   `valid` badge. It no longer takes `totalReceipts`, so there is no code path left that
   can turn a backend pass into an alarm. A docblock records the call-order proof above
   so the next reader cannot re-derive the r1 mistake.
3. `hasUncoveredReceiptArm` is deleted and the caution banner branch with it; a passing
   fleet gets the plain green `allValid` banner again (`:167-173`).
4. The caveat now describes the **figure**, not the verification, and sits **once** under
   the table rather than on every row (`:243-245`):
   `chainVerification.legacyRowsCountNote` — *"This column counts legacy-arm receipts
   only. Event-chained receipts are verified by this check but are not counted in the
   figure."* The column header stays `legacyRowsVerified`, the cell stays
   `verified / total` (or `noLegacyRows` = "None" at zero).

**r2 finding 2 (MINOR) is closed by the same change** — a brand-new terminal that has
simply never sold anything now renders as a plain pass with "None" in the count column,
not as an anomaly. There is no per-row caution state left to trigger.

## i18n re-authoring

Removed from `en`, `fr` **and** `ar` (all three carried the false claim):
`chainVerification.notCovered`, `chainVerification.legacyArmOnlyNote`,
`chainVerification.allValidWithLegacyGap`.

Added to all three: `chainVerification.legacyRowsCountNote`.

Net vs `dev`: **+10 keys per locale** (r1's +12, minus 3, plus 1). Locale diffs are
minimal (`4 +---` each) — the `fr` file's `\uXXXX` escaping style was preserved with a
surgical text edit rather than a full re-dump.

**The `ar` strings remain machine-authored by this fix round and have NOT been reviewed
by a native speaker.** Flagged again for the Arabic backfill lane; the re-authored
`legacyRowsCountNote` in particular states a compliance fact and should be read by a
native speaker before it reaches an auditor.

## Tests

`ChainVerificationPanel.test.tsx` — the r1 test *"does NOT show a green Valid verdict for
a terminal whose legacy arm is empty"* encoded the false claim and is **replaced**:

- **`renders a zero-legacy-row terminal as VERIFIED, not as an anomaly`** — asserts the
  success badge is present in the receipt verdict cell, `container.querySelector('[class*="amber"]')`
  is `null` (no caution anywhere on the panel), the count cell reads `noLegacyRows`, the
  `legacyRowsCountNote` caveat is under the table, and the fleet banner is the plain
  `allValid`.
- **`never claims the event-chain arm went unverified`** — a whole-panel text guard:
  `not.toMatch(/not covered/i)` and `not.toMatch(/covers the legacy arm only/i)`. This is
  the regression guard for this specific class of mistake, independent of any one node.
- The `phase1Terminal` fixture docblock now records that this shape is the **normal**
  post-Phase-1 case, with the `:396-407` before `:409-418` ordering cited.

**Falsifiability, both directions (verbatim):**

r1's test file (`git show afc97db0a:…ChainVerificationPanel.test.tsx`) run against the r2 code:
```
   × ChainVerificationPanel > does NOT show a green "Valid" verdict for a terminal whose legacy arm is empty 36ms
     → expected <span …(1)></span> to be null
      Tests  1 failed | 7 passed (8)
```
Re-introducing an r1-style amber branch under the r2 test file:
```
   × ChainVerificationPanel > renders a zero-legacy-row terminal as VERIFIED, not as an anomaly 38ms
     → Unable to find an element with the text: Valid. …
   × ChainVerificationPanel > never claims the event-chain arm went unverified 18ms
     → expected 'Hash Chain VerificationVerify the int…' not to match /not covered/i
      Tests  2 failed | 7 passed (9)
```

## Verbatim guardrail evidence (at `1a9706aaa`)

Compliance suite, by file:
```
 ✓ src/features/compliance/lib/chainDiagnostics.test.ts (7 tests) 13ms
 ✓ src/features/compliance/api/complianceApi.test.ts (5 tests) 11ms
 ✓ src/features/compliance/pages/FraudSettingsPage.test.tsx (2 tests) 1946ms
 ✓ src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx (4 tests) 3011ms
 ✓ src/features/compliance/__tests__/tenantScope.test.tsx (13 tests) 2236ms
 ✓ src/features/compliance/components/CashDrawerControlsSection.test.tsx (8 tests) 2225ms
 ✓ src/features/compliance/components/ChainVerificationPanel.test.tsx (9 tests) 2534ms
 Test Files  7 passed (7)
      Tests  48 passed (48)
```
(47 → 48: the r1 zero-legacy test is replaced and a second guard added.)

Shared-file regression check (`src/lib/i18n.ts` carried over from r1):
```
 ✓ src/lib/__tests__/i18nPosZReportsShadowing.test.ts (4 tests) 4ms
 ✓ src/lib/i18nRawKeyCoverage.test.tsx (5 tests) 48ms
 Test Files  2 passed (2)
      Tests  9 passed (9)
```

`./node_modules/.bin/eslint <6 compliance files> src/lib/i18n.ts` — **0 errors**, 4
warnings, all pre-existing and unchanged from the r2 gate's own measurement
(`complianceApi.test.ts:12:30` unbound-method; `complianceApi.ts:69/92/115`
no-unsafe-type-assertion). Re-linting just the two files this round touched:
```
ESLINT-SCOPED EXIT: 0
```
(no output — `ChainVerificationPanel.tsx` and `ChainVerificationPanel.test.tsx` are clean.)

`./node_modules/.bin/tsc --noEmit`
```
TSC EXIT: 0
```
(swap free at the time: 1087 MB, above the 300 MB floor.)

`pnpm -s audit:i18n:local`
```
i18n completeness OK — 55 namespaces, authored keys: en=9511, fr=9528, ar=5156 authored (1921 behind aliases); 2816 known gap(s) held at the baseline.
  English-aliased namespaces — ar: 21 ns / 1921 keys served in English
```
(en 9513 → 9511, fr 9530 → 9528, ar 5158 → 5156: −3 +1 per locale. The 2816 baselined
gaps are unchanged.)

Audits:
```
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```

**Baseline honesty.** `git status --porcelain -- apps/web/tools scripts` is empty — no
baseline or audit tool was touched in either round. The i18n counts moved by
authoring/removing real strings, not by absorption. The design-system count is still 810
with 0 new even though the component lost its amber branch.

Scope of `1a9706aaa`: 5 files — the panel, its test, and the three locale files.

## Durable follow-up (backend lane — explicitly NOT this PR)

The event-arm count the panel would need in order to state the truth **with a number**
already exists and is thrown away:

- `ReceiptHashService.php:296-300` — `inspectFiscalEventsArm` computes
  `$receiptCoverageCount` (fiscal_events rows projected onto a receipt of this terminal)
  and `$inspectedCount` (all fiscal_events rows walked).
- `ReceiptHashService.php:335-339` — the clean path returns
  `new ReceiptChainArmVerificationResult(isValid: true, count: $receiptCoverageCount, inspectedCount: $inspectedCount)`.
- `ReceiptHashService.php:269` — `verifyTerminalChainFiscalArm` reduces all of that to
  `->isValid`, so `Nf525DataProvider` never sees the numbers and the controller payload
  cannot carry them.

Plumbing `count` / `inspectedCount` through `Nf525ChainVerificationResult` and
`Nf525ExportController::verifyChains` would let the panel show an event-arm figure beside
the legacy one and retire `legacyRowsCountNote` entirely. It would also disambiguate the
one case the FE genuinely cannot distinguish today: a terminal with **no fiscal_events
rows at all**, where `inspectFiscalEventsArm` returns `(true, 0)` vacuously
(`ReceiptHashService.php:292-294`), is indistinguishable in this payload from a busy
Phase-1 terminal.

## Deliberately NOT changed in round 2

- **r2 finding 3 (MINOR) — row-level `is_valid` is the only unused field**
  (`complianceApi.ts:29`). Left as-is: the fix-round-2 brief scoped this round to the
  MAJOR (plus finding 2, which the same change closes). It is a one-line decision
  (render it or drop it) and should be taken in the same round as the backend DTO work
  below rather than churned twice.
- **r2 finding 4 (MINOR, carried) — no generated DTO for this endpoint.** The controller
  still builds a raw array literal (`Nf525ExportController.php:91-110`), so both the
  `ChainVerificationResult` interface and the `chainDiagnostics.ts` prefix table are
  untyped couplings to the backend. Both are pinned by tests; the `#[TypeScript]` DTO +
  stable `error_code` remains a backend lane.
- **r2 finding 5 (MINOR, carried) — `exportJetXml` still posts a dead `company_id`**
  (`complianceApi.ts:66`), on the sibling endpoint, outside this PR's diff.
- **r1 findings 5, 6, 7** (name collision, Arabic backfill, generated DTO) as recorded in
  the round-1 section above.

## Not verified in round 2

- **Still no live call to `POST /compliance/nf525/verify-chains`.** The correction rests
  on reading `Nf525DataProvider.php:396-418` and `ReceiptHashService.php:267-340`, not on
  observing a running tenant. A browser pass on a POS tenant with sealed receipts remains
  the fastest way to settle both this and the r1 shape claim.
- **No browser recette**; the new render is proven by unit test only.
- **No PHP executed and no PHP file changed.**
- **RTL/Arabic not rendered in a browser.** The new caption uses the same subtle text
  token and inherits the panel's logical properties.
- **Arabic copy quality still unassessed** (machine-authored).
