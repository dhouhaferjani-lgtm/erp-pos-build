# Lane C wave-2 fix wave — FISCAL re-review (scoped re-gate)

**Range reviewed:** `8a849f9bd..6cb629d96` (8 commits). Worktree
`/Users/houssamr/Projects/syneriva/apps/erp.refund-chain`, branch `feat/v3-refund-chain-integration`,
read-only. Commits after `6cb629d96` ignored per brief.

**Contract:** `docs/sessions/LANE-C-wave2-consolidated-findings.md` (18) + orchestrator-issued
finding 19. Implementer's report: `docs/sessions/LANE-C-wave2-fixwave-report.md`.

**Scope:** verdict each finding ADDRESSED / NOT ADDRESSED + new breakage introduced BY the fix diff.
Verified-clean list not re-litigated. Orchestrator rulings (SALE-branch gross-as-net untouched; one
parked `Math.abs`; finding 10 via pre-PIN refusal; deferred minors) treated as accepted and confirmed
conformant, not flagged.

---

## Per-finding verdict

| # | Finding | Verdict | Evidence (file:line, read and verified) |
|---|---|---|---|
| 1 | X-report refund blindness | **ADDRESSED** | `apps/pos/src/api/reportApi.ts:426-505` — `receipt_kind === 'refund'` branch: sale-only `salesCount`/`grossSales`/`netSales`/`taxAmount`, positive-magnitude `refundsAmount` (`:441`), VAT subtracted with `lineNet = lineGross − lineVat` (`:449`), payment bucket subtracted (`:461`), `sales_count: salesCount` (`:512`), `refunds_count/refunds_amount` real (`:516-517`) and fed into the SIGNED event (`:565`). Query is `SELECT *` on `offline_receipts` with `toSqliteUtc(shiftOpenedAt)` (`:411-417`) so refund rows are actually in scope. |
| 2 | Mirror not bound to the signed original | **ADDRESSED** | `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:207-330`. Real binding, not another mirror read: signed row now selects `event_type/event_version/business_date/canonical_bytes` (`:216`); envelope type must be `SALE_RECEIPT` (`:229`); **byte equality** mirror↔signed row (`:244`); payload validated by the CANONICAL `validateSaleReceiptPayload()` (`:284`) — which does an exact key-set assert plus per-member `validateLineItem`/`validatePaymentRow` (`FiscalEventEngine.ts:1540,1695-1709`), so the `as` casts at `:317-320` are runtime-backed (`training_flag` via `assertBool` `:1597`, `transaction_discount_amount` via `assertMoneyString` `:1619`); `receipt_uuid` identity (`:292`); discriminator ∈ {SALE, TRAINING} (`:301`); signed `business_date` == row column (`:313`). Every path returns `null` ⇒ caller refuses. 6 real-authoring binding tests at `__tests__/resolveOriginalFiscalEventLocally.realAuthoring.test.ts:480-547`. |
| 3 | IEEE-754 quantity in the signed path | **ADDRESSED** (parked residual conforms to ruling) | `RefundLineInput.quantity: string` (`RefundReceiptV4Payload.ts:74-97`), normalized once at `refundCheckoutStore.ts:325`, carried verbatim into the snapshot/cap (`refundIntentRepository.ts:517-526`), the row mirror (`refundReceiptService.ts:92` `bcmul(line.quantity,'-1')`), and `original_line_references[i].quantity` (`RefundReceiptV4Payload.ts:449-457`) with a byte-equality assertion (`:450`). The one `Math.abs` at `:379` is the parked ruling. |
| 4 | Z/EOD gross-as-net VAT double-count | **ADDRESSED** | `zReportService.ts:855-872` (`lineNet = bcsub(lineGross, lineVat, decimals)`, explicit scale on every touched `bc*`), `endOfDayPreview.ts:279-291` (refund branch derives net = gross − vat; sale branch left byte-intact per ruling 1), X covered by finding 1. MANDATED e2e is real: `lib/offline/__tests__/refundReportingEndToEnd.test.ts` drives the REAL `createRefundReceipt()` (`:267`) on REAL SQLite with a REAL `FiscalEventEngine` (`:252`) and feeds the unmodified row through Z (`:293`), EOD (`:308`) and X (`:324`), asserting hand-computed −10.00/−2.00/−12.00 and the SIGNED X_REPORT totals (`:342-352`). |
| 5 | Payout confirm/dispute reconciliation | **ADDRESSED** | `refundIntentRepository.ts:321-340` — single guarded statement, one timestamp, opposite must be NULL, `rowsAffected !== 1` throws `InvalidRefundPayoutResolutionError`. Pending-confirmation = both null (`:382-389`); pending-reprint = either resolution non-null AND `printed_at IS NULL` (`:402-409`). |
| 6 | Approval re-author dead-letters signed refunds | **ADDRESSED** | `posOverrideAuthoring.ts:88-108` reads the authoritative `approval_id` back out of the RESOLVED approval event's own signed `envelope.payload` (`:203-204`), uses it for the override payload too; fallback = the fresh candidate only on unreadable bytes. |
| 7 | False `original_business_date` | **ADDRESSED** | `OriginalFiscalEventLocalView.businessDate` (`fiscalEventRepository.ts:158`) from the signed payload, cross-checked against the row (`:313`); threaded at `refundCheckoutStore.ts:1358` (`originalBusinessDate: v4Original.businessDate`), `approvalContext.businessDate` still dates the NEW event (`:1343`). |
| 8 | Card-paid original refunded in cash (§9.6) | **ADDRESSED** | `RefundReceiptV4Payload.ts:300-310` inside `assertOriginalRefundable()` — one implementation, lookup-level call at `refundCheckoutStore.ts:822` (before any intent draft / PIN) and builder re-assert at `:320`. Card / mixed / voucher / empty `payments[]` all refuse. `refusalErrorToKey()` (`refundCheckoutStore.ts:263-275`) is exhaustive-by-name. |
| 9 | Phase-2 acknowledgement endpoint missing | **ADDRESSED** | Route inside the `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` group — `apps/api/app/Modules/POS/routes.php:82-85`; controller `…/Presentation/Controllers/V4RefundAuthoringAcknowledgementController.php:49-72` (constructor injection, `Gate::any`, `Terminal::forCompany(...)->findOrFail`); service `…/Application/Services/V4RefundAuthoringAcknowledgementService.php:31-52` (Phase 1 required, idempotent, never moves the timestamp). Guard activation proved END-TO-END: `tests/Feature/POS/V4RefundAuthoringAcknowledgementTest.php:100-125` (guard inert before, `LegacyCorrectionRetiredException` after) — `LegacyCorrectionGuard.php:36` conditions on exactly that column. Device stops swallowing: `syncService.ts:1339-1387` (3 attempts, backoff, `console.error`, persisted `terminal_state.v4_refund_authoring_ack_error` via migration v66 `migrations.ts:2116-2142` + `terminalStateRepository.ts:254-276`, `logSyncOperation` row). |
| 10 | Per-line-discounted partial refund crashes post-PIN | **ADDRESSED** (ruling-allowed option 2) | `refundCheckoutStore.ts:952-968` — refusal at lookup level, before `createOrReuseActiveRefundIntent` (`:970`) and before any PIN; full-line refunds still allowed. |
| 11 | Reused-intent resume not idempotent | **ADDRESSED** | `resumeReusedIntent()` `refundCheckoutStore.ts:1103-1156`: `refund_event_appended` ⇒ verify `refund_fiscal_event_id` (`:1109`) AND the linked `offline_receipts` row (`:1121`), then refuse-with-reconciliation (`:1128`); `approval_authored` ⇒ recover evidence from the local chain mirror (`refundApprovalV3.ts:160-206`, fail-closed on any `approval_id`/scope/`policy_version`/supervisor/`approval_event_id` disagreement); `drafted` ⇒ unchanged. **`ensureApprovalAuthored()` cannot re-author fiscal events** — verified: `refundIntentRepository.ts:239-247` is a pure guarded SQL state transition with a re-read confirmation, no engine call; it runs unconditionally at `refundCheckoutStore.ts:1295`, after the approval is cached, so a failure refuses the settle instead of rolling it back. See Minor N-4 for an ordering shadow. |
| 12 | Post-ACK flips not atomic | **ADDRESSED** | `syncService.ts:364-394` — one `withWriteTransaction('fiscal')`; receipt flip requires exactly 1 row (`:373-378`, `updateReceiptStatusByIdempotencyKey` now returns the count, `offlineReceiptRepository.ts:249-269`); invalid intent transition suppressed ONLY on a re-read-confirmed `synced` (`:389-392`); everything else propagates to the outer catch, which marks the event `failed` for retry (`:505-515`). |
| 13 | Dispute evidence best-effort | **ADDRESSED** | `RefundPayoutReconciliationModal.tsx:257-291` — evidence authored FIRST, marker second; authoring failure `return`s without resolving the payout (`:288`); idempotent on `source_event_id = refundIntentId`; the unreachable `?? ''` replaced by an explicit refusal (`:258-266`). Matches the finding's "author before the marker" shape. |
| 14 | `subtotal` column = gross total | **ADDRESSED** | `refundReceiptService.ts:257` `bcmul(payload.subtotal,'-1',scale)`; asserted on the REAL row at `refundReportingEndToEnd.test.ts:280-284`. |
| 15 | AVOIR print lacks original ref/QR | **ADDRESSED (untested)** | `RefundPayoutReconciliationModal.tsx:111-141` resolves both from `intent.original_local_receipt_id` and passes them at `:96-101`. No test exercises it (print path mocked) — see Minor N-7. |
| 16 | Shift-receipts list hardcodes `'sale'` | **ADDRESSED** | `reportApi.ts:662-668` (`receipt_kind === 'refund' ? 'return' : 'sale'`); covered by the real-writer case at `refundReportingEndToEnd.test.ts:355-363`. |
| 17 | Test mocked the defective query | **ADDRESSED** | `RefundPayoutReconciliationModal.test.tsx` — the whole `refundIntentRepository` is un-mocked and runs on `SqliteTestAdapter` + real migrations (`:52-62`); only i18n, fiscal authoring and print stay mocked; asserts operator-visible outcomes (`:200-256`). |
| 18 | Cap must FAIL CLOSED + §4.4 erratum | **ADDRESSED** | `refundIntentRepository.ts:474-530` throws `CumulativeRefundSnapshotUnreadableError` on unparseable JSON / non-array / non-object entry / non-integer index / non-canonical quantity string; caught and refused at `refundCheckoutStore.ts:856-874`. Spec erratum landed at `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md:396-414` — blockquoted, dated 2026-08-01, orchestrator-ruled, states: backstop = hardening bound only, §12 server cap sole authority, tolerance nil / fail closed, canonical decimal strings. **Matches ruling 18.** |
| 19 | Legacy refunds must bound the device backstop | **ADDRESSED, with a defect** | `refundCheckoutStore.ts:1035-1069` + `localRefundRecordRepository.ts:128-151`. Receipt-level VALUE bound: original's `receipt_number` + `total` as ceiling, Σ magnitudes of `local_refund_records` for that number, plus this attempt's own `Σ|line_total|`, all `bcabs/bcadd/bccomp` at the original's currency scale; fails closed on unreadable ORIGINAL row (`:1042`), non-canonical legacy total (`localRefundRecordRepository.ts:141-147`), or any throw (`:1062-1068`). Typed `LegacyRefundRecordUnreadableError` + en/fr keys (`locales/{en,fr}/pos.json` `refundFlow.legacyRefundValueExceeded`). Real-SQLite repo tests (`__tests__/legacyRefundValueBackstop.test.ts`) + 4 store cases. **It CAN refuse a legitimate first refund when cash rounding is active — see N-1.** |

**Score: 19/19 ADDRESSED, 0 NOT ADDRESSED.**

---

## New breakage introduced BY the fix diff

### Important

**N-1 — [Important] `apps/pos/src/stores/refundCheckoutStore.ts:1045` (finding 19) — the value ceiling
is the original's ROUNDED total, so a legitimate FIRST full refund of a cash-rounded-down receipt is
refused.**

`evaluateLegacyRefundValueBound()` takes the ceiling from `offline_receipts.total`
(`:1045 originalTotal = bcabs(originalReceipt.total, scale)`) and compares it against
`Σ|cartItem.line_total|` (`:1055-1058`). But `offline_receipts.total` is the **rounded** total —
`receiptService.ts:347` writes `bcformat(input.policySnapshot.roundedTotal, decimals)` — while the
line mirror carries the **exact** gross line amounts. `roundCashTotal()`
(`lib/payment/cashRounding.ts:139-142`) is a round-to-nearest (`bcdiv(..., 0)` → big.js half-up), so
whenever the exact total rounds DOWN, `Σ|line_total| > total`.

Failure scenario (cash-only launch profile, `cashRoundingEnabled` + valid denomination +
`fiscal_schema_version === 3`, `checkoutPolicySnapshot.ts:163-178`): a sale with exact 12.34
rounds to 12.30. The customer returns everything. `legacyRefunded = 0`,
`thisRefundTotal = 12.34`, `projected 12.34 > originalTotal 12.30` ⇒ `begin()` refuses with
`refundFlow.legacyRefundValueExceeded` ("this receipt has already been refunded … for its full
value"), no intent drafted, no operator override. **Every full refund of a rounded-down receipt
becomes impossible on the v4 path**, with a message that names a cause that does not exist.

The row already carries what is needed: `offline_receipts.cash_rounding_adjustment`
(`offlineReceiptRepository.ts:63`, adjustment = rounded − exact). Suggested fix — one line:
```
const originalTotal = bcabs(
  bcsub(originalReceipt.total, originalReceipt.cash_rounding_adjustment ?? '0', scale),
  scale,
);
```
plus a test with a rounded-down original proving a full refund still proceeds. (Alternative: rule
that cash rounding stays disabled for the launch tenant and record that dependency — but the bound
would still be latent-wrong for anyone who enables it.)

Note the adjacent, PRE-EXISTING question this exposes (NOT introduced here, NOT in the 19, flagged
for a ticket only): a v4 refund pays out `Σ|line_total|` (the exact gross) while the customer
tendered the rounded total, so a rounded-down original is over-refunded by the adjustment.

### Minor

- **N-2 — `refundCheckoutStore.ts:954`** — finding 10 compares a MONEY value at `QUANTITY_SCALE`
  (`bcabs(discountAmount, QUANTITY_SCALE)` where `QUANTITY_SCALE = 4`, `:126`). Harmless for the
  zero-test it performs, but it is money at the quantity scale (rule 19 hygiene). Use the currency
  scale.
- **N-3 — `apps/pos/src/api/reportApi.ts:53`** — `XReportResponse.refunds_amount: string` is now a
  REQUIRED field, but the same type is what the SERVER path returns (`apiPost<XReportResponse>`,
  `:157`) and `apps/api/.../Presentation/Resources/XReportResource.php:35` emits `refunds_count`
  only, no `refunds_amount`. No consumer reads it on the server path today, so it is latent — but the
  type now lies. Either mark it optional or add it server-side.
- **N-4 — `refundCheckoutStore.ts:877-892` vs `:986`** — ordering shadows finding 11's
  `refund_event_appended` route: the cumulative-quantity cap runs BEFORE
  `createOrReuseActiveRefundIntent()`, so for a FULL-quantity refund that was already appended the
  cap fires first (`alreadyRefunded + thisAttempt > originalQuantity`) and the operator gets
  `refundQuantityExceeded` instead of `refundAlreadyAppended`, and
  `useRefundReconciliationStore.refresh()` is never called from this path. Partial refunds do reach
  the intended branch. The rollback loop the finding targeted is closed either way (both outcomes are
  clean refusals), so this is message/observability only — but the store test masks it by mocking
  `getCumulativeRefundedQuantityByOriginalLine` to an empty Map
  (`__tests__/refundCheckoutStore.test.ts:69`), so the interleaving is untested.
- **N-5 — `lib/offline/__tests__/refundReportingEndToEnd.test.ts:283-284`** — the §7.2a identity is
  asserted with `Number.parseFloat(...)+Number.parseFloat(...)`. Float on money, in the one test that
  exists to prove decimal correctness. Use `bcadd`/`bccomp`.
- **N-6 — `components/pos/RefundPayoutReconciliationModal.tsx:283-289` (and `:258-266`)** — an
  evidence-authoring failure (and the no-fiscal-event guard) `return` silently: `busy` clears and the
  modal simply does not advance. The operator taps "No / Not sure" and nothing visible happens, with
  no error surface. Add an inline error state.
- **N-7 — finding 15 has no test** — `resolveOriginalReceiptPrintRefs()`
  (`RefundPayoutReconciliationModal.tsx:111-141`) is never exercised: `getOfflineReceiptForPrint` is
  mocked and `attemptPrint` early-returns outside Tauri. The legacy-parity regression the finding
  names could silently return to `null/null`.
- **N-8 — `RefundPayoutReconciliationModal.tsx:214`** — `confirmRefundIntentPayout()` now THROWS
  (`InvalidRefundPayoutResolutionError`) where it previously no-oped; `handleConfirmPayout` has
  `try/finally` but no `catch`, so a 0-row resolve becomes an unhandled rejection.
- **N-9 — i18n copy** — `refundFlow.legacyRefundValueExceeded` ("already been refunded … for its full
  value") is also shown for the two fail-closed data cases (unreadable legacy record, unreadable
  ORIGINAL row) at `refundCheckoutStore.ts:922-927`. It asserts a fact the device does not know.
- **N-10 — `RefundReceiptV4Payload.ts:306-307`** — `assertOriginalRefundable()` requires EXACTLY one
  payment leg. An all-cash original settled as two cash legs is refused as "not cash". Fail-closed, so
  safe, but stricter than §9.6's intent.
- **N-11 — X vs Z asymmetry** — `generateLocalXReport()` counts only v4 `offline_receipts` refund
  rows, whereas `aggregateReportData()` also seeds from `local_refund_records`
  (`zReportService.ts:830-833`). Pre-existing for X (it hardcoded 0 before), not a regression, but the
  signed X and the signed Z now disagree on a legacy-refund shift.
- **N-12 — spec** — the §4.4 erratum documents finding 18's backstop but not finding 19's new
  receipt-level legacy VALUE bound, which is now a second device-side refusal with its own typed error
  and i18n key. One more sentence in the erratum would keep the binding document complete.

### Checked and clean (no finding)
- No new `onQueue(...)`; no horizon coverage change needed.
- No raw `toISOString()`/Carbon string bound into a SQLite TEXT time comparison anywhere in the diff;
  `reportApi.ts:416` uses `toSqliteUtc`.
- No device-side stock decrement, no parallel refund event type, no rename/restructure of an existing
  Event class; the refund remains `SALE_RECEIPT` + `invoice_type_code=REFUND`.
- Server additions touch no projection/queue path; `CompanyContext` is used only in request scope
  (`V4RefundAuthoringAcknowledgementController.php:55`); no no-arg currency-scale resolution added.
- Ruling conformance: SALE branch of `zReportService`/`endOfDayPreview`/`reportApi` left byte-intact;
  exactly one `Math.abs` remains (`RefundReceiptV4Payload.ts:379`), guarded by the `:450` alignment
  assertion; finding 10 resolved as pre-PIN refusal; deferred minors untouched except the ruled M-1/M-7.

---

## VERDICT

**PASS — conditional on N-1.**

19/19 findings ADDRESSED, 0 NOT ADDRESSED. No new Critical. One new Important (N-1) that fails in the
safe direction (refusal, never an over-payout) but blocks a legitimate first full refund whenever cash
rounding is active — a one-line fix using a column the same function already reads, plus a test.
Eleven Minors, none gating.
