# Lane C spec ROUND-3 review records — fiscal-pos + treasury (Codex r3 in sibling file)

**Target:** spec revision 3 @ `e93f9378c`. Verdicts: fiscal **APPROVE-WITH-FIXES** (conditional —
"no round-4 full review needed if C-1..C-4 + I-1..I-9 land verbatim"); treasury
**APPROVE-WITH-FIXES** (3 blocking money rulings, "bounded spec-text rulings, not a redesign");
Codex **REJECT** (7 items, 4 boundary choices — same underlying issues). Scope-narrowing ruled
GENUINELY EFFECTIVE by all three (voucher/original_payment/VOID unreachable; §16 faithful).

## Converged fold list (union, deduplicated)
1. V4 SIGN CONTRACT + discount arithmetic (fiscal C-1/C-2, treasury C-1, Codex 4.1): positive
   magnitudes everywhere, direction in invoice_type_code; normalization lives in
   RefundReceiptV4Payload.ts (NOT hydrateFromReceipt — cart UI depends on negatives); ⚖️ ORCHESTRATOR
   RULING: launch REFUSES any refund of a receipt with non-zero transaction_discount_amount (typed
   device refusal; whole-discount-receipt refunds → §16). Return-line object key set/types/enum frozen.
2. offline_receipts ruling (treasury C-2): decide insert-or-not; if inserted add a discriminator
   column in the same SQLite migration + aggregateReportData filter (refund rows → refunds buckets).
3. §12 cap query sign normalization + mixed legacy-population test (fiscal C-3).
4. Capability flag delivery (fiscal C-4, Codex 4.7): upsertTerminalState's regression guard REJECTS
   the whole upsert on v3 terminals (server current_sequence frozen vs growing device hash_sequence)
   → dedicated non-regressive setter mirroring setShiftNumberSeed; terminalStateRepository.ts + real
   dispatch seam (HomePage 'start-refund') in manifest; ⚖️ RULING: two-phase enable/acknowledge
   protocol (server guard conditioned on acknowledged device capability); enablement preflight
   refuses when >1 active DEVICE terminal.
5. Write-off compensation redesign (treasury C-3, Codex 4.4): ⚖️ RULING: server-observable evidence
   only (signed payload shift_id + operator attestation at write-off time — never device-local
   payout_confirmed_at); ONE idempotent compensation record per rejected event
   (source_type + source_id=fiscal_event_id + partial unique index per repo precedent); repository
   balance moves through TreasuryMovementServiceInterface in the same transaction; entry shape
   CLASS-DEPENDENT (invalid refund → Dr RefundWriteOff/Cr Cash; valid-but-unbooked → the reversal
   shape Dr Revenue[/SalesReturn]/Cr Cash); RefundWriteOff seeded in all 3 chart seeders + idempotent
   backfill; permission seeded; ingress-quarantine gets an addressable target; posting+atomicity stated.
6. Seal-discriminator backfill legality (Codex 4.5): ⚖️ RULING: migration amends
   prevent_receipt_modification() to allow exactly one NULL→value transition of
   sealed_hash_algorithm (all other columns immutable) + PG trigger regression test; §10.4 narrowed;
   Receipt/Terminal model casts in manifest; verifier NULL-handling gated on backfill completion
   (fiscal I-5: NULL = legacy_pipe_v1 until backfill-complete flag).
7. Intent index/lifecycle (fiscal I-4, Codex 4.3, treasury): exclude `synced` from the active set,
   drop nonexistent `applied`; cumulative-quantity idempotency for repeat identical partials =
   server §12 cap authority; payout-confirmed-but-unprinted startup recovery + reprint flow;
   payout_disputed_at money effect ruled (Z filter + server counterpart).
8. Non-retryable enactment (fiscal I-3, Codex 4.2): ApplyFiscalEventProjectionJob classification
   change + regression test IN the manifest; permission-derived alert class dropped or replaced
   with signed-evidence reads (no CompanyContext/team in queue workers).
9. Policy-evidence claim narrowed (Codex item 7): window/cap/manager/disposition = server-advisory
   for launch OR signed snapshot specified — pick one, stop claiming device proof without fields.
10. Manifest/citation sweep: registry test file EXISTS (extend; payload-less eventVersionFor
    behavior for the existing green assertion stated); pin migrations v65 + exact API migration
    filenames; SystemAccountPurpose = existing-modified; posOverrideAuthoring.test.ts = new;
    orphaned test files of deleted repos listed; capability call-site named; refusal i18n must NOT
    say "use the legacy path" (it 409s); D1-moved line cites (:120/:400/:465); ticket path =
    2026-07-31-treasury-bridge-training-money-legs.md; GoldenFixtureBuilder full path; VOID
    server rejection lives in the VALIDATOR (registry line = add 4 to SUPPORTED_VERSIONS only);
    voucher/VOID gate keyed on ReceiptType::Sale form; v3-REFUND payloads rejected outright
    (no legitimate producer exists); "always cash" tenant claim removed; §8 v2 fixture forces
    fiscal_schema_version=2 explicitly post-D1.

## Process ruling
Revision 4 = fold round. Verification = SCOPED passes (fiscal reviewer verifies C-1..C-4/I-1..I-9
landed verbatim; Codex confirms its 7 items), NOT a full fourth round — per the fiscal reviewer's
explicit condition and three rounds of convergence.
