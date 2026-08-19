Read-only review complete. I read the M2 section of the brief (`docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md:345-382`), the evidence contract row (`:439`), the house rules (`:446-471`), the round-1 register (`docs/handoff/reviews/sv-stage1/M2-round1.md`), and verified every claim against the code at `0f8cd885f`. Fix commits under review: `6dfb7148a`, `0f8cd885f`.

## Round-1 disposition (re-verified, not taken on report)

| R1 | Severity | Status | Evidence I re-derived |
|---|---|---|---|
| 1 — regrouped `expected_cash` | P2 | **CLOSED** | `git diff df85d43f..HEAD -- apps/pos/src/lib/offline/endOfDayPreview.ts` shows the expression at `endOfDayPreview.ts:460-464` is character-identical to base (`bcsub(bcadd(bcsub(bcadd(O,T,s),C,s),D,s),R,s)`). The only additions are the `cashSalesNet` display term (`:453-457`) and two return fields (`:490-491`). Zero rounding boundaries moved. |
| 2 — over/short uncovered | P2 | **CLOSED (rendered), residual below** | `CashReconciliationSection.test.tsx:249-275` adds 4 `it.each` cases pinning `Over/Short/Excédent/Manquant` + `6.00` on the rendered reveal. I dumped both locale files: `summary.over/short` = `Over/Short` · `Excédent/Manquant`. Correct. |
| 3 — `bg-surface-subtle` dead class | P3 | **CLOSED** | `CashDrawerRevealSummary.tsx:33` now `bg-surface-raised`; `apps/pos/src/index.css:274` defines `--color-surface-raised`. |
| 5 — red-run counts | P3 | **CLOSED** | Report `:404-410` rewritten; counts now reconcile — 15 component + 23 preview = 38, matching the fix-round replay at `:441`. |
| 6 — undeclared suites | P3 | **CLOSED** | Report `:411` now declares six files. I ran all six: **6 files / 75 tests passed**, matching the claim. |
| 8 — raw `Counted` string | P3 | **CLOSED** | `CashReconciliationSection.tsx:302` `bcformat(actuals[...] ?? '0', scale)`; guarded by `variances`' `'' `/`'.'` filter (`:136`) so `bcformat` never sees a string big.js rejects (I probed: `''` and `'.'` throw, `'100.'` and `'.5'` parse). |
| 4, 7 — label breadth, muted instruction | P3 | Carried, recorded at report `:440` | Unchanged, correctly deferred. |

## Finding register — M2 (SV-11), round 2

**1. P3 — CONFIRMED (arithmetic) / PLAUSIBLE (reachability): the round-1 fix traded a value drift for a display that does not add up, and the report asserts the opposite.**
`apps/pos/src/lib/offline/endOfDayPreview.ts:453-464`. `cash_sales_net` is now rounded on its own boundary — `((T−C)−R)` at `scale` — while `expected_cash` keeps the legacy `(((O+T)−C)+D)−R` boundaries. Where those disagree, reveal lines 1–3 no longer sum to line 4. The fix's **own new test pins exactly that case**: `endOfDayPreview.test.ts:279-303` asserts `cash_sales_net = '10.00'` and `expected_cash = '110.01'` for `O=100.00, T=10.005, C=0.005, D=R=0`. I reproduced it against big.js from this worktree:

```
opening 100.00  cash_sales_net 10.00  drawer 0.00  expected 110.01
sum of lines 1-3 = 110.00
```

Failure scenario: a cashier on that shift reads `Opening float 100.00 / Cash sales 10.00 / Paid in-out 0.00 / Expected in drawer 110.01` — the decomposition whose entire purpose (dossier §2 SV-11 item 3) is to explain the expected figure visibly contradicts it by one minor unit. Round 1 verified the opposite property held pre-fix ("the three lines still sum exactly to Expected"), so this is a regression introduced by the fix, not a pre-existing trait.

Reachability: same class as round 1's — needs a sub-scale term for a 2-dp currency. I pushed harder than round 1 and found the live writers all format at currency scale before persistence: `paymentStore.ts:1331,1367` (`bcformat(p.amount, decimals)`), `receiptService.ts:559-560` (`tendered_amount`, `change_due` at `decimals`), `buildReceiptData.ts:329`. `cashTenderedSum`/`cashChangeDueSum` still accumulate at the **default scale 3** (`endOfDayPreview.ts:337-345,359`) irrespective of currency, so the hazard is structural, but I could not produce an in-repo writer that reaches it — hence P3, not P2.

Report `docs/sessions/codex-sv-stage1-report.md:440` closes with *"the line values still decompose the authoritative expected figure"*, which the fix's own fixture disproves. `:422` is honest about it ("the intentionally different display-term and authoritative-expected rounding results"); `:440` is not. **Ask:** correct the `:440` sentence and carry the residual to M5 / SV-12 (which owns line 2's structural extension) as a recorded note.

**2. P3 — CONFIRMED: the seven `cash_count.summary.*` locale values are not pinned by any test; the rendered FR assertions read from the test's own mock map.**
`CashReconciliationSection.test.tsx:16-42` mocks `react-i18next` with a hand-written French dictionary, and the EN path resolves to the component's `defaultValue` (`CashDrawerRevealSummary.tsx:26-29,43,48,56,64,71`). The locale-contract test (`:197-212`) pins only four keys — `count_instruction` (en+fr), `expected_includes_float` (en+fr), `no_difference`, `variance`. It does **not** pin `summary.opening_float / cash_sales_net / drawer_movements / expected_in_drawer / counted / over / short` in either file.

What the new tests **do** prove: the component requests the correct key strings (a wrong key would miss the mock's map and fall through to the English `defaultValue`, failing the FR cases). What they do **not** prove: that `fr/pos.json` carries the French value. Failure scenario, i.e. round 1's scenario narrowed but not closed: someone edits `fr/pos.json:927` `summary.over` to `"Over"` — production shows a French cashier "Over" on the one case that triggers a mandatory written reason, and all 75 tests stay green. Values are correct today (I dumped both files), so this is an evidence gap, not a defect. **Ask:** extend the `:197-212` assertions to the seven summary keys — a self-contained addition to a test that already exists.

**3. P3 — carried from round 1 (#7), unchanged and correctly deferred.** `CashReconciliationSection.tsx:255-262` renders the dossier's "single highest-value change" at `text-sm text-ink-muted`, with the dossier's bold on *all* / *tout* / *y compris le fonds de caisse* dropped. Owner presentation call, not a contract breach.

**4. P3 — carried from round 1 (#4), explicitly recorded at report `:440`.** Line 2's label is narrower than its contents (`T−C−cashRefundImpact`), line 3's absorbs cash account-payment movements (`endOfDayPreview.ts:443,96-97`). The labels are exact dossier copy; the deviation is the dossier's. For M5 / owner.

## Bypasses I tried that FAILED (the implementation held)

- **Blind-mode magnitude leak:** `committed = useState(!blindMode)` (`:88`), reveal gated at `:288,:296`, table gated at `CashCountTable.tsx:63-64`. Nothing rendered-then-hidden. FR test asserts the disclosure line **absent** pre-commit (`test:227-229`) and the reveal absent (`:165`). Under blind mode nothing derived from expected or variance reaches the DOM before Commit Counts.
- **SV-12 no-dead-DOM contract:** single cash-sales row at `CashDrawerRevealSummary.tsx:46-53`; no placeholder, reserved slot, empty container or `display:none` sibling. Pinned negatively by `queryByText(/rounding/i)` at `test:166,195`.
- **Render crash on a partial keypad string:** the new `bcformat(countedCash)` is unreachable for `''`/`'.'` (guard at `:136` gates `cashVariance`, which gates the whole summary at `:296`); big.js accepts `'100.'`. No crash path.
- **Fiscal/sealed-byte reach:** the three M2 commits touch only `apps/pos/src/{components,lib,locales}` + `docs/`. No `apps/api`, no `zReportService.ts`, no receipt sealing, no event added/renamed/retired, no `TREASURY_SHIFT_VARIANCE_GL_ENABLED`.
- **Wire-shape change from the two new required fields:** `EndOfDayPreview` is produced only by `buildEndOfDayPreview`; `Header.tsx:403` consumes `preview.expected_cash` alone and never spreads the object into a payload. `npx tsc --noEmit` exits 0.
- **Rule 19:** no `parseFloat`/`Number()` introduced; all new values are decimal strings through `bcadd`/`bcsub`/`bcformat`. `eslint` on the five touched files: 0 errors, 2 warnings, **both pre-existing at base** (`set-state-in-effect` at `:104`, `parseFloat(rate)` on a VAT rate at `endOfDayPreview.ts:472`).
- **Green claim:** I ran the six declared suites myself — `6 passed (6) / 75 passed (75)`, exactly the report's figure. No vitest worker pools left behind.
- **R-5 (device Arabic):** `apps/pos/src/locales/` still contains only `en/` and `fr/`; the ticket at `docs/superpowers/tickets/2026-08-11-pos-device-arabic-locale.md` carries all four mandated contents (no `ar` tree at baseline · `i18n.ts` two languages + four namespaces · RTL implications · the `sv11-arabic-device-locale` gate) and states it does not block. Correctly non-blocking.
- **Fix-round replay honesty:** report `:441` claims only 3 of 38 failed on revert, and explains that the four over/short cases stayed green because their production branch shipped in `54e1341b6`. I checked `CashDrawerRevealSummary.tsx:24-29` — that is accurate; the fix round added tests, not the branch.

Both round-1 P2s are closed and independently verified. What remains is one new P3 introduced by the fix (a one-minor-unit display decomposition that does not sum, structurally reachable but with no in-repo writer, and a report sentence at `:440` that overstates it) plus three carried P3s, all recorded in the tree. Per the brief's gate rule, P3s may ship with the record. Findings 1 and 2 are each a one-line fix and should be picked up in the M5 whole-lane pass rather than held here.

VERDICT: ACCEPT
