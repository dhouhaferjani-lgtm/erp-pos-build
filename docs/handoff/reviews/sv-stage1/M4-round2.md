## M4 (SV-10) — Round 2 adversarial merge gate

**Diff:** `df85d43f4..HEAD`. M4 delivery = `39a72abc0`, `1885a764e`, `314ea156e`, `e5152f669`. Backend files in the range belong to M3 (already ACCEPTed) and were not re-litigated.

**Lenses:** `fiscal-pos` — applies (shift-close disclosure semantics). `frontend-conventions` — applies (rendered-output tests, tokens, i18n). `treasury`/`tenancy-authz` — not named for M4, not applied.

**Independently reproduced:** `CashReconciliationSection.test.tsx` (16) + `EndOfDayPreviewModal.test.tsx` (27) + `CashCountTable.test.tsx` (8) = **51/51 green**, matching the report. `npx tsc --noEmit` exit 0.

The round-1 P1 (payments table) and P2/#3 (electronic Actual cell) are genuinely closed — I re-verified both gates and their rendered regressions. What follows is new.

---

## Register

### 1. P1 — CONFIRMED — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:257-273` (leak at `:269`), certified Clean by the audit
**On the first End-of-Day open of every app session, the legacy card renders the exact `expected_cash` — because `cashCountEnabled` is *temporally* false while the fraud-settings fetch is in flight.**

- `fraudSettings` initialises to `null` (`Header.tsx:77`) and its **only** setters live inside the effect that fires when the modal opens (`Header.tsx:112-114` → `:119-125`, and the offline catch at `:192`). It is never populated before that, and never reset on close.
- That effect awaits `Promise.all([fetchFraudSettings(), fetchAuthorizedManagers()])` — **two HTTP round trips**.
- The modal's own effect (`EndOfDayPreviewModal.tsx:109-157`) builds the preview from **local SQLite** and sets `phase='preview'`.
- The preview block at `:210` renders as soon as `phase==='preview' && preview!==null` — it does **not** wait on `fraudSettings`. Inside it, `cashCountEnabled` (`:93-99`) is false while `fraudSettings` is null, so the `{!cashCountEnabled && …}` branch at `:257` renders **`format(preview.expected_cash)`** at `:269`.

**Failure scenario:** terminal launches, cashier taps the shift badge (`Header.tsx:611` — not manager-gated). Local preview resolves in ~20 ms; the two API calls resolve in ~300 ms online, or hang to the HTTP timeout offline. For that entire window the modal displays a large bold **"Expected Cash 130.00"** card. Then it vanishes and the blind count begins — with the cashier already knowing the answer. Offline (the case the whole B6/B7 cache machinery exists for) the window is seconds.

**Why the audit missed it:** row 1 certifies this path Clean on *"the caller passes all activation props at `Header.tsx:699-714`; the modal requires all of them at `:89-103`"* — a **static** prop-list argument. Props are passed, but `fraudSettings` is `null` at mount. Row 2 even states the qualifier — *"No, **when the cash-count flow is active**"* — and then never follows the path where it is not yet active under a blind policy. The row's supporting citation (`Header.tsx:338-354`) proves the *close* is fail-closed, not that the *magnitude* is hidden; those are different properties. This is precisely the M4 antidote firing: spot-check a "clean" verdict, and it fails.

**In-scope:** yes. §5.1 is "no surface … before Commit Counts", and the fix is narrow (defer the preview block, or the legacy card, until the policy has resolved-or-failed — a fail-closed `policyLoaded` signal alongside the `hidePaymentAmounts` gate just added).

---

### 2. P2 — CONFIRMED — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:245` + `CashReconciliationSection.tsx:259-265`
**Expected cash is still exactly reconstructible pre-commit — via the Gross Sales card, which the round-2 fix left rendered while hiding the payment row it duplicates.**

- `gross_sales = Σ receipt.total` over sale rows only (`endOfDayPreview.ts:235`).
- `expected_cash = opening + cashTendered − change + drawerNet − cashRefundImpact` (`endOfDayPreview.ts:460-464`).
- On a **cash-only shift with no drawer ops and no legacy refund**, `Σ receipt.total ≡ cashTendered − change`, so **`expected_cash = opening_cash + gross_sales` exactly**.
- `opening_cash` is rendered unconditionally by M2's instruction line (`CashReconciliationSection.tsx:259-265`); `gross_sales` is rendered unconditionally at `EndOfDayPreviewModal.tsx:245`. Neither is behind `hidePaymentAmounts`.

**Failure scenario:** IziPOS coffee-shop / parapharmacy terminal, cash-only day. Blind mode on. Cashier opens End of Day: "Count all the cash in the drawer, including the opening float of **100.00**" and, two rows down, **Gross Sales 45.00**. Expected = 145.00. Enters 145.00, zero variance, control defeated — with the payments table dutifully showing "—".

The fix's own comment at `:100-102` states the rationale: *"CASH plus the visible opening float can reconstruct expected cash."* That reasoning applies verbatim to `gross_sales`, which was left alone. The audit's Clean verdict — *"Sales/VAT summaries remain because they are not the per-tender expected/variance fields identified by the review"* — is a scope argument the fix itself abandoned one line earlier. Either extend the gate to the sales summary cards, or record the residual explicitly in the audit + ticket it for owner ruling; a silent asymmetry is not an option after the round-1 P2 was closed on exactly this ground.

---

### 3. P3 — CONFIRMED — `apps/pos/src/components/pos/XReportModal.tsx:100-107`, absent from the audit
**The X Report renders the same per-method CASH total the round-2 fix just suppressed, and the audit does not enumerate it at all.**

The brief requires the audit to cover *"any sibling surface that renders a variance-derived value before Commit Counts."* The audit's sibling table lists `ShiftClosurePage`, `ZReportModal`, `ZReportListPage`, `CloseShiftModal` and the theme preview — but not the X Report, which is reachable mid-shift from the same header (`Header.tsx:293-323`, `:721`, `:729-735`) and renders `format(row.total_amount)` per payment method plus Gross Sales.

**Mitigation (why P3, not P2):** the menu entry is `managerOnly: true` and is filtered for non-managers (`ReportsMenu.tsx:60`, `:65`), so it sits in the same manager-only class as `ShiftClosurePage`, which *was* enumerated and ticketed. The defect is the **enumeration gap in the deliverable**, not an unguarded cashier path. It needs a row in the audit file with that reasoning stated.

---

### 4. P3 — PLAUSIBLE — `apps/pos/src/components/pos/CashReconciliationSection.tsx:90`
**`useState(!blindMode)` captures the policy once; a policy that flips `false → true` mid-session leaves `committed` stuck at `true`, revealing the Expected column under blind mode.**

`fraudSettings` is never reset on modal close (`Header.tsx:77` is the only initialiser), so a second EOD open re-mounts the section with the **stale** policy object while the refetch is in flight. If the stale value was non-blind, `committed` initialises `true` and the later `blindMode=true` prop never re-runs the initialiser — `showExpected` at `CashCountTable.tsx:63` is unconditionally true. Note the deploy correlation: **M3's own SV-9 migration is exactly a `false → true` flip for existing tenants.** Pre-existing pattern (not introduced by M4), race-ordering dependent, hence PLAUSIBLE/P3 — but it belongs in the audit's render-path enumeration and, given #1, is worth fixing with the same `policyLoaded` signal.

---

## Bypasses I tried that FAILED (defences hold)

- **Boolean-coercion bypass of the new strict gate.** `hidePaymentAmounts` uses `=== true` while `blindMode` uses a truthy check — a SQLite `1` would desync them. Refuted: the server casts `(bool)` (`FraudSettingsResolver.php:30,45`, DTO `:19`) and the device cache maps `r.require_blind_cash_count === 1` to a real boolean (`companyFraudSettingsCacheRepository.ts:99`). No coercion path reaches the modal.
- **Parent/child commit desync via remount.** `cashCountsCommitted` lives in the parent, `committed` in the child. No phase transition unmounts only the child (`:210` covers `preview`+`confirming`; `error`/`success` drop the whole block, and `isOpen=false` resets both at `:118`). No divergence path found.
- **Omitted `onCommit`.** Optional prop; if a future caller omits it, amounts stay hidden forever — **fails closed**. Correct direction.
- **Existing defence weakened?** No. The SECURITY comment and `!cashCountEnabled` guard at `:250-257` are byte-unchanged from base; `git diff` on that hunk is empty.
- **Reveal/reason/PIN gates.** `CashDrawerRevealSummary` mounts only on `committed && cashTender && cashVariance` (`:301`); reason `:312`; PIN `:334`; `verifiedManager` has exactly one setter (`:345`) inside the gated panel. Severity is computed in memory (`:129-198`) and never interpolated into a prompt. All hold.
- **Non-blind regression.** `hidePaymentAmounts` requires `require_blind_cash_count === true`; legacy preview-only rendering unchanged. Confirmed by the pre-existing non-blind tests still green.
- **Vacuous new tests?** No. Both new regressions assert **rendered text scoped to the payments table / Actual cell** (rule 17, not CSS classes), and the report's revert-replay is specific and credible (2 failed / 33 skipped on production-only revert). Reproduced green at 51/51.
- **Standing checks.** M4's production delta touches rendering only — no float on money, no new i18n strings (the `'—'` glyph matches the pre-existing placeholder at `:136`), no migration, no queue, no `app()`, no tenant-scoped query keys, no `any`. Clean.

---

## Rationale

The round-1 findings were closed properly and the fix is well-shaped. But M4's deliverable *is* the enumeration, and its acceptance is absolute — "**no** surface renders expected or variance magnitude before Commit Counts." Finding 1 is the exact `expected_cash` figure, rendered by the very card the audit certified Clean, on the first close of every session; finding 2 leaves an exact reconstruction on the same screen using reasoning the fix itself adopted and then didn't apply. Both are inside R-8's narrow fix permission.

VERDICT: CHANGES-REQUIRED
