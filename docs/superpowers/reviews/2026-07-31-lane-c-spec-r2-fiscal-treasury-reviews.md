# Lane C spec ROUND-2 review records — fiscal-pos + treasury

**Target:** spec revision 2 @ `7d42c463f` (feat/v3-refund-chain). Codex r2 recorded separately in
`2026-07-31-codex-refund-chain-spec-r2-review.md`. **All three r2 verdicts: REJECT** — but converged:
E1/rounding ruled CLOSED by both Claude reviewers; failure-mode + verifier-partition determination
VERIFIED correct; the residue is executability + money-path specifics.

## fiscal-pos (Opus) r2: REJECT — 4 Criticals
C-1 `FiscalEventEngine.ts` absent from the manifest (owns SALE_RECEIPT_PAYLOAD_KEYS_V3, the
unconditional assertExactKeySet at :1463, eventVersionFor call at :569 — a v4 payload is rejected by
the device's own validator; the manifest cannot produce a working refund). C-2 verifier remediation
UNSOUND — branching on the terminal's CURRENT fiscal_schema_version breaks every pre-cutover v2 row
on cutover terminals; fix = per-row sealed-algorithm discriminator (nullable column written by
finalization, backfilled by dual recomputation). C-3 DISCOUNT CONTRACT unspecified —
hydrateFromReceipt drops discount_amount; V1 line invariant throws LineArithmeticInvariantError on
any discounted-line refund; transaction_discount unruled. C-4 original_payment still a silent no-op
(treasury_payment_id never written by bridge). +8 Importants (guard §2.4 dangling refs/unnamed
helper/409-or-422 unresolved; reverse-lookup undesigned; FOR UPDATE test needs PG mode; dead-letter
surface no test/route/middleware; refund_destination enum 3-vs-2 contradiction; Z sign divergence;
rollout step-5 outage contradiction) +6 Minors (F-10 fixture collision; i18n paths wrong; etc).

## treasury (Opus) r2: REJECT — scorecard: 1 closed, 2 weakened, 1 misdiagnosed, 2 excluded-without-mechanism
CLOSED: pos_cash_rounding_refund reuse (v4 inherits the shipped bridge via >= gating; purpose enums;
partial unique index covers both types). CRITICALS: original_payment backlink still dead (only writer
is the retired ReceiptPaymentService; PaymentRefundService returns [] on empty); "byte-identical"
proration CONTRADICTS the actual service (opposite operation order, scale+10 intermediates, residual
to SMALLEST leg not largest; no ordinal→Payment mapping; device decimal.ts defaults scale 3 — rule 19);
calling PaymentRefundService DOUBLE-BOOKS vs the bridge's own leg-writes (+X POS and -X Refund rows,
one repo movement); **the spec's own unconditional v3 guard makes its legacy fallbacks UNREACHABLE —
on a v3 terminal there is NO refund path for v2 originals (the entire pre-cutover population),
voucher destinations, or ex-VOIDs**; store-voucher exclusion is enum-only — redeemVouchers is
unconditional at projector :408, so an original part-paid by voucher gets the voucher REDEEMED AGAIN
on refund; expected-cash fix targets DEAD CODE (buildExpectedPerMethod is only reachable via
generateZReport which throws for v3 — the rule-20 trap) while the real device path (refund_intents)
lacks shift_id/total/cash_impact columns. IMPORTANTS: rounding-adjustment sign convention unstated
(decides Income vs Expense account + Z netting); CashDrawerService still writes wrong
expected_cash/variance on v3 shift close; VOID prohibition has no fail-closed server rejection while
the manifest tells the registry to author v4 for VOID; retiring refundZAccounting leaves any
surviving legacy path with no device Z record; bridge's un-training-gated money legs named but not
ticketed; S4 still lacks a write-off/reconciliation artifact.
