# IziPOS Desktop — Q2 2026 Release Smoke Protocol

**Date created:** 2026-04-28
**Target application:** IziPOS desktop (Tauri 2 desktop app — `apps/pos/`)
**Released to main:** PR #58 (merge commit `50055029`, 2026-04-28)
**Tester:** ____________________
**Test session date:** ____________________

---

## About this document

This protocol guides a tester through the IziPOS desktop application after the Q2 2026 release. The goal is to confirm that the new features and bug fixes work end-to-end, and to catch any regressions before customers see them.

This release shipped **548 commits** spanning multiple major workstreams. The most user-visible changes for IziPOS are:
- **Cash counting + fraud thresholds** — new End-of-Day cash reconciliation flow with manager-PIN escalation
- **Payment tolerance** — short-pay within tolerance is now accepted as a "rounding" writeoff
- **Discount UX** — discount modals are now fullscreen
- **Receipt formatting** — currency-aware decimal display (EUR shows €0.02, TND shows 0.020 TND)
- **Z-report** — new schema with cash-count details + tolerance summary
- **Hardening** — better error messages, currency-aware admin inputs, design tokens

Other clusters (AutoSpecs / Otospex automotive, Document module updates) are NOT in scope for this protocol — they're tested separately.

---

## How to use this document

### Format

This document has two parts:
1. **Overview / setup instructions** — read first, complete the setup checklist before starting.
2. **Test scenarios table** — go through one row at a time. The table is designed so it can be copied into Excel or Google Sheets directly.

### How to copy the table into Excel / Google Sheets

The "Test scenarios" section below uses a markdown table. To get it into a spreadsheet:

1. **Google Sheets**: Open a new sheet. Paste the markdown directly into A1 — Google Sheets auto-converts it. If it doesn't, paste it into a Doc first, copy from the Doc, then into Sheets.
2. **Excel**: Save the table as CSV. Or use an online converter (search "markdown table to CSV"). Or paste into Google Sheets first, then File → Download → Microsoft Excel.

If you have a markdown editor like Typora or Obsidian, exporting to CSV is one click.

### Filling out the table

For each test:
- **Status**: enter one of `PASS` / `FAIL` / `BLOCKED` / `SKIP` / `N/A`
- **Actual Behavior**: describe what happened, especially if different from expected. Include screenshots if you can — paste in a separate column or attach to the row in Sheets.
- **Severity**: only required if `FAIL`. Use one of `CRITICAL` / `HIGH` / `MEDIUM` / `LOW` / `INFO`.
  - **CRITICAL** = data loss, fiscal-chain corruption, or app crash that blocks all work
  - **HIGH** = feature is broken (e.g., can't complete a sale)
  - **MEDIUM** = feature works but with degraded UX (e.g., wrong label, slow response)
  - **LOW** = cosmetic issue (e.g., typo, wrong color)
  - **INFO** = observation worth flagging but not actionable
- **Notes**: anything else — environment, repro steps if intermittent, related test IDs.
- **Tester**: your name or initials.
- **Date**: when the test was run.

### Reporting issues

For any `FAIL` with `CRITICAL` or `HIGH` severity, also send a quick message to the dev team immediately — don't wait until the end of the session. Include the Test ID and a brief description.

For `MEDIUM` and below: completing the table and handing it back is sufficient.

---

## Pre-test setup

Complete this checklist before starting the scenarios. Do NOT proceed if any item fails — escalate to the dev team.

| # | Setup item | Done? |
|---|---|---|
| S1 | IziPOS desktop app is installed (latest build from main, post-`50055029`) | ☐ |
| S2 | Application starts and shows the login screen | ☐ |
| S3 | Test tenant is configured with vertical = `retail` (NOT automotive — that's Otospex) | ☐ |
| S4 | Test tenant has at least: 1 cashier user, 1 manager user, 1 operator user (each with a PIN) | ☐ |
| S5 | Test tenant has at least 5 products configured with prices in EUR (or your test currency) | ☐ |
| S6 | At least one tender method is configured for "Cash" |  ☐ |
| S7 | At least one tender method is configured for "Card" | ☐ |
| S8 | Fraud / cash-control settings on the tenant: soft variance ≤ €5, hard variance ≤ €20 (default), blind-mode toggle visible | ☐ |
| S9 | Receipt printer is configured (or set to "preview only" mode) | ☐ |
| S10 | Internet connection is on (offline scenarios will explicitly disconnect) | ☐ |

---

## Test scenarios

The table below is the heart of this protocol. Go through each test in order, in a single uninterrupted session if possible. Do NOT skip tests unless instructed.

| ID | Section | Title | Preconditions | Steps | Expected Result | Status | Actual Behavior | Severity | Notes | Tester | Date |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **A. Sign-in & Shift** | | | | | | | | | | | |
| A01 | Sign-in | Cashier signs in with valid PIN | App at login screen; cashier user exists | 1. Tap cashier user. 2. Enter correct PIN. 3. Submit. | App loads main POS screen with cashier name in header. | | | | | | |
| A02 | Sign-in | Sign-in with wrong PIN is rejected | App at login screen | 1. Tap any user. 2. Enter wrong PIN. 3. Submit. | Error message shown ("Invalid PIN" or similar). User stays on login screen. After 3 failed attempts, throttle / cooldown shown. | | | | | | |
| A03 | Sign-in | Sign-out from header (manager-only) | Logged in as cashier; cashier should NOT see sign-out | 1. Look at header for sign-out button. 2. Sign out as cashier (if button exists, click it). 3. Sign in as manager. 4. Look for sign-out. 5. Click. | Cashier does NOT see sign-out (or sees it but with permission denied). Manager sees sign-out and can use it. | | | | Tests permission gating on sign-out. | | |
| A04 | Shift | Open new shift with opening cash float | Logged in as cashier; no shift open on this terminal | 1. Open shift dialog. 2. Enter opening cash (e.g., 100.00 EUR). 3. Confirm. | Shift opens. Header shows shift status "Open". Opening cash recorded. | | | | | | |
| A05 | Shift | Re-opening with shift already open shows current state | Cashier with active shift | 1. Try to open another shift on the same terminal. | Existing open shift is shown; new-shift option disabled or asks to close first. | | | | | | |
| **B. Cash Sales — Tender Scenarios** | | | | | | | | | | | |
| B01 | Sale | Exact cash tender | Open shift; product priced 10.00 EUR | 1. Add product to cart. 2. Tap Pay. 3. Choose Cash. 4. Enter 10.00. 5. Confirm. | Receipt printed/previewed. Change due = 0.00. Receipt total = 10.00. | | | | | | |
| B02 | Sale | Overpay with change due | Same as B01 | 1. Add product. 2. Pay Cash. 3. Enter 20.00. 4. Confirm. | Change due = 10.00. Receipt shows tendered 20.00, change due 10.00. | | | | | | |
| B03 | Sale | Receipt scale (EUR) | Tenant currency = EUR | 1. Complete any cash sale. 2. Look at receipt monetary display. | All amounts shown with **2 decimals** (e.g., €10.00, NOT €10.000). | | | | Verifies PR #45 currency-aware display scale | | |
| B04 | Sale | Receipt scale (TND) | Tenant currency = TND (if available; SKIP if no TND tenant) | 1. Complete any cash sale on a TND tenant. 2. Look at receipt monetary display. | All amounts shown with **3 decimals** (e.g., 10.000 TND). | | | | Same fix; verifies TN compliance | | |
| B05 | Sale | Underpay outside tolerance is REJECTED | Open shift; product priced 10.00 EUR; tolerance settings active | 1. Add product 10.00. 2. Pay Cash. 3. Enter 8.00 (more than €0.50 short for FR or 0.5% of 10.00). 4. Try to confirm. | Sale is rejected with clear error message. Receipt is NOT printed. Cart preserved. | | | | Phase 2 A1 — outside tolerance branch | | |
| B06 | Sale | Underpay within tolerance is ACCEPTED with "Rounding" line | Open shift; product priced 10.02 EUR | 1. Add product 10.02. 2. Pay Cash. 3. Enter 10.00 (€0.02 short — within FR tolerance). 4. Confirm. | Sale is accepted. **Receipt shows a "Rounding" line of -€0.02.** Total paid line still 10.02. Change due = 0. | | | | Phase 2 A1 — tolerance writeoff path | | |
| B07 | Sale | Multi-tender (cash + card) | Open shift; product priced 50.00 EUR | 1. Add product 50.00. 2. Pay. 3. Tender 30.00 cash. 4. Tender 20.00 card. 5. Confirm. | Sale completed. Receipt shows two tenders. Total paid = 50.00. | | | | | | |
| B08 | Sale | Cancel mid-checkout | Cart with items | 1. Add items. 2. Tap Pay. 3. Cancel before completing. | Returns to cart screen with items intact. No receipt printed. | | | | | | |
| **C. Discounts** | | | | | | | | | | | |
| C01 | Discount | Apply line-level discount via fullscreen modal | Cart with one line | 1. Tap a line item. 2. Tap "Discount" / line discount action. 3. Modal opens fullscreen. 4. Enter percentage (e.g., 10%). 5. Confirm. | Modal opens **fullscreen** (not popover). Discount applied to that line only. Line total shows discount. Cart total recalculates. | | | | PR #38 fullscreen UX | | |
| C02 | Discount | Edit + remove line discount | Line with active discount | 1. Tap the line. 2. Open discount modal. 3. Change to 5% (or remove). 4. Confirm. | Discount updates or removes. Cart total recalculates correctly. | | | | | | |
| C03 | Discount | Apply transaction-level discount via fullscreen modal | Cart with multiple lines | 1. Tap "Discount" at the cart level (NOT a specific line). 2. Modal opens fullscreen. 3. Enter amount or percent. 4. Confirm. | Modal fullscreen. Discount applied to entire transaction. Receipt shows the transaction discount line. | | | | PR #38 + Phase 4 reactivity | | |
| C04 | Discount | Sub-tolerance discount on POS (UI hint) | Tenant has tolerance settings | 1. Cart with line at 10.00. 2. Try to apply a discount of 0.05 (below FR €0.50 tolerance threshold). | Either (a) the discount is allowed but flagged with a warning ("below tolerance") or (b) the input shows an inline below-tolerance hint. NOT silently accepted without feedback. | | | | Phase 4 anti-abuse FE mirror; behavior depends on policy | | |
| C05 | Discount | Tax math after discount is correct | Cart with taxed item | 1. Add product 100.00 (with 20% VAT). 2. Apply 10% discount. 3. Look at receipt totals. | Subtotal: 90.00. VAT: 18.00. Total: 108.00. (Or whatever the tax rules are — verify the math is internally consistent.) | | | | | | |
| **D. Hold / Recall + Offline** | | | | | | | | | | | |
| D01 | Hold | Hold a cart, recall it later | Cart with items | 1. Add items. 2. Tap Hold. 3. Cart clears. 4. Tap Recall (or held orders list). 5. Pick the held cart. | Held cart appears in the list. Recalling restores all items, prices, discounts. | | | | | | |
| D02 | Hold | Complete sale from recalled cart | After D01 | 1. Recall held cart. 2. Tap Pay. 3. Complete cash sale. | Sale completes. Receipt printed. Held cart removed from list. | | | | | | |
| D03 | Offline | Disconnect mid-shift, complete sale offline | Open shift; turn off WiFi or unplug ethernet | 1. Disconnect network. 2. Add product. 3. Pay cash. 4. Confirm. | Sale still completes. Receipt prints with a fiscal hash. App may show "offline" indicator. | | | | | | |
| D04 | Offline | Reconnect, sync queue drains | Continuing from D03 | 1. Reconnect network. 2. Wait ~30 seconds. 3. Look at sync indicator / pending count. | Sync indicator shows queue draining. Pending count reaches zero. No errors in console. | | | | Verifies fiscal chain integrity across offline → online | | |
| **E. Cash Counting / End of Day** | | | | | | | | | | | |
| E01 | EOD | Open End-of-Day modal | Open shift with at least one cash sale | 1. Tap End of Day / Close Shift. | EOD modal opens. Shows per-tender expected amounts (Cash, Card, etc.). Lists each tender row with expected and an empty actual field. | | | | PR #37 cash counting | | |
| E02 | EOD | Per-tender breakdown shows ALL enabled tenders | Same as E01 | 1. Look at tender list. | All enabled tenders appear, even those with zero transactions in this shift. | | | | Audit G15 fix | | |
| E03 | EOD | Enter actual cash count (matching expected) | EOD open | 1. Enter actual cash equal to expected. 2. Tap Confirm. | Variance = 0. Severity = "Balanced" or "Info". No manager PIN required. Z-report can be generated. | | | | | | |
| E04 | EOD | Variance within soft threshold | Soft = €5 (default) | 1. Enter actual cash = expected + 3.00 (€3 over). 2. Confirm. | Variance shown. Severity = "Warning" or similar non-critical. May require a reason note but NOT manager PIN. Z-report can be generated. | | | | | | |
| E05 | EOD | Variance within hard threshold (manager PIN) | Hard = €20 (default) | 1. Enter actual cash = expected + 15.00. 2. Confirm. | **Manager PIN modal appears.** Cashier cannot proceed without manager. Manager enters PIN, sale proceeds. | | | | PR #37 ManagerPin | | |
| E06 | EOD | Variance over hard threshold | Default settings | 1. Enter actual cash = expected + 50.00. 2. Confirm. | Either (a) blocked outright with a critical error, OR (b) requires manager PIN AND fires a fraud alert. | | | | | | |
| E07 | EOD | Blind mode on (Otospex-style) | Tenant has `blind_count = ON` | 1. Open EOD. 2. Verify expected amounts are HIDDEN. 3. Enter actual. 4. Confirm. | Expected column shows "—" or hidden. Cashier counts blind. Reveal happens after confirm. | | | | Otospex tenants typically blind=ON; IziPOS typically OFF | | |
| E08 | EOD | Reason note required for variance | Variance in soft+ range | 1. Enter actual cash with €3 over. 2. Try to confirm without reason note. | Form rejects with "Reason required" message. Adding reason allows confirm. | | | | | | |
| **F. Z-Report** | | | | | | | | | | | |
| F01 | Z | Generate Z-report after EOD | EOD completed | 1. After EOD confirm, generate Z-report (or it auto-prints). 2. Look at the printed Z. | Z prints. Contains: shift open/close times, opening cash, expected/actual per tender, variance, **cash count details**, **manager override info if used**, fiscal hash. | | | | PR #37 schema-2 Z | | |
| F02 | Z | Z-report includes tolerance_summary | At least one B06-style tolerance writeoff in this shift | 1. After E01-style EOD, generate Z. 2. Look for "Tolerance Writeoffs" or similar block. | Z shows total tolerance writeoff amount, count of writeoffs, currency. Even if shift had ZERO writeoffs, the block exists with zeros. | | | | Phase 2 A1 + Phase 4 wiring | | |
| F03 | Z | Receipt-printer-formatted Z-report | F01 succeeded | 1. Look at the Z output as it was printed. 2. Verify amounts use currency-native scale. | EUR amounts: 2 decimals. TND amounts: 3 decimals. NOT all printed at scale 3. | | | | PR #45 currency-aware print | | |
| F04 | Z | Z-report cash-count strings are localized | Test in EN, then in FR (if FR locale available) | 1. Set app language to FR. 2. Generate Z (run a quick shift end-to-end in FR). 3. Look at Z labels. | "CASH COUNT" → "RAPPROCHEMENT CAISSE" (or similar FR translation). NOT hardcoded English on the receipt. | | | | Audit HIGH-6 fix | | |
| **G. Permissions / Roles** | | | | | | | | | | | |
| G01 | Permissions | Cashier role limitations | Logged in as cashier | 1. Try to access fraud / cash-control settings page (admin). | Access denied or menu item hidden. Cashier cannot configure tolerance/fraud thresholds. | | | | | | |
| G02 | Permissions | Manager role | Logged in as manager | 1. Verify EOD escalation modal works. 2. Manager can enter PIN to override. | Manager-PIN flow works as in E05. | | | | | | |
| G03 | Permissions | Operator role for delivery edit/delete | Logged in as operator (if role is in scope) | 1. Try to edit a delivery note. 2. Try to delete one. | Operator can edit AND delete delivery notes (per the new permissions in audit HIGH-E remediation). | | | | If operator role isn't in IziPOS UI, mark N/A | | |

---

## Issue logging / escalation

For each test that fails:

1. Fill in the `Status`, `Actual Behavior`, `Severity`, and `Notes` columns.
2. If `CRITICAL` or `HIGH` severity, also notify the dev team immediately (Slack / email / however you usually escalate).
3. Capture a screenshot if possible. Drop it in a shared folder; reference the filename in `Notes`.
4. If a test reveals a CRASH, capture (a) what you were doing in `Steps`, (b) any error message in `Actual Behavior`, (c) the time of the crash. The dev team can correlate with logs.

---

## Sign-off

Tester signs off below when the protocol is complete (all tests have a Status, even if SKIP / N/A).

| Field | Value |
|---|---|
| Tester name | |
| Total tests | 35 |
| Passed | |
| Failed | |
| Blocked | |
| Skipped / N/A | |
| Highest severity issue | |
| Overall verdict | (Acceptable to ship / Needs follow-up / Major issues — block release) |
| Sign-off date | |
| Sign-off signature | |

---

## Coverage notes

This protocol covers the IziPOS-relevant changes in the Q2 2026 release. **Out of scope** for this protocol (test separately):

- AutoSpecs / Otospex automotive flows (vehicle, work order, scheduling, technician)
- Web admin (Document module, Compliance settings page, Tenant management, etc.)
- Mobile app (separate React Native build)
- B2B invoicing flows (close-with-writeoff, payment allocation) — those are web admin
- ML / data-acquisition services
- API-only endpoints with no IziPOS UI surface

If you encounter behavior that suggests a problem outside IziPOS (e.g., the Z-report uploads fine but the web admin shows wrong totals), note it in `Notes` with severity `INFO` and we'll route it to the right team.

---

**End of protocol.**
