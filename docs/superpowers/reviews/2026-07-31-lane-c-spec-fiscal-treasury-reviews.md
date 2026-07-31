# Lane C spec review records — fiscal-pos + treasury (round 1)

**Target:** `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md` @ `e294a2df6`
(branch `feat/v3-refund-chain`). Third reviewer (Codex) recorded separately in
`2026-07-31-codex-refund-chain-spec-review.md`. **All three verdicts: REJECT.**

## fiscal-pos-reviewer (Opus): REJECT
Criticals: (1) spec anchored on the WRONG builder — production authors via
`buildSaleReceiptV3Payload` (receiptService.ts:357), not `SaleReceiptPayload.ts`; a mirrored 28-key
payload is rejected by `assertExactKeySet` V3; (2) mixed-tender per-leg rounding UNIMPLEMENTABLE —
bind 2c requires `total` to be an exact multiple of the denomination whenever adjustment≠0 → refund
rounding must be CASH-ONLY-payout gated (mirror `isCashOnlyTender`) or a new payload design;
(3) rounding keys are MANDATORY on v3 (canonical zero when absent), never omitted; (4) §1.2(a)
symptom INVERTED — `verifyLegacyArm` recomputes the legacy pipe-digest while v3 seals with
`V3ReceiptHashComputer` → `pos:verify-chains` + NF525 endpoint go permanently RED after first legacy
return (not a silent blind spot) — and no remediation for already-created orphan rows is specified;
(5) counter derivation wrong — creation paths set `current_sequence=1`, PG CHECK forbids
chain_sequence 0 (23514 not 23505), both PG-only → SQLite test cannot reproduce; (6) negative-qty
refund cart vs strictly non-negative payload contract never reconciled (cartClassification.ts:4-5
explicitly prohibits exactly this); (7) S4 dead-letter surface + §3c void-fallback control PUNTED
out of manifest — the dodge Lane C's contract forbids.
Important: no v3 REFUND/VOID golden vector (F-09 is 27-key pre-v3); state machine missing key-rotation,
Z-boundary-crossed, local-refund-receipt-row states; S5 backstop rests on a reverse lookup
(refunds-by-original) that has no designed path; no CompanyContext/precision contract for the new
projector check; no v2-non-regression test for the §2.4 guard; E1 batch boundary inconsistent.
Credit: ~25 citations exact; §3e projector-gap finding confirmed; two-tier offline policy defensible;
"no new event type" ruling right; next-sale-chains-off-refund test assertion is the strongest part.

## treasury-reviewer (Opus): REJECT
Criticals: (1) refund-rounding GL claimed absent but LANDED as `pos_cash_rounding_refund`
(TreasuryReceiptBridge.php:431, GeneralLedgerService.php:3508-3514 throws on unknown source types,
partial unique idempotency index in 2026_07_28_100200) — implementing `pos_refund_rounding` either
throws on every rounded refund or double-post-exposes; (2) store-voucher refunds UNREPRESENTABLE —
no issuer call in projector/bridge, `redeemVouchers` not isRefund-gated (BURNS a voucher),
`writePayment` throws InstrumentRequiredException, no refund_destination payload field;
(3) `original_payment` destination is a SILENT NO-OP on v3 (projector hardcodes
treasury_payment_id=null; bridge never populates it → PaymentRefundService prorates nothing,
returns []; live today via legacy /return on v3 receipts) and drops amount cap / original_payment_id
linkage / partial unique index / instrument-settled assertion; (4) v3 VOID double-counts cash+sales
(projector writes is_voided=false, never updates original; buildExpectedPerMethod has no
receipt-type filter); (5) v3 cash refund INFLATES server expected cash (CHECK amount>0 + no type
filter → refund X shows as +X → 2X false shortage feeding the variance severity ladder);
(6) CashDrawerService claim false — calculateExpectedCash runs unconditionally for v3 and its REFUND
ops come only from the legacy paths §2.4 gates off.
Important: S4 = cash-out-no-books with remediation deferred; v2/v3 refund `total` sign flips on the
same server aggregation (perpetual grand total moves opposite directions); retiring
refundZAccounting.ts orphans 4 readers incl. cash_impact expected-cash feed; S5 backstop cannot
prevent double payout and is quantity-only; training-original refunds structurally unrepresentable +
bridge doesn't training-gate money legs (must be refused device-side).
Minor: resolveOriginalReceiptId not company-scoped; state purpose enums not PCG numbers; AVOIR
rounding line must be a hard prerequisite. Confirmed correct: cash-destination server half IS landed
and tested (bridge + PosRefundReceiptBridgeTest).
