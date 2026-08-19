## M4 (SV-10) — Round 3 adversarial merge gate

**Diff reviewed:** `df85d43f4..HEAD`; M4's own delta isolated as `1eef6fe79..HEAD` (commits `39a72abc0`, `1885a764e`, `314ea156e`, `e5152f669`, `00e8667af`, `8066db447`). Backend files in the wider range belong to M1–M3 (already ACCEPTed) and were not re-litigated.

**Lenses.** `fiscal-pos` — applies (shift-close disclosure + Z availability). `frontend-conventions` — applies (rendered-output tests, tokens, i18n). `treasury` / `tenancy-authz` — not named for M4; not applied. **Standing checks N/A by construction:** M4's production delta is four `apps/pos` React files only — no migration, no queue, no `app()`, no PHP, no tenant query key, no money arithmetic added (`format()`/`'—'` only), so Rule 19, migration-safety, horizon coverage and constructor-injection checks have no surface here. Confirmed by `git diff --stat 1eef6fe79..HEAD`.

**Independently reproduced:** `Header.test.tsx` + `CashReconciliationSection.test.tsx` + `CashCountTable.test.tsx` + `EndOfDayPreviewModal.test.tsx` = **61/61 green**, matching the report. `npx tsc --noEmit` exit 0. `eslint` on the four changed production files: 0 errors, 1 pre-existing `set-state-in-effect` warning at `CashReconciliationSection.tsx:106` — exactly as reported.

**Round-1 and round-2 findings are genuinely closed.** I re-verified the payments/VAT/summary gate (`EndOfDayPreviewModal.tsx:110-113`, `:269-280`, `:328-336`, `:362-364`), the electronic-Actual gate (`CashCountTable.tsx:139-141`), the first-open race fix (`Header.tsx:237-244` → `:721`; modal `:103-106`, `:204-234`), and that the existing SECURITY defence at `EndOfDayPreviewModal.tsx:283-306` is byte-unchanged. **Spot-checks of two "clean" verdicts hold:** `ZReportModal` has no production JSX caller (only `FiscalReportModals.test.tsx`), and the X Report menu item is genuinely manager-filtered (`ReportsMenu.tsx:60`, `:65`).

What follows is new.

---

## Register

### 1. P2 — CONFIRMED — `apps/pos/src/components/pos/TodaySalesPanel.tsx:118` (via `AppShell.tsx:218`, `ReportsMenu.tsx:63,65`) — absent from the audit's enumeration
**The exact `gross_sales` figure that round 2 suppressed in the modal is two taps away on a cashier-accessible page, and that page appears nowhere in the audit.**

- Round-2 finding #2 was accepted on this reasoning: on a cash-only, no-drawer-movement shift, `expected_cash = opening_cash + gross_sales`, and `opening_cash` is rendered unconditionally pre-commit by M2's instruction line (`CashReconciliationSection.tsx:259-265`). The fix hid gross/net/tax in the modal accordingly.
- `TodaySalesPage` renders `format(totalSales)` at `TodaySalesPanel.tsx:118`, where `totalSales = bcsum(non-voided sale receipt totals)` (`:84`) — the same aggregate as `endOfDayPreview.ts:235` (`grossSales`, non-voided/non-training/non-refund). It also renders every receipt's total and payment label (`getPaymentLabel`, `:24-27`).
- Access: the Reports button renders for **any operator with an open shift** (`Header.tsx:676-685`); `todaySales` and `transactionHistory` are `managerOnly: false` (`ReportsMenu.tsx:61,63`), and `/sales` is **not** manager-gated (`AppShell.tsx:218`) — unlike `/shift`, `/reports`, `/reports/z`.

**Failure scenario:** cash-only IziPOS day, blind mode on. Cashier opens End of Day, reads *"Count all the cash in the drawer, including the opening float of 100.00"*, cancels (nothing is committed, no penalty), taps Reports → Today's Sales → **Total Sales 45.00**, reopens End of Day and enters 145.00. Zero variance, blind control defeated — with the payments table, VAT table and gross-sales card all dutifully showing "—".

**Why this is a gate failure, not a scope quibble:** M4's deliverable *is* the enumeration, and the brief requires *"any sibling surface that renders a variance-derived value before Commit Counts"*. The sibling table lists `ShiftClosurePage`, `ZReportModal`, `XReportModal`, `ZReportListPage`, `CloseShiftModal` and the theme preview — every one of them manager-gated or unreachable — and omits the one **cashier-reachable** money surface. Consequently the audit's closing sentence, *"no reachable cashier pre-commit surface renders or exactly reconstructs expected-cash or variance magnitude under blind mode"*, is false as written. Gating a cashier's own sales history during an open blind count is a product decision larger than SV-10, so R-8's remedy applies: **an audit row with the verdict + a ticket**, and the Result sentence corrected. Silently leaving it out after round 2 closed the identical reasoning inside the modal is the asymmetry round 2 already refused.

---

### 2. P2 — CONFIRMED — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:216-219`
**M4's only new user-facing string has no `en` and no `fr` key; the French device renders English.**

`t('cash_count.policy_unavailable', { defaultValue: 'Cannot close this shift: …' })` is the blocking error an operator sees when the close is refused. Neither `apps/pos/src/locales/en/pos.json` nor `.../fr/pos.json` contains `cash_count.policy_unavailable` (verified by key dump: both files carry the same 18 `cash_count` keys, none of them this one; `grep -rn "policy_unavailable" apps/pos/src/locales` → no match). i18next therefore falls back to the inline English default in **every** locale.

This is a direct breach of the brief's house rule (*"Rule 11 i18n: every user-facing string through `t()`; **en + fr** on the device (R-5)"*) and of the pattern M2 itself followed one milestone earlier — `count_instruction`, `expected_includes_float`, `no_difference` and the whole `summary` subtree were added to **both** locale files in `54e1341b6`.

**Failure scenario:** a French Otospex terminal whose policy cache is empty opens End of Day and is blocked by an English-only sentence — on the one screen where the operator must understand the remediation ("connect to the network once, then retry").

---

### 3. P2 — CONFIRMED — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:105-106`, `:211-234` + `apps/pos/src/components/Header.tsx:178-229`
**The round-2 fail-closed gate converts "policy unavailable" from a degraded close into a hard block on closing the shift at all — an availability change beyond the row's permission, with no ticket, no deploy note, and no covering test.**

- `cashCountPolicyUnavailable = cashCountPolicyResolved === true && fraudSettings == null` (`:105-106`) suppresses the entire preview block (`:231-234`), leaving only an error and **Cancel** (`:211-229`). There is no other production close path: `ShiftClosurePage`'s Close Shift is inert, `CloseShiftModal` is an orphan.
- That state is reachable, not theoretical. The online fetch failing is the normal offline case; the offline fallback then depends on `company_fraud_settings_cache`, whose only eager population is **fire-and-forget with a swallowing catch** (`terminalStore.ts:528-534`). A terminal activated while offline, or whose pre-warm 401/500'd, has an empty cache; `getCompanyFraudSettings` returns null and nothing sets `fraudSettings` (`Header.tsx:192-201`), while the `finally` still marks the policy resolved (`:227-229`).
- Before M4 that same device fell through to legacy preview-only mode and could close. After M4 it cannot produce a Z report until connectivity returns.
- Note the deliberate contrast one screen over: the payment-policy pre-warm documents its own failure mode as *"fail-closed (exact behavior, no rounding, no auto-accept)"* — i.e. **degrade**, not block.

Blocking may well be the right call now that SV-9 makes blind counting mandatory, but it is a shift-close/NF525 availability decision, and R-8 is explicit: *"Anything larger → a ticket + the report."* The audit and report present the gate as pure gain and never name the trade-off; no ticket exists (the only M4 ticket covers `ShiftClosurePage`). The modal test covers the `fraudSettings: null, resolved: true` prop combination, but nothing covers the Header path that produces it (`fetchFraudSettings` rejecting **and** `getCompanyFraudSettings` returning null), so the real-world trigger is untested.

---

### 4. P3 — CONFIRMED — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:417-435` and `:376-384`
**Two money rows in the very modal the audit walked escape both the new gate and the enumeration.**

- Net cash rounding renders `format(preview.cash_rounding_summary.totalAdjustment)` unconditionally at `:428` — it is **not** behind `hideFinancialAmounts`, and it is live today (`endOfDayPreview.ts:504-506` populates it from real `cash_rounding_adjustment` columns).
- `ToleranceDrillDown` renders `totalAmount` plus an expandable per-receipt list (`ToleranceDrillDown.tsx:57-59`), gated only on `writeoffCount > 0` — data-driven from `tolerance_shortfall` (`endOfDayPreview.ts:249-251`), not a hard zero.

Neither is an expected or variance magnitude, so §5.1's literal acceptance still holds and I am **not** asking for a gate. But the audit's "End-of-day financial summaries" row enumerates only the summary cards, VAT breakdown and payment totals, and claims the render path was followed rather than inferred. Two money rows in the same JSX subtree, one of them live, deserve a stated verdict — that is precisely the M4 antidote ("states what was checked and what was found clean").

---

### 5. P3 — PLAUSIBLE — `apps/pos/src/components/Header.tsx:227-229`, `:235`, `:237-244`
**The fail-closed re-arm is scoped to *open*, not to *every policy refresh*, so round-2 finding #4's stale-initializer path survives in a narrow window.**

`handleOpenEndOfDay` resets `cashCountPolicyResolved` to `false` (`:242`), but the fetch effect (deps `[showEndOfDay, terminal, companyId]`, `:235`) never re-arms it — it only ever sets it `true` (`:228`). `refreshTerminalRecord` replaces the terminal object on any real field change, including live counting-block/zone advisories (`terminalStore.ts:1020-1041`), which can fire while the EOD modal is open. In that window the effect re-runs, `cashCountPolicyResolved` stays `true` with the *previous* policy, the section is never unmounted, and `useState(!blindMode)` (`CashReconciliationSection.tsx:90`) keeps `committed = true` from a stale non-blind policy — `showExpected` at `CashCountTable.tsx:63` then renders the Expected column with no commit. Requires a terminal-record change and a `false → true` policy flip inside the same open (the exact SV-9 migration flip), hence PLAUSIBLE. Cheap remedy: set `cashCountPolicyResolved` false at the top of the effect body, not only in the open handler.

---

### 6. P3 — CONFIRMED — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:211-221`
**A genuine preview error is masked by the policy message.** The branch fires on `phase === 'error' || (phase === 'preview' && unavailable)`, but the ternary inside checks only `cashCountPolicyUnavailable`. When the preview build fails *and* the policy is unavailable, the operator and any screenshot-based support path lose the real `errorMessage` (e.g. `No company selected`) and see the sync advice instead. Scope the ternary to the branch that produced it.

---

## Bypasses I tried that FAILED (defences hold)

- **DOM residue on hidden values.** `SummaryCard` (`:507-514`) has no `title`/`aria-label` carrying the value; the Expected/Variance columns are **not rendered at all** (`CashCountTable.tsx:77-89`, `:116-120`, `:144-155`), not CSS-hidden. Nothing recoverable from the DOM or accessibility tree.
- **Numpad presets.** `CurrencyNumpad` takes no expected/suggested value (grep for `expected|preset|suggest` → empty). No "fill expected" shortcut.
- **Second EOD-open entry point.** `setShowEndOfDay(true)` has exactly one call site (`Header.tsx:243`), and it is the re-armed handler. No unguarded opener.
- **Parent/child commit desync.** `cashCountsCommitted` (parent) vs `committed` (child): the child cannot reach `committed=true` under blind mode except through the gated button (`CashReconciliationSection.tsx:278-291`), which always fires `onCommit`; `isOpen=false` resets both (`:117-127`). Omitting the optional `onCommit` fails **closed**. No divergence found.
- **Truthy-coercion desync** between `require_blind_cash_count === true` (`:112`) and the child's truthy `blindMode` — re-refuted: the cache maps SQLite `1` to a real boolean before it reaches the modal.
- **Existing defence weakened while tidying?** No — `EndOfDayPreviewModal.tsx:283-306` (SECURITY comment + `!cashCountEnabled` guard) is byte-identical to base; only the enclosing conditional changed.
- **Non-blind / legacy regression.** `hideFinancialAmounts` requires `require_blind_cash_count === true`; `cashCountPolicyResolved` is optional, so unmigrated callers render exactly as before. Pre-existing non-blind tests still green.
- **Vacuous tests?** No. The new regressions assert rendered amounts (`30.00`, `15.00`, `430.00`, `45.00`, `37.82`, `7.18`) scoped with `within()`, plus absence/presence across the commit boundary — rendered output, not keys or classes (rule 17). The revert/replay evidence is specific (2 failed / 33 skipped in round 1; 4/4 selected security cases failing in round 2) and consistent with the code.
- **Confirmation/authorization semantics.** `isReady`, `blindCountUsed`, the cash-count payload and the sealed Z inputs are untouched by M4; the print action remains success-phase-only (`:466`, `:484-492`).

---

## Rationale

The security engineering in rounds 1–2 is sound and I could not break the modal itself. What fails is the deliverable M4 is actually graded on. Finding 1 shows the round-2 derivation fix is defeated by a cashier-reachable page the audit never enumerated, which makes the audit's own closing claim untrue. Finding 2 ships M4's single new user-facing string with no `en`/`fr` key, breaking a house rule M2 satisfied one milestone earlier. Finding 3 lands a shift-close availability change — reachable via an already-swallowed cache pre-warm failure — with no ticket and no covering test, which is exactly what R-8 requires to be recorded rather than absorbed silently. All three remedies are small: two audit rows, a ticket, six locale lines, and one test.

VERDICT: CHANGES-REQUIRED
