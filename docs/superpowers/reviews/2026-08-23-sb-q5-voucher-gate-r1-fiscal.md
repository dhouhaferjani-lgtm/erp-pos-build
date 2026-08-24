# Gate record — Session B lane Q-5 (voucher void), fiscal-pos lens r1

Commit `94ec615bf`. **Verdict: ACCEPT (approved-with-conditions).** No fiscal-chain defect.

Remit answers: (1) sealed-payload question = **forward-only-safe** on two independent grounds:
only the cascade populates `receipt_id` and it fires only on already-sealed (voided) receipts;
and `voucher_ledger` is append-only so zero rows attached to an already-sealed receipt change
value — no recompute path can move. (2) No fiscal event consumes voucher voids;
absence-of-fiscal-event is pre-existing design, improved by this lane (ledger row + GL entry +
domain event where before there was nothing) — program ticket for a `VOUCHER_VOIDED`
virtual-admin event type (F-6). (3) POS void surfaces untouched; 410 tombstone intact; rule 12
satisfied; device push rejects voided events, sync DTO omits gl_journal_entry_id. (4) X/Z
reports do NOT aggregate Voided (and manual voids carry terminal_id null) — no report change;
GL correctly moves the liability now (that WAS finding #22); entry_date = occurred_at, no
backdating.

Micro-round additions (fold with treasury items): F-2 filename 140000→150000 (dup of treasury
F-5); F-3 cascade test must pin `voidedEntry->receipt_id === creditNote->id` (+terminal_id) —
receipt_id governs hash-payload membership; F-4 add `/vouchers/{id}/void` to
`config/support_access.php` `hard_block_path_patterns` (today blocked only indirectly via the
permission intersection). F-5 supplies the mechanism for treasury F-1: every redemption writes
`event=Redeemed` (partial included), so PartiallyRedeemed always trips the redemption guard —
effective voidable set = {Issued}; also proves the cascade hard-block still precedes the new
guard on that path.

Registered obligations: **F-1 [Important, inherited]** — Nf525 legacy verify arm lacks the
`is_voided=false` filter `ReceiptHashService:601` applies ⇒ a voided v3 credit note with
post-seal cascade rows recomputes as a FALSE chain break; unreachable today (ES-21: no
production `ReceiptVoided` emitter) — PINNED obligation on any lane that re-arms a
ReceiptVoided emitter: align the filters or exclude event='voided' rows.
Lens ran `VoucherCascadeServiceTest` 9/26 green (the one file the treasury set omitted).
