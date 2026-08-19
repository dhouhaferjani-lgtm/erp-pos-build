Read-only review complete. I read the M2 section of the brief (`docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md:345-382`), the evidence contract row (`:439`), the house rules (`:446-470`), and verified every claim against the code at `54e1341b6` (+ `4f001de24`).

## Finding register — M2 (SV-11), round 1

**1. P2 — CONFIRMED (reachability PLAUSIBLE): a money formula on the shift-close path was regrouped inside a milestone declared "presentation only (R-4)", and the regrouping is not value-identical.**
`apps/pos/src/lib/offline/endOfDayPreview.ts:453-461`. Old: `(((O+T)−C)+D)−R`; new: `O + ((T−C)−R) + D`. The helpers round (`src/lib/decimal.ts:13` `Big.RM = 1`, `toFixed(scale)`), so regrouping moves the rounding boundaries. I ran the two expressions against big.js@7.0.1 from this worktree:

```
{"O":"100.00","T":"10.005","C":"0.005","D":"0","R":"0"} old=110.01 new=110.00 *** DIFFERENT ***
{"O":"100.00","T":"10.005","C":"0.001","D":"0","R":"0"} old=110.01 new=110.00 *** DIFFERENT ***
```

Reachability: `cashTenderedSum`/`cashChangeDueSum` accumulate via `bcadd(a,b)` with **no scale argument** (`endOfDayPreview.ts:343-345,359,381`), i.e. the default scale 3, while `scale` is 2 for EUR/USD/GBP (`src/lib/currency.ts:10-18`) — so sub-scale terms are structurally possible. I could not prove an in-repo writer that emits 3-dp EUR (`buildReceiptData.ts:221,327,329` format at currency decimals), hence PLAUSIBLE, not CONFIRMED, on reachability.

Failure scenario: for a 2-decimal currency where any receipt total, payment, or `change_due` carries a third decimal, `expected_cash` shifts one cent versus pre-M2. That value is **not** presentation-only — `apps/web`… no, on the device: `src/components/Header.tsx:403` passes `preview.expected_cash` straight into `closeShift(...)` as the recorded actual cash, and `CashReconciliationSection.tsx:113` uses it as the CASH tender's expected, so the same cent moves the displayed variance and can cross a soft/hard tolerance boundary (`severityForVariance`, `:61-70`). No test pins old == new; the two added preview assertions (`endOfDayPreview.test.ts:72-73,268-269`) exercise only whole-cent fixtures. Either revert the regrouping (derive `cash_sales_net` for display without touching the `expected_cash` expression) or add an equivalence test and declare the scope departure.

**2. P2 — CONFIRMED: two of the six dossier reveal labels ("Over"/"Short", "Excédent"/"Manquant") have zero coverage, and the report overstates it.**
`apps/pos/src/locales/en/pos.json:927-928`, `fr/pos.json:927-928`, rendered at `CashDrawerRevealSummary.tsx:26-30`. `grep -n "Excédent\|Manquant\|summary.over\|summary.short\|'Over'\|'Short'" src/components/pos/CashReconciliationSection.test.tsx` returns nothing. Every SV-11 test uses a balanced count, so line 6 is only ever exercised in its "No difference"/"Aucun écart" form. The evidence contract (brief `:439`) requires "the exact-string table checked line by line against the dossier"; the session report `docs/sessions/codex-sv-stage1-report.md:418` claims "English and French rendered-output assertions pin the exact instruction, float disclosure, **and all six reveal labels**" — that claim is false for the over/short variants. Failure scenario: a wrong key or a mistranslation in `summary.over`/`summary.short` ships silently; the FR path falls back to the component's English `defaultValue`, so a French cashier on the *only* case that triggers a mandatory written reason reads "Over" instead of "Excédent", and no test fails.

**3. P3 — CONFIRMED: `bg-surface-subtle` is not a defined design token (rule 18).**
`apps/pos/src/components/pos/molecules/CashDrawerRevealSummary.tsx:33`. `apps/pos/src/index.css:271-276` (`@theme inline`) defines `surface-canvas`, `surface-raised`, `surface-overlay`, `surface-sunken` — no `surface-subtle`. The class appears nowhere else in `apps/pos`. Tailwind 4 emits no rule for it, so the reveal panel ships with a border and no background fill. Cosmetic, but it is a dead class on the one new component.

**4. P3 — CONFIRMED: two reveal lines are labelled narrower than their contents.**
Line 2 "Cash sales (net of change)" is `T − C − cashRefundImpact` (`endOfDayPreview.ts:453-457`) — cash refunds are folded in. Line 3 "Paid in / paid out" is `drawerNet`, which also absorbs cash **account-payment** movements (`:443`); the added docblock says so outright (`:96-97` "Signed deposits, payouts, and cash account-payment movements"). The three lines still sum exactly to Expected, so no arithmetic defect. But on a shift with refunds or counter account payments, a cashier cannot reconcile line 2 against the Z-report cash-sales figure. The labels are the dossier's, so this is a note for M5/the owner rather than an M2 defect.

**5. P3 — CONFIRMED: the red-run evidence does not cover all five new tests.**
Report `:404` states the red run had "9 component cases total, with the three new SV-11 cases failing". The shipped file has 11 cases, five of them new (`CashReconciliationSection.test.tsx:141-245`). 11 − 5 = 6 pre-existing, so the 9/3 run predates two of the five, which were never shown red. Mitigated: the revert-replay claim (`:434`, "failed 7 of 33") is arithmetically consistent with all five component tests plus the two preview assertions going red, which I accept as after-the-fact non-vacuity proof. Fix the report's counts.

**6. P3 — CONFIRMED: the declared regression set omits two suites that cover the changed formula.**
Report `:411` declares four files. `src/lib/offline/__tests__/cashTenderedFormula.test.ts` and `src/lib/offline/__tests__/refundReportingEndToEnd.test.ts` both drive `buildEndOfDayPreview` — the refund suite is precisely the one that would catch finding 1's `cashRefundImpact` reordering. I ran them: 4/4 green, so no regression; house-rule/documentation gap only.

**7. P3 — the dossier's "single highest-value change" ships as de-emphasised muted text.**
`CashReconciliationSection.tsx:257` renders the instruction at `text-sm text-ink-muted`, and the bold the dossier puts on "all" / "tout" / "y compris le fonds de caisse" is dropped (the strings are plain). Presentation judgement, not a contract breach — flagging for the owner.

**8. P3 — PLAUSIBLE: "Counted" renders the raw keypad string.**
`CashReconciliationSection.tsx:302` passes `actuals[...]` unformatted; the test itself pins `'Counted100'` next to `'Expected in drawer100.00'`. `variances` only excludes `''` and `'.'` (`:136`), so an entry of `100.` would render as `100.` on the reveal. Consistent with the existing table cell (`CashCountTable.tsx:139`), so not a regression — `bcformat(raw, scale)` would align the two adjacent figures.

## Bypasses I tried that FAILED (i.e. the implementation held)

- **Blind-mode leak before commit:** the reveal is gated on `committed` (`:296`), `showExpected`/`showVariance` are `!blindMode || committed` (`CashCountTable.tsx:63-64`), and nothing is rendered-then-hidden. The FR test asserts the disclosure line absent pre-commit (`test:224-226`). The brief's SV-12 no-dead-DOM contract holds: single cash-sales row, no placeholder, no reserved slot, no `display:none` sibling.
- **Type break from the two new required `EndOfDayPreview` fields:** `samplePreview` (`EndOfDayPreviewModal.test.tsx:90`) is untyped, so no error; `pnpm typecheck` exits clean.
- **Float on money:** no `parseFloat`/`Number(...)` introduced; all new values are decimal strings through `bcadd`/`bcsub`/`bcformat`.
- **en/fr key parity:** both locales received identical key sets (3 top-level + 7 under `summary`).
- **Green claim:** I ran all four declared suites plus the two undeclared ones — 70/70 pass (33 + 33 + 4). No vitest zombie pools left behind.
- **Scope fence:** the M2 commit touches only `apps/pos` + docs; no `apps/api`, no Stage-2+ file, no event, no `TREASURY_SHIFT_VARIANCE_GL_ENABLED`.
- **R-5:** ticket `docs/superpowers/tickets/2026-08-11-pos-device-arabic-locale.md` exists and records the baseline, namespaces, RTL implications and the informational gate — correctly non-blocking.
- **"Écart" retention:** `fr/pos.json:917` still `"variance": "Écart"`, pinned by test.

Findings 1 and 2 are fix-before-merge: one is an unscoped, non-identical change to a money expression that reaches `closeShift`, the other is an evidence gap the milestone's own antidote clause names, compounded by a false completeness claim in the report.

VERDICT: CHANGES-REQUIRED
