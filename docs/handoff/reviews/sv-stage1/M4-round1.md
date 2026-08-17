# M4 Review — SV-10 blind-mode leak audit (round 1)

**Diff reviewed:** `df85d43f4..HEAD` (M4 delivery = `39a72abc0` + `1885a764e`: one new test block, the audit file, one ticket, report/progress records). No production code changed in M4.

**Lenses:** `fiscal-pos` (applies — shift-close disclosure semantics), `frontend-conventions` (applies — rendered-output test discipline, testids over CSS classes, i18n). `treasury`/`tenancy-authz` not named for M4; not applied.

**Independently verified green:** `CashReconciliationSection.test.tsx` (16) + `EndOfDayPreviewModal.test.tsx` (26) + `CashCountTable.test.tsx` (7) = **49/49**, matching the report's claim.

---

## Register

### 1. **P1 — CONFIRMED** — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:313`
**The exact expected amount of every non-cash *physical* tender is rendered pre-commit under blind mode, in the same modal, ~200px below the column that suppresses it.**

- `CashReconciliationSection.tsx:113` defines the blind-counted expected for a non-CASH tender as **literally `p.total_amount`** — the same field, off the same `preview.payment_methods` array.
- `CashCountTable.tsx:63` / `:77-81` / `:116-120` hide that value under `showExpected = !blindMode || committed`.
- `EndOfDayPreviewModal.tsx:295-319` then renders `format(row.total_amount)` for **every** row of that same array, inside the same `phase === 'preview'` block (`:201-219` mounts the reconciliation section in the same block). `committed` is section-local state; the payments table never sees it.
- Physical non-cash tenders are real, not hypothetical: `apps/api/database/seeders/PaymentMethodSeeder.php:105` (CHECK, TN), `:141` (TRAITE, TN), `:234` (CHECK, FR) all seed `'is_physical' => true`. `endOfDayPreview.ts:395-407` seeds **every** enabled physical method into `payment_methods`, so those rows always exist.

**Failure scenario:** TN/FR terminal, `require_blind_cash_count = true`, shift with CHECK tenders totalling `430.000`. Cashier opens End of Day. The Expected column is hidden — and the Payments table below shows `CHECK … 430.000`. The cashier types `430.000` into the CHECK actual and commits with zero variance. The blind gate is defeated for that tender before a single note is counted.

**Why this is in-scope, not "larger than SV-10":** R-8's fix permission is exactly "a surface that discloses expected … magnitude before Commit Counts under blind mode", and §5.1 acceptance is "**no** surface". This surface renders the identical value, not an approximation.

The audit's verdict for this row ("End-of-day sales/payment summaries … no expected/variance field is rendered … sales/tender facts … not `expected_cash` or a computed variance") is refuted by `CashReconciliationSection.tsx:113`: for every non-CASH physical tender, the "sales/tender fact" **is** the expected. This is precisely the M4 antidote firing — the reviewer's spot-check of a "clean" verdict fails.

---

### 2. **P2 — CONFIRMED (facts) / owner ruling needed (scope)** — `apps/pos/src/components/pos/CashReconciliationSection.tsx:257-263` + `EndOfDayPreviewModal.tsx:313`
**Expected cash is exactly derivable pre-commit from two figures rendered on the same screen — and the repo's own fixture proves it.**

- The M2 instruction line renders `preview.opening_cash` unconditionally, blind or not (`CashReconciliationSection.tsx:259`) — reveal line 1.
- The Payments table renders the CASH row's `total_amount`, which `endOfDayPreview.ts:390-392` explicitly nets change out of ("the cash method total reflects net cash retained in the drawer") — i.e. reveal line 2, the figure `CashDrawerRevealSummary.tsx:52` only shows **after** commit.
- `expected_cash = opening + cash_net + drawer_movements` (`endOfDayPreview.ts:456-460`). On any shift with no paid-in/paid-out and no legacy cash refund — the normal shift — the two rendered numbers sum to expected **exactly**.

**Failure scenario, with repo data:** `EndOfDayPreviewModal.test.tsx:99-108` uses `opening_cash: '100.00'`, CASH `total_amount: '30.00'`, `expected_cash: '130.00'`. The existing SECURITY test at `:474-486` asserts only that the *string* `130.00` is absent — while the modal renders "…opening float of 100.00" and a payments row "CASH … 30.00" on the same screen. 100 + 30 = 130.

The audit's dismissal ("ingredients from which a user can estimate drawer contents") understates this: it is not an estimate, it is addition of two adjacent displayed figures, and the second addend was **added by this wave** (M2's instruction line). Whether the fix lands in this row or a ticket is the owner's call under R-8 — but the audit cannot carry a **Clean** verdict on this reasoning.

---

### 3. **P2 — CONFIRMED** — `apps/pos/src/components/pos/organisms/CashCountTable.tsx:138-140`
**The audit's stated reason for clearing the Actual column is factually wrong for the non-physical branch.**

The audit says: *"The physical Actual field remains visible because it is the operator's own input (`:121-140`), not an expected/variance disclosure."* Lines `138-140` are the **`else`** branch of that ternary and render `{tender.expected_amount}` — an expected value, ungated by `showExpected`, under blind mode, pre-commit. For a CARD tender `expected_amount = p.total_amount` (`CashReconciliationSection.tsx:113`).

**Failure scenario:** blind mode, pre-commit — the Actual column shows CARD's expected total while the Expected column header is suppressed. Electronic tenders are not blind-counted so the operational harm is limited, which is why this is P2 not P1; but the audit's cited range is asserted clean on a description that does not match the code it cites.

---

### 4. **P3 — CONFIRMED** — `apps/pos/src/components/pos/CashReconciliationSection.test.tsx:276-312`
**The new regression renders `CashReconciliationSection` in isolation, so it structurally cannot cover the class of leak the audit claims to have cleared.**

The audit's strongest clean verdicts (payments table, sales summaries, legacy card interaction) all live in the **parent** modal. The only modal-level blind assertion is the pre-existing `:474-486` test, which checks one string. The M4 evidence contract asks for the audit to enumerate *render paths*; the coverage added stops at the component boundary, which is the same blind spot that produced finding 1.

---

## Checks that PASSED (bypasses I tried and failed to break)

- **Existing defence intact.** `git diff --stat <BASE>..HEAD -- apps/pos/src/components/pos/EndOfDayPreviewModal.tsx apps/pos/src/components/Header.tsx` is **empty**. The SECURITY comment + `!cashCountEnabled` guard are byte-unchanged at `:240-263`. R-8's "do not weaken" is satisfied.
- **Reveal summary unreachable pre-commit.** Gated at `CashReconciliationSection.tsx:296` by `committed && cashTender && cashVariance`; `CashDrawerRevealSummary` has no other caller.
- **Reason / PIN prompts.** `:307` and `:329` both require `committed`; `verifiedManager` has exactly one setter (`:340`) inside the gated PIN panel, so the `:347` badge cannot precede commit. Severity is computed in memory only (`:126-158`, `:174-196`) and never interpolated into either prompt.
- **Orphan components.** `ZReportModal` and `CloseShiftModal` have no production JSX caller — only barrel re-exports and tests. Audit claim holds.
- **`ShiftClosurePage`.** Verified static/demo: hard-coded `openingCash`/`expectedCash` at `:19-21`, manager-gated at `AppShell.tsx:222-225`, no `require_blind_cash_count` input, inert close button at `:120-129`. Correctly ticketed rather than redesigned (`docs/superpowers/tickets/2026-08-17-shift-closure-page-blind-count-parity.md`).
- **Non-blind path.** `showExpected = !blindMode || committed` leaves the legacy non-blind UX unchanged.
- **Standing checks.** M4 is test-only: no float on money (bcmath strings throughout), no new i18n strings, no migration, no queue, no `app()`, no tenant-scoped keys touched. Rule 17 respected — assertions are on rendered text and testids, not CSS classes. Mutation-proof (removing the `committed` guards → red) is an acceptable substitute for red-first on a no-fix audit milestone, and it was recorded.

---

## Verdict rationale

M4's process work is good — the audit does follow production callers, does record clean verdicts, and does ticket the out-of-row item instead of expanding the lane. But the milestone's own §5.1 acceptance is *"under blind mode, **no** surface renders expected or variance magnitude before Commit Counts"*, and finding 1 is a reachable, seeded-in-production surface rendering the exact expected magnitude verbatim. It is inside R-8's narrow fix permission, and the audit cleared it on reasoning the code contradicts. That is a P1 and it blocks.

VERDICT: CHANGES-REQUIRED
