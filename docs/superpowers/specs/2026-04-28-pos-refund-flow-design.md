# POS Refund Flow — Design Spec (Phase 1 — NF525 Standard Retail)

> **Date:** 2026-04-28 (revised after Codex review)
> **Priority:** High — completes a daily-operational gap for retail
> **Scope:** Backend (apps/api), POS Desktop (apps/pos), Back-office (apps/web)
> **Out of scope (Phase 2):** Automotive-specific flows (cores/`consigne`, eco-tax pro-rata, warranty redo, installed-parts policy). Captured at end of doc for context only.
> **Out of scope (separate session):** EU e-invoicing / Peppol / UBL CreditNote export.
> **Coordination:** See `docs/sessions/2026-04-28-refund-vs-deferred-coordination.md`. **H3 prerequisite is satisfied** — the `Nf525DataProviderContract` and its DTOs landed on `dev` on 2026-04-28 with all extension points refund-flow needs already reserved as nullable fields.

---

## 0. Why this exists

The repo already has solid foundations for POS returns: hash-chained return receipts (`ReceiptReturnService`), partial/full line-level returns with cumulative tracking, stock restoration, cash-drawer accounting, payment refund service, mature discount/coupon/promotion system. What's missing prevents a retail store from running daily refund operations confidently:

- Receipts can't be retrieved by scanning — only typed lookup
- No exchange flow (refund + new sale on one customer-facing transaction)
- No voucher / store-credit tender (cashier can't say "your refund is €40 store credit")
- Multi-tender refunds don't prorate
- No return-window enforcement, no manager override, no per-tenant policy
- No customer-history search at the POS for cashiers

This spec closes those gaps with NF525 fiscal integrity preserved end-to-end.

## 0.1 Codex reviews (2026-04-28) — what changed

### First review

H3 (Compliance Rule #6 cleanup) merged on `dev` ahead of refund flow. The H3 owner reserved nullable extension points on the new DTOs that match this spec exactly: `Nf525ReceiptData.exchangeGroupId` (§3.4), `Nf525ReceiptData.{authorizedByUserId, overrideReason, outOfWindow}` (§3.6), `Nf525ReceiptData.voucherLedgerEntries` (§3.1), `Nf525ReceiptPaymentData.{instrumentType, instrumentSerial}` (§5.1), `Nf525ZReportData.reportData` JSONB pass-through versioned by `schema_version` (§5.3). Refund flow **populates** these reserved fields in the POS-side provider; **the contract itself does not change**.

Codex flagged 5 blockers, 14 majors, and several minors. Material changes folded into this revision:

### Second review (after H3 landed)

Saved at `docs/superpowers/reviews/2026-04-28-pos-refund-flow-codex-review-2.md`. Verdict: ship-with-changes. Material changes folded into this second revision:

- **§4.1 receipt-finalization rewrite no longer claims hash parity.** Hash inputs change in v3, period. v1/v2 receipts keep the empty-payment-array hash format and the legacy verifier still passes; v3 receipts use a new payload that includes payments / voucher ledger entries / `exchange_group_id`. v3 cutover is **per-terminal at the next Z-close** — not mid-shift. Migration semantics for `pending_seal` (nullable `fiscal_hash` and `chain_sequence` until finalization, immutability trigger update for the single pending→fiscalized transition) are explicit. (Codex review 2 finding A.)
- **Offline path is in scope.** `apps/pos/src/lib/fiscal/hashService.ts` (Tauri client) and `ReceiptSyncService` are added to the rewrite. Both PHP and TypeScript implementations must produce byte-identical hashes for v3, with golden test vectors. (C.)
- **Receipt hash v3 canonical payload is formally specified** (new §5.0). Field order, decimal scale per currency, null sentinel, deterministic ordering of payment / voucher / line collections, UUID format, timestamp timezone — locked before service code is written. (D.)
- **Voucher GL accounting matrix is explicit** for all seven ledger events (§5.2). Voucher redemption does **not** route through `GeneralLedgerService::createPOSPaymentEntry` — that path debits cash and credits revenue, which would double-count revenue when paired with a voucher liability debit. A new `createVoucherRedemptionEntry` posts: debit voucher liability, credit POS tender clearing (or revenue/VAT split depending on Phase-1 chart). (E.)
- **Credit-note void with redeemed vouchers is hard-blocked in Phase 1** with an explicit "manual accounting required" message and a link to the operator runbook. The compensating fiscal-correction workflow is deferred to Phase 1.1; not invented here. (F.)
- **QR token format is fully specified** (§4.5): `v:kid:receipt_uuid:mac`, MAC over canonical JSON `{tenant_id, company_id, receipt_uuid, purpose: "receipt_lookup", issued_at, kid}`. Verifier selects keys only from `Terminal.tenant_id`, fetches by `(receipt_uuid, tenant_id, company_id)`, constant-time MAC comparison, generic error response identical for "wrong tenant / tampered / not found". Two active keys per tenant for rotation; old tokens valid until the previous key's `kid` is retired. (G.)
- **Layered voucher rate limiting** (§4.7 expansion): per-terminal-per-day, per-cashier-per-day, per-tenant-per-hour for failed lookups, per-IP/API-token, per-code-prefix, per-voucher failed attempts. POS lookup endpoint returns generic "code invalid" until validation passes; balance is revealed only inside an active payment session, not from a casual scan. (H.)
- **Goodwill voucher controls hardened** (§3.5/§7.1): required customer over `goodwill_named_customer_threshold`; no issuer-as-recipient (server-side guard); no issuer-redeems-issued (cross-check at redemption time); daily issuance cap per user; mandatory reason codes; four-eyes approval above `goodwill_four_eyes_threshold`; bearer goodwill **default off** at the tenant level. (I.)
- **Z-report v3 cutover is per-terminal at next Z-close** (§5.4): the `schema_version` flip is gated on "no open shift, no un-Z-reported receipts on this terminal." Mid-shift mixed-schema reporting is explicitly unsupported. (J.)
- **Voucher residual policy is explicit** (§5.5): voucher ledger stores **internal precision = currency_scale + 2**; display/redeem at currency scale; on final redemption, sub-minor balance triggers `RoundingAdjustment` ledger event written to a tenant-configured rounding account. No silent dust. (K.)
- **NF525 certification gate** (new §10.1): refund-flow v3 is **off by default for France tenants** behind a feature flag `pos_refund_v3_france_enabled`. The flag stays off until the editor attestation / certificate is updated to cover the new hash payload + JET fields. Tenant-level enablement; non-France tenants default-on. Software-version bump and JET fixture re-validation are plan tasks. (L.)
- **Customer-history defaults tightened** (§3.5/§2.6): 15 searches/day per cashier, 3 rejected-specificity/hour, 8 same-partner/day, immediate alert on any cross-company search. (M.)
- **Eco-tax claim narrowed** (§3.7): Phase 1 ships the columns + DTOs; **writer integration is Phase 2**, no "lossless data" promise that would force `ReceiptCreationService` whitelist changes today. (N.)
- **`PaymentRefundService` payment_type fix** (§4.3): proration extension also fixes the existing latent bug where refund payments are written without `payment_type`, defaulting to `document_payment`. New negative payments carry `payment_type = Refund` explicitly. (Additional finding.)

The full first-review change set:

- **Fiscal sealing flow rewrite** (A1, A2, additional finding): receipt creation currently seals `payment_methods_hash` over an empty payment array (`ReceiptCreationService.php:478`) and writes payment rows after sealing (`ReceiptPaymentService.php:190`). Voucher tender requires payment capture inside the sealed payload, including instrument identifier (voucher code), not just type+amount. See §5.1.
- **Voucher liability is GL-grade, not just a payments row** (A1): voucher issuance creates an immutable voucher ledger entry **and** a GL liability entry, both committed before the credit note seals. `pos_receipt_payments.amount > 0` constraint forbids using the payments table for issuance. See §5.2.
- **PaymentType enum is NOT extended** (E16): voucher routes through `PaymentMethod` (with a dedicated voucher ledger), not `Treasury\PaymentType`. This avoids breaking `Payment::scopeIncoming/Outgoing` and the exhaustive matches in `PaymentType.php:27`.
- **`voucher_serial` is renamed**, not repurposed (E18): becomes `instrument_serial` with a `instrument_type` discriminator. Restaurant meal vouchers and store-credit vouchers no longer collide.
- **Settings reuse, not parallel storage** (E20): `Company.reservation_settings` already holds `customer_return_expiry_days` and `high_value_alert_threshold` (`CompanyController.php:221`). Refund policy fields extend that JSON blob; no new `pos_refund_settings` table.
- **`exchange_group_id` is committed cryptographically** (B8): added to the receipt's hash payload, not metadata-only.
- **Z-report v3 is not purely additive** (A4): `ReportGenerationService.php:842` increments `sales_count` indiscriminately and leaves `refunds_count`/`refunds_amount` at zero. Sale-vs-return aggregation must be **split**, plus v3 keys normalized in `ZReportHashService`. v2/v3 chain transition needs a golden replay test.
- **Currency rounding rules are explicit** (F23): use `CurrencyScaleResolver`; allocate residual to last redemption/allocation line; TND scale-3 vs EUR scale-2 test-covered.
- **Voucher-to-cash refund is forbidden by default** (F21).
- **Source credit note void cascades explicitly** (F24): voiding a credit note voids any unused voucher; blocks if redeemed.
- **Exchange idempotency** (F28): unique `exchange_request_id` over both fiscal docs and voucher issuance; retry returns the same triple.
- **Stock locking through both halves of an exchange** (F26): same-transaction inventory consistency required.
- **Audit trail propagates beyond return receipt** (F27): manager-override fields on voucher issuance, redemption, and refund-payment-allocation rows too.
- **Customer-history scope is company-aware** (F25): defaults to current company; cross-company lookup is a separate permission.
- **Voucher stacking is explicit** (D14): multiple vouchers per sale, per-voucher confirmed amount, no duplicate redemption in one transaction.
- **Manager override = max(flat_amount, percent_of_original)** (C10), with manager-override-to-extend on daily caps (C11).
- **Customer-history privacy hardening** (C9): rate limits, minimum search specificity, audit logs, masked totals by default, alert on broad/repeated searches.
- **Phase-2 forward compatibility** (F30): per-line eco-tax fields specified now; cores are NOT shoehorned into the voucher entity (F29).

## 0.2 Offline-first principle — local SQLite is the default, sync is background-only

The POS desktop is offline-first **as a default**, not as a fallback. Every customer-facing operation — open a sale, complete a sale, look up a receipt, process a return, issue a voucher, redeem a voucher, search customer history — reads from and writes to the **local SQLite database first**, every time, regardless of whether the internet is available.

When connectivity is available, an **asynchronous background sync** pushes local writes to the server and pulls server-side updates into the local SQLite. **No customer-facing operation ever hits an API directly** in Phase 1. Lookups are local; the API is only for the background sync pipeline.

When connectivity is unavailable, behavior is unchanged: the terminal continues to operate against local SQLite. On reconnect, the sync pipeline catches up.

- **Sales**: complete locally, queue for sync. Existing behavior.
- **Receipt lookup for refund**: scoped to **this terminal's** receipts only in Phase 1. Tomorrow's spec (Phase 1.5+) extends this to "all terminals in this shop" via LAN gossip sync. The customer-facing implication: a customer who buys at terminal A in shop X cannot return at terminal B in shop X while both are offline. This is acceptable for our first clients (small shops with one POS terminal).
- **Customer history search**: scoped to **this terminal's** receipts only in Phase 1. Same Phase 1.5+ path.
- **Voucher issuance and redemption**: scoped to **this terminal's** voucher set in Phase 1. A voucher issued at terminal A is redeemable at terminal A only. Phase 1.5+ extends to LAN-gossip across terminals in a shop.
- **Manager PIN for override**: cached per terminal in the local user/permission projection (already standard); resolves locally.
- **Settings (return window, override threshold, voucher policy)**: pulled into the local SQLite at session-open and refreshed on connectivity.

For our first clients (small shops with one terminal), single-terminal scope is the entire reality, so the "offline degradation" is invisible. As we expand to multi-terminal shops, Phase 1.5 adds shop-scope gossip; Phase 2 adds company-scope sync (with role-based access controls).

## 1. Guiding principles

1. **One UX, two fiscal documents.** Cashier sees one screen for an exchange; system writes a credit note + a new sale receipt, both hash-chained, joined by `exchange_group_id` (and the link is committed in the hash payload).
2. **Voucher as a non-taxable liability + tender at redemption (MPV-by-default).** Per EU Directive 2016/1065 (FR: BOI-TVA-CHAMP-10-10-40-50; IT: D.Lgs. 141/2018; UK: VATA 1994 Sch. 10B; TN: Code de la TVA substance-of-supply), a refund-issued voucher is **not a taxable event at issuance** — the original sale's VAT is already reversed via the credit note. At redemption the voucher is a tender against the new VAT-priced sale; the sale carries its own VAT normally. Voucher is a `PaymentMethod` (extensible per tenant) plus its own non-taxable liability ledger. **No VAT logic at the voucher layer.** A `voucher_kind` column with default `MPV` is added for the rare future case where a tenant explicitly issues single-purpose prepaid coupons (e.g. "10 EUR off coffee only").
3. **Policy lives in `Company.reservation_settings`.** Return window, manager-override threshold, allowed refund destinations, customer-history visibility, voucher defaults — all extend the existing settings home; no parallel storage.
4. **Permissions, not roles.** New Spatie permissions assigned to default roles in the seeder; tenants can re-assign.
5. **Fiscal chain is law, including instrument identifiers.** Sale, refund, voucher issuance, voucher redemption, exchange linkage — every customer-facing money movement and its instrument code is sealed in the chain. Originals immutable. Payment rows immutable post-seal.
6. **Cashier ergonomics.** Scan a QR, pick lines, choose destination, confirm. Manager PIN only when policy thresholds are hit. Mid-flow interruptions resume from a draft session.

## 2. End-to-end user flows

### 2.1 Refund-only, with original receipt

1. Cashier opens "Returns" on the POS.
2. Cashier **scans the QR on the customer's printed receipt** (or types the ticket number).
3. System retrieves the receipt, validates it's within the configured return window. If outside, see §2.5.
4. Cashier picks lines + quantities. "Max returnable" already accounts for prior partial returns.
5. Cashier picks a **return reason** (existing `ReturnReason` enum).
6. Cashier picks a **refund destination**: `OriginalPayment`, `Cash`, or `StoreVoucher`. Allowed destinations come from policy (resolver, not UI filter — see §3.4).
7. If refund amount exceeds `effective_manager_override_threshold` (= `max(flat_amount, percent_of_original × original_total)`), an inline manager-PIN prompt appears.
8. System opens an unsealed `RefundDraft` (idempotent on `refund_request_id`), then on confirm: in one DB transaction creates the return receipt (negative totals, NF525-chained, payment rows + voucher issuance ledger sealed in the hash payload), restores stock, records cash-drawer op (cash component only), reverses original payment via proration, and/or issues a voucher with a fresh code.
9. A return receipt prints. If a voucher was issued, the voucher code (and QR) prints on the same paper or a separate voucher receipt, configurable by printer profile.

### 2.2 Exchange (refund + new sale, one transaction)

1. Cashier scans receipt → picks lines to return.
2. Taps **"Exchange"**. Cart now has returned lines as negative items, and `exchange_request_id` is reserved.
3. Cashier scans new product(s) — they enter the same cart as positive lines.
4. Cart shows: subtotal of returns (negative), subtotal of new sale (positive), **net amount due**.
5. If net positive: cashier collects payment.
6. If net negative: cashier picks refund destination for the surplus (cash / original tender / voucher), subject to policy.
7. On confirm, in a single DB transaction:
   - Inventory is locked, net stock movement computed, both halves' stock movements written.
   - Two fiscal documents are sealed in order: return receipt first, then sale receipt. Each chains to the terminal's `last_hash` in sequence. **Both hash payloads include `exchange_group_id`** (so the link is cryptographically committed). Return receipt's payload also includes the voucher issuance ledger entry id (if surplus → voucher).
   - Net payment is settled (charge or refund-to-destination), all immutable post-seal.
8. Customer-facing printed ticket renders both halves on one page with the net at the bottom.

If the cashier closes the app or the session times out mid-flow, the `RefundDraft` is recoverable on next open by the same cashier; resume or discard. Drafts expire after 30 minutes by default.

### 2.3 Voucher issuance

Triggered from §2.1 (refund destination = `StoreVoucher`), §2.2 (surplus → voucher), or by an authorized back-office user (admin issues a goodwill voucher).

A `Voucher` entity is created with: `code` (unique scannable token), `initial_balance`, `current_balance`, `currency`, `issued_at`, `expires_at` (from tenant default, owner-overridable), `status = Issued`, `issued_by_user_id`, `issued_at_terminal_id` (nullable for back-office), `source_receipt_id` (the credit note that birthed it; nullable for goodwill), `partner_id` (nullable; set when the cashier identifies the customer at issuance), `redemption_mode` (`Bearer | CustomerBound`), `issued_to_partner_id` (nullable; the **identified-at-issuance** partner, distinct from `partner_id` which is the **current holder** for transferable cases), `notes`, `authorized_by_user_id` (nullable), `override_reason` (nullable), `policy_trigger` (nullable string, e.g., `out_of_window`).

In the same transaction, an immutable `VoucherLedger` row is written with `voucher_id`, `event = Issued`, `amount = +initial_balance`, `terminal_id`, `user_id`, and a `gl_journal_entry_id` referencing the GL liability credit (debit refund clearing, credit voucher liability) per the tenant's chart of accounts. The credit note's hash payload includes `voucher_id`, `voucher_code`, and `voucher_amount` — sealed before insert.

### 2.4 Voucher redemption (used as tender at sale time) — Phase 1 single-terminal

**Phase 1 voucher domain is single-terminal.** A voucher issued at terminal A is redeemable only at terminal A. The voucher row carries `redeemable_at_terminal_id = issued_at_terminal_id` and the redemption service rejects any attempt to redeem at another terminal with `VoucherNotForThisTerminalException`. This matches our offline-first stance and avoids the "what if both terminals are offline" cross-terminal redemption complexity.

Phase 1.5+ extends the redeemable scope to all terminals in a shop (LAN-gossip sync of the voucher set). Phase 2+ extends further to company-wide (central server reconciliation).

1. Cart is built normally on this terminal.
2. At payment, cashier picks **Voucher** as a tender, scans/types the voucher code.
3. System validates against the local SQLite voucher set: code exists on this terminal, status ∈ {`Issued`, `PartiallyRedeemed`}, not expired, `current_balance > 0`, `redemption_mode = Bearer` OR (`CustomerBound` AND current cart's customer matches `issued_to_partner_id`). Rate-limited per terminal-per-day to deter brute force.
4. Cashier confirms the amount to apply (default = `min(remaining_due, current_balance)`).
5. **Voucher stacking:** multiple vouchers can be applied to one sale up to the remaining due. Each must be confirmed individually. The same voucher code cannot be applied twice in one transaction (server-side guard on a redemption-set).
6. Each redemption creates: (a) one tender row on the sale receipt with `payment_method.code = 'voucher'`, `instrument_type = 'store_voucher'`, `instrument_serial = <voucher_code>`; (b) one immutable `VoucherLedger` row with `event = Redeemed`, `amount = −applied_amount`, `receipt_id`, `terminal_id`, `user_id`, `gl_journal_entry_id` (debit voucher liability, credit revenue/cash-clearing).
7. The voucher's `current_balance` and `status` update via the ledger projection. The receipt's hash payload includes the redemption tender rows with their `instrument_serial` so the chain commits the voucher code, not just type+amount (additional finding).

### 2.5 Out-of-window refunds

Policy field `out_of_window_policy` (default `VoucherOnly`):

- `Refuse`: cashier blocked; manager can authorize via PIN (logged on the credit note + on the voucher ledger).
- `VoucherOnly`: refund destination is forced to `StoreVoucher`; no cash, no card reversal; no manager prompt needed.

The destination resolver enforces this server-side; UI filtering is presentation only and cannot be bypassed by direct API call.

### 2.6 Customer-history lookup (no receipt) — privacy-hardened, single-terminal in Phase 1

**Phase 1 scope:** customer-history search returns receipts created on **this terminal only**. This matches our offline-first stance and the reality of our first clients (small shops with one terminal). Phase 1.5+ will extend the scope to all terminals in a shop via LAN-gossip sync; Phase 2 to company-wide via central server, with role-based access controls.

Two-tier permission:

- **`pos.search_customer_recent_purchases`** — see purchases within `customer_history_window_days` (default = `customer_return_expiry_days` from existing settings). For everyday cashiers.
- **`pos.search_customer_full_history`** — unlimited window. For managers/owners.

Privacy hardening (C9):

- **Minimum search specificity:** at least one of {full phone, full email, loyalty card scan, partner-id-scanned-from-loyalty-QR}; partial name alone is rejected.
- **Rate limit:** N searches per cashier per day (configurable; default 30); soft-block beyond with a manager-PIN escalation.
- **Audit log:** every search row written to `customer_history_searches` (cashier_id, terminal_id, search_terms_hash, result_count, timestamp). Reviewable by tenant owners.
- **Masked totals by default:** the result list shows date, terminal, line count, and last-4 of receipt number — not totals — until the cashier picks a receipt to act on. Totals reveal on click.
- **Alerting:** `BroadCustomerSearchAlert` event emitted if a cashier does more than M searches in a window or if the same cashier searches the same partner repeatedly (configurable; default M=10/hour and 5 same-partner/hour).
- **Terminal scope (Phase 1):** results are scoped to **this terminal's** receipts only. No cross-terminal queries. Cross-shop and cross-company are explicitly out of Phase 1 scope. Per Phase 1.5+ roadmap, these expand with additional permissions (`pos.search_customer_cross_terminal_in_shop`, `pos.search_customer_cross_company`).

### 2.7 Mixed-tender refund proration

When the original sale has multiple payments (e.g., €60 card + €40 cash) and the refund is partial:

- Default: prorate across original payments by their proportional share.
- Owner can switch the policy to `LargestFirst` or `CashierChoice` (the latter requires `pos.refund_destination_override`).
- `PaymentRefundService::refundReceiptPayments(Receipt $original, BCAmount $totalToRefund, ProrationStrategy $strategy)` writes one negative `Payment` per touched original (idempotent on `refund_request_id`), each carrying `original_payment_id` and `authorized_by_user_id` if a manager override fired.

## 3. Domain model changes

### 3.1 New module: `Voucher` — unified across sources (refund / exchange / goodwill / loyalty-credit / gift-card / promotional)

**Architectural decision** (audit-driven): no voucher primitive exists today. The closest is `RewardType::Credit` in the Loyalty module, which calculates a monetary value but has no path to becoming a payment-row tender. We build a single new `Voucher` module that becomes the **canonical redeemable-instrument layer**, and unify all sources behind it via a `source` discriminator. The UX surfaces them coherently (one "Vouchers & Credits" page in the back-office, source-filterable; one voucher-tender entry at the POS regardless of how the voucher was born). The accounting differs by source per the §5.2 GL matrix, but the data model is one entity.

Hexagonal layout under `apps/api/app/Modules/Voucher/`:

- `Domain/Voucher.php` — entity. Fields: `code`, `initial_balance`, `current_balance`, `currency`, `status` (enum), `redemption_mode` (`Bearer | CustomerBound`), `voucher_kind` (`MPV | SPV`, default `MPV`; SPV reserved for future, Phase 1 issuance refuses SPV), **`source` (`Refund | ExchangeSurplus | Goodwill | LoyaltyCredit | GiftCardPurchase | Promotional`)**, `issued_at`, `expires_at`, `partner_id` (nullable, current holder), `issued_to_partner_id` (nullable, identified-at-issuance), `source_receipt_id` (nullable), `source_loyalty_transaction_id` (nullable; set when source = LoyaltyCredit), `source_promotional_campaign_id` (nullable; reserved for Promotional source, Phase 2+), `issued_by_user_id`, `issued_at_terminal_id` (the terminal that issued — **Phase 1 voucher domain is single-terminal**: `redeemable_at_terminal_id` defaults equal to `issued_at_terminal_id`), `notes`, `authorized_by_user_id` (nullable), `override_reason` (nullable), `policy_trigger` (nullable). Tenant- and company-scoped. Immutable except via ledger projection.
- `Domain/VoucherLedger.php` — append-only ledger. Fields: `voucher_id`, `event` (`Issued | Redeemed | PartiallyRedeemed | Expired | Voided | Transferred | Reversed`), `amount` (signed), `receipt_id` (nullable), `terminal_id` (nullable), `user_id`, `gl_journal_entry_id`, `authorized_by_user_id` (nullable), `policy_trigger` (nullable), `occurred_at`. The ledger is the source of truth; `Voucher.current_balance` and `Voucher.status` are projections.
- `Domain/Enums/VoucherStatus.php` — `Issued`, `PartiallyRedeemed`, `FullyRedeemed`, `Expired`, `Voided`.
- `Domain/Enums/VoucherEvent.php` — ledger event types as above.
- `Domain/Enums/RedemptionMode.php` — `Bearer`, `CustomerBound`.
- `Domain/Services/VoucherCodeGenerator.php` — strategy: tenant-prefixed token + 12 alphanumeric (no `I/O/0/1`) + check digit. Configurable.
- `Application/Services/VoucherIssuanceService.php` — issuance entry points keyed by source: `issueFromRefund()`, `issueFromExchangeSurplus()`, `issueGoodwill()`. **Phase 1.5 wires `issueFromLoyaltyCredit()`** (called by the Loyalty module when a member redeems a `RewardType::Credit` reward; today the loyalty redemption produces only a `loyalty_transactions` row with no payment-row counterpart, audit confirmed). **Phase 2+ wires `issueFromGiftCardPurchase()` and `issueFromPromotionalCampaign()`**. Each entry point in a transaction: write `Voucher` + first `VoucherLedger` row + GL journal entry; emit `VoucherIssued` event. Returns voucher with code so the caller can include it in the receipt hash payload. **All issuance paths produce vouchers redeemable through the same `VoucherRedemptionService::redeem()`** — the redemption surface is uniform across sources.
- `Application/Services/VoucherRedemptionService.php` — `lookup(string $code, Terminal)`, `redeem(Voucher, BCAmount, Receipt, User, Terminal)`, `validate(...)` — exception-based: `VoucherExpiredException`, `VoucherInsufficientBalanceException`, `VoucherInvalidStatusException`, `VoucherNotForThisCustomerException`, `VoucherDuplicateInTransactionException`, `VoucherRateLimitedException`. Hooks rate limiter.
- `Application/Services/VoucherCascadeService.php` — `onCreditNoteVoided(Receipt $creditNote)`: if no redemptions exist for vouchers issued from this credit note, void those vouchers (write ledger `Voided` + reverse GL); if any redemption exists, **hard-block in Phase 1** with a `VoucherCascadeBlockedException` whose message points the operator to the manual-accounting runbook at `docs/runbooks/voucher-redeemed-credit-note-correction.md` (created during plan execution). Phase 1.1 will define the in-system compensating fiscal-correction workflow (corrective sale/debit note + voucher ledger `Reversed` rows + GL reversals + JET export representation). Wired via a listener on the credit-note void event. (Codex review 2 finding F.)
- `Domain/Events/VoucherIssued.php`, `VoucherPartiallyRedeemed.php`, `VoucherFullyRedeemed.php`, `VoucherExpired.php`, `VoucherVoided.php`, `VoucherTransferred.php`, `VoucherReversed.php` — event-sourced audit consistent with rest of codebase.
- `Presentation/Controllers/VoucherController.php` — back-office CRUD + lookup + void + (privileged) extend-expiry + (privileged) transfer.
- `Presentation/routes.php` — `GET /vouchers`, `GET /vouchers/{code}`, `POST /vouchers/issue-goodwill`, `POST /vouchers/{id}/void`, `POST /vouchers/{id}/transfer`, `GET /pos/vouchers/lookup?code=...` (POS-scoped, returns balance + status only).

**Why a separate module, not a Coupon subtype:** coupon = discount-delivery (its "use" produces a discount on a receipt). Voucher = tender (its "use" pays for the receipt and hits a liability account). Conflating them tangles the discount engine with payment processing. A shared "redeemable code validator" can be extracted later if useful.

**Why one entity for bearer + customer-bound:** simpler ops; redemption rules differentiate via `redemption_mode`. Audit fields capture intended-recipient for fraud reporting (`issued_to_partner_id`).

### 3.2.1 Store voucher (this spec) vs restaurant voucher (Phase 2 — distinct tender)

These are **two different financial instruments** and must be modeled as **two separate `PaymentMethod` rows** with two separate POS tender buttons. The user confirmed (2026-04-28) that the existing `voucher_serial` field on `pos_receipt_payments` was reserved for restaurant tickets (Sodexo, Up Déjeuner, Edenred, Pluxee, Restaurant Pass — common in France/Tunisia/EU). They are not the same thing as our refund/store credit and must not be conflated.

| | Restaurant ticket (Phase 2 — reserved) | Store voucher (this spec — Phase 1) |
|---|---|---|
| Issued by | Third-party (Sodexo, Up, Edenred, Pluxee, Restaurant Pass) | Our merchant (us) |
| Funded by | Employer + employee | Customer's prior purchase (refund) or our goodwill |
| Liability sits on | Issuer's books | Our books (EU Directive 2016/1065 MPV) |
| Settlement | We batch + submit to issuer; they reimburse minus 3–5% fees | None — internal GL net |
| Redeemable for | Food only (FR Code du travail Art. R3262 + per-country rules) | Anything we sell (or per policy) |
| Daily caps | Yes (e.g. €25/day FR 2025) | None |
| Change/cash-back | No | Per policy |
| VAT | Cash-equivalent at the POS; no extra VAT logic for us | Non-taxable liability + tender (§5.2) |
| Has a balance? | Modern card-based: yes; legacy paper: face-value single-use | Yes, internal precision = scale + 2 |

**Phase 1 wires the store_voucher tender end-to-end.** The `instrument_type` discriminator on `pos_receipt_payments` (§3.3) accepts `restaurant_voucher` as a valid value (so the existing decorative `voucher_serial` data backfills correctly), but Phase 1 issuance/redemption services reject `instrument_type = 'restaurant_voucher'` with a clear `RestaurantVoucherNotYetSupportedException` pointing to a Phase 2 backlog item. Phase 2 will:

- Add a `RestaurantTicketTenderService` with a per-issuer settlement-provider abstraction (one provider per: Sodexo, Up, Edenred, Pluxee, Restaurant Pass, etc.)
- Per-country compliance: FR Code du travail Art. R3262 daily caps, Tunisia local rules, EU equivalents
- Settlement batch + fees ledger
- GL direction: `Dr Cash-equivalent clearing → Cr Sales` (no liability on our books, just a receivable from the issuer pending settlement)
- Per-country issuer catalog seeded per tenant

These are completely different code paths from the store-voucher liability ledger, which is why they must be a distinct tender. Cashier ergonomics in Phase 2: separate tender button labelled "Restaurant Ticket / Ticket Restaurant" (or per-country variant) on the POS payment screen.

### 3.2 Voucher as a tender at redemption (PaymentMethod, NOT PaymentType — and not a "tax-handling tender")

- **`Treasury\Domain\Enums\PaymentType` is not modified.** It stays at the existing six cases. Voucher is **not** a Treasury payment-type because Treasury types denote money-flow direction in AR/AP accounting; voucher tender at the POS doesn't fit cleanly there and would force changes to `Payment::scopeIncoming/Outgoing` and every exhaustive match.
- A new `PaymentMethod` row is seeded per tenant: `code = 'store_voucher'`, `name = 'Store Voucher / Bon d'achat'`, `is_physical = false`, `instrument_kind = 'store_voucher'`. The seeder runs per tenant via the existing tenant-bootstrapping pipeline.
- Sale receipts that use voucher tender record a `pos_receipt_payments` row with `payment_method_id = <voucher method>`, `payment_type = 'pos'` (the existing POS receipt type), and the new instrument fields below.
- **The redeeming sale handles its own VAT normally.** Voucher tender does not carry VAT logic. The voucher's role is purely as a tender (settles part or all of the sale's amount due) backed by a non-taxable liability ledger.
- The voucher ledger is the source of truth for balance/status. The payments row is the receipt-side audit; the ledger is the voucher-side audit. Both are needed.

### 3.3 `instrument_serial` rename + discriminator

`pos_receipt_payments`:

- Rename `voucher_serial` → `instrument_serial` (string nullable).
- Add `instrument_type` (string nullable, enum-backed: `restaurant_voucher | store_voucher | gift_card | none`).
- Migration is a column rename + new column. PHP-side: a value object `PaymentInstrument` with `kind` + `serial` so call sites can't mix them up.
- Backfill: existing rows where the legacy `voucher_serial` was set become `instrument_type = 'restaurant_voucher'` — preserves the field's original documented meaning. The discriminator value is recognized by the column constraint and DTOs but **rejected by Phase 1 issuance/redemption services** (§3.2.1).
- Phase 1 issuance/redemption: only `instrument_type = 'store_voucher'` is wired. Other values raise the corresponding "not yet supported" exception with a clear runbook pointer.

### 3.4 Exchange linking — sealed in hash payload

- Add `exchange_group_id UUID NULL` to `pos_receipts` (and any archive table). Indexed.
- An exchange creates two receipts sharing the same `exchange_group_id`.
- **The receipt hash payload includes `exchange_group_id`** alongside the existing fields. `ReceiptHashService` and the seal computation are updated to pass it. Schema-version bump on the chain payload (see §5.4).

### 3.5 Refund-policy storage — extend `Company.reservation_settings`

The existing `Company.reservation_settings` JSON blob (managed via `CompanyController`, fields `customer_return_expiry_days` and `high_value_alert_threshold` already present) gains:

- `customer_return_expiry_days` — already exists; keep. Treated as the canonical return window.
- `customer_history_window_days` — new; default = `customer_return_expiry_days`.
- `out_of_window_policy` — new; `'refuse' | 'voucher_only'`; default `'voucher_only'`.
- `manager_override_threshold_amount` — new; decimal (currency-relative); default 50.00.
- `manager_override_threshold_percent` — new; decimal (0–100); default 10.00. The effective threshold is `max(flat, percent × original_total)`.
- `manager_override_required_for_no_receipt` — new; bool; default `true`.
- `allowed_refund_destinations` — new; array subset of `['original_payment', 'cash', 'store_voucher']`; default all three.
- `proration_strategy` — new; `'proportional' | 'largest_first' | 'cashier_choice'`; default `'proportional'`.
- `voucher_default_expiry_days` — new; int; default 365.
- `voucher_transferable_default` — new; bool; default `true` (voucher created as bearer unless cashier sets otherwise at issuance).
- `voucher_cash_refund_allowed` — new; bool; default `false` (F21 — voucher-to-cash refund denied unless explicitly enabled per tenant + manager override).
- `daily_refund_cap_per_cashier` — new; nullable decimal; default null (no cap).
- `daily_refund_cap_override_allowed` — new; bool; default `true` (manager can extend the cap on the day with a reason).
- `customer_history_search_max_per_cashier_per_day` — new; int; default **15** (Codex review 2 finding M; tightened from 30).
- `customer_history_search_alert_thresholds` — new; `{ rejected_specificity_per_hour: 3, same_partner_per_day: 8, cross_company_immediate: true }` (M; "broad/hour" replaced with "rejected/hour" because broad searches don't execute per §2.6 minimum specificity).
- `voucher_lookup_per_terminal_per_day` — new; int; default 200.
- `voucher_lookup_per_cashier_per_day` — new; int; default 100.
- `voucher_lookup_failed_per_tenant_per_hour_alert` — new; int; default 50 (alert threshold).
- `voucher_lookup_failed_per_tenant_per_hour_block` — new; int; default 200 (hard block threshold).
- `voucher_failed_attempts_auto_void` — new; int; default 5 (auto-void after this many failed redemption attempts on the same voucher).
- `goodwill_named_customer_threshold` — new; decimal; default 100.00 (above this, goodwill voucher must have a named customer).
- `goodwill_four_eyes_threshold` — new; decimal; default 250.00 (above this, goodwill issuance requires a second admin's approval).
- `goodwill_daily_issuance_cap_per_user` — new; nullable decimal; default null (no cap).
- `goodwill_bearer_default_off` — new; bool; default `true` (Codex review 2 finding I — bearer goodwill default off; explicit opt-in per tenant).

A back-office settings page ("POS Refund Policies") edits these. Existing `customer_return_expiry_days` and `high_value_alert_threshold` continue to work; new fields are additive.

### 3.6 Audit fields on return receipt

Extend the return receipt row:

- `authorized_by_user_id` (nullable; set if manager override fired)
- `override_reason` (nullable string)
- `out_of_window` (bool, true if the original sale was outside the window when the return ran)
- `policy_trigger` (nullable string: `over_threshold | over_daily_cap | no_receipt | out_of_window | …`)
- `refund_request_id` (UUID, idempotency key)

The same four audit columns (`authorized_by_user_id`, `override_reason`, `policy_trigger`, plus `request_id`) propagate to:

- `vouchers` (issuance)
- `voucher_ledger` (every event row)
- `payments` written by `PaymentRefundService` (the negative payments produced by proration)

This is the F27 fix: fraud investigation reads any of these tables and finds the override context without reconstructing it from receipts.

### 3.7 Per-line eco-tax fields (Phase-2 forward compatibility, columns only)

Add to `pos_receipt_lines` (and the document line equivalent):

- `eco_tax_amount` (nullable decimal, scale-currency)
- `eco_tax_rate` (nullable decimal)
- `eco_tax_category` (nullable string, FK to a future eco-tax category table — for now a free string)

**Phase 1 ships the columns + DTO fields only. Writer integration is deferred to Phase 2.** (Codex review 2 finding N narrows the original "lossless data whenever upstream emits" claim — `ReceiptCreationService` builds an explicit whitelist that drops unknown line attributes, so honoring upstream eco-tax values would require updating the writer + fixtures + tests today, expanding Phase 1 scope unnecessarily.) Phase 2 will:
- Extend `ReceiptCreationService` line construction whitelist to include eco-tax fields.
- Add `eco_tax_*` to `ReceiptLine` and `DocumentLine` `$fillable`.
- Update factories / fixtures to default the fields to null.
- Wire the upstream tax engine to emit eco-tax per line for applicable products.

Until Phase 2, the columns stay null on every row written. The Compliance contract DTO accommodates them as nullables already (verified on `origin/dev`); no contract change.

### 3.8 Permissions

New Spatie permissions in `RolesAndPermissionsSeeder`:

- `pos.search_customer_recent_purchases` — `Cashier`, `Manager`, `Admin`.
- `pos.search_customer_full_history` — `Manager`, `Admin`.
- `pos.search_customer_cross_company` — `Admin`.
- `pos.refund_above_threshold` — `Manager`, `Admin`. Bypasses `manager_override_threshold_*`.
- `pos.refund_no_receipt` — `Manager`, `Admin`. Required to issue a goodwill voucher without an original receipt.
- `pos.refund_extend_daily_cap` — `Manager`, `Admin`. Authorizes one cashier's daily-cap override.
- `pos.issue_goodwill_voucher` — back-office; `Manager`, `Admin`.
- `pos.void_voucher` — back-office; `Manager`, `Admin`.
- `pos.extend_voucher_expiry` — back-office; `Admin`.
- `pos.transfer_voucher` — back-office; `Admin`.
- `pos.redeem_voucher` — `Cashier`, `Manager`, `Admin` (defaults; tenant can restrict).
- `pos.refund_destination_override` — required for `cashier_choice` proration when policy demands it.
- `pos.refund_voucher_to_cash` — required to refund a voucher itself (paired with tenant's `voucher_cash_refund_allowed` setting).
- `pos.fiscal_schema_cutover` — Admin only. Required to flip a terminal from v2 to v3 hash schema (§4.1, §5.4).
- `pos.rotate_qr_signing_key` — Admin only. Required to rotate the tenant's QR signing key (§4.5).
- `pos.issue_goodwill_voucher_high_value` — Admin only. Required to issue goodwill vouchers above `goodwill_four_eyes_threshold`. Requires a second admin's approval at issuance time (server-side dual-approval workflow).

## 4. Backend service changes

### 4.1 Receipt finalization rewrite (sealed payment payload — schema-version cutover)

**Codex review 1 (A1/A2) + Codex review 2 (A, C).** Today's hash inputs are insufficient: `ReceiptCreationService.php:478` seals `payment_methods_hash` over `[]` and `ReceiptPaymentService.php:190` appends payment rows after sealing. Online and offline paths share this gap; offline already computes a client-side hash with payments (`apps/pos/src/lib/fiscal/hashService.ts`) but the server's `ReceiptSyncService.php:280` recomputes with `[]`, so the offline `offline_fiscal_hash` is currently ignored.

Refund flow does **not** rewrite the legacy hash to magically include payments. It introduces **schema-version v3** as a new canonical hash, while keeping v1/v2 untouched.

#### v3 receipt hash payload

See §5.0 for the formal canonical payload. v3 includes: legacy fields (`receipt_number`, `posted_at`, `total`, `currency`, `vat_breakdown_hash`) + `payment_methods_v3_hash` (over the actual payment rows including `instrument_type` + `instrument_serial`) + `voucher_ledger_v3_hash` (over voucher issuance/redemption rows touching this receipt) + `exchange_group_id` + `audit_v3_hash` (over `authorized_by_user_id`, `override_reason`, `policy_trigger`, `out_of_window`, `refund_request_id`).

#### `pending_seal` lifecycle

A new `Receipt::status = pending_seal` is introduced. Migrations:

- `fiscal_hash` and `chain_sequence` become **nullable** (until finalization). Today they are NOT NULL — the migration must be a zero-downtime two-step (add nullable, dual-write, drop NOT NULL) or wrapped in a maintenance window per tenant.
- The PostgreSQL immutability trigger (`2026_01_08_190637_create_pos_receipts_table.php:154`) is updated to allow the **single** `pending_seal → fiscalized` transition. Today it allows only void status flips. Any other update on a `pending_seal` row except `status`, `fiscal_hash`, `chain_sequence`, `previous_hash`, `payment_methods_hash`, `vat_breakdown_hash` is rejected.
- Pending receipts are excluded from JET exports, Z-report aggregation, and `verify-chain` walks. They are visible in admin "in-flight" diagnostics only.
- Pending receipts have a TTL (default 30 minutes via a scheduled job that purges abandoned drafts). Purging logs to `customer_history_searches`-style audit table.

#### Service decomposition

- `ReceiptCreationService::create()` returns a `Receipt` in `pending_seal` status with no fiscal_hash. (Today this method seals; behavior changes for v3 callers.)
- `ReceiptPaymentService::recordPayments()` writes payment rows linked to the pending receipt. (Today it operates on already-sealed receipts; behavior changes for v3 callers.)
- `VoucherRedemptionService::redeem()` writes voucher tender rows + ledger entries linked to the pending receipt.
- `ReceiptFinalizationService::finalize($receipt)` is the new seal step: validates all rows, computes v3 canonical payload (§5.0), writes `fiscal_hash`, `chain_sequence`, `previous_hash`, transitions `status = fiscalized`. Non-idempotent on second call (returns the existing fiscal_hash on retry).

#### Online and offline parity

Both paths route through `ReceiptFinalizationService` for v3:

- **Online**: cart confirm → `create()` (pending_seal) → `recordPayments()` → `redeem()` if voucher → `finalize()` in one HTTP request, one DB transaction.
- **Offline (Tauri)**: `apps/pos/src/lib/fiscal/hashService.ts` is rewritten to compute the v3 canonical hash (§5.0) byte-identically with PHP. The Tauri client writes a draft receipt with all rows + computes its own `offline_fiscal_hash` over the v3 payload. On sync, `ReceiptSyncService::syncReceipt()` re-runs `ReceiptFinalizationService` server-side, recomputes the hash, and **fails the sync** if the server hash ≠ `offline_fiscal_hash`. The server is authoritative; the offline hash is a tamper-detection cross-check, not a trust signal.

#### Schema-version cutover gate

v3 is enabled **per terminal** via `Terminal.fiscal_schema_version` (default v2). The flip from v2 to v3:

- Requires no open shift on the terminal.
- Requires no un-Z-reported receipts on the terminal.
- Is performed via a back-office action gated by `pos.fiscal_schema_cutover` permission (Admin only).
- Is logged to a fiscal-audit table.
- Is one-way (no v3 → v2 rollback in normal operation; emergency rollback is a manual ops procedure with a separate runbook).
- For **France tenants**, additionally gated on the certification flag (§10.1).

The legacy verifier (`hashPaymentMethods([])`) still walks pre-cutover receipts; the v3 verifier walks post-cutover receipts. `verify-chain` dispatches per-receipt by `fiscal_schema_version`.

#### Blast-radius callers

Files that need migration to the finalize-after-payments flow:

- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` (online sale path)
- `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php` (offline sync path) — Codex C
- `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php` (payment write path)
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php` (return path; today seals receipts inline at line 218)
- `apps/api/app/Modules/POS/Application/Services/OrderToReceiptService.php` (held-order conversion)
- `apps/pos/src/lib/fiscal/hashService.ts` (Tauri client) — must produce identical v3 bytes to the PHP service
- `apps/pos/src/lib/offline/receiptService.ts` (offline draft writer) — produces the v3 payload client-side

This is the most invasive part of the spec. The plan **must** include golden hash test vectors shared between PHP and TypeScript before any service refactor lands. Test scenarios: cash-only sale, mixed-tender sale, voucher tender at sale, return receipt, exchange both halves, offline-then-sync round-trip producing identical hashes.

### 4.2 ReceiptReturnService extensions

- Accepts `RefundDestination` (`OriginalPayment | Cash | StoreVoucher`), `refund_request_id`, optional `authorized_by_user_id` + `override_reason` + `policy_trigger`.
- Validates: return window, daily cap (with extension override), allowed destinations (server-side resolver), effective override threshold = `max(flat, percent × original_total)`.
- Calls `VoucherIssuanceService::issueFromRefund(...)` if destination is voucher; passes the returned voucher into the credit-note finalization payload.
- Idempotent on `refund_request_id`.

### 4.3 PaymentRefundService extensions

- Add `refundReceiptPayments(Receipt $original, BCAmount $totalToRefund, ProrationStrategy $strategy, Uuid $refundRequestId, ?int $authorizedByUserId): RefundAllocation[]`.
- Strategies as in §2.7.
- Each negative payment carries `original_payment_id`, `refund_request_id`, `authorized_by_user_id`.
- **`payment_type` set explicitly** to `PaymentType::Refund` on every negative refund payment row. (Codex review 2 additional finding: today's `PaymentRefundService.php:54-69` writes refund payments without `payment_type`, falling back to the column default `'document_payment'`. The proration extension fixes this latent bug for both new prorated refunds and a follow-up backfill data-migration is logged in §8 for the existing rows.)
- Idempotent on `(receipt_id, refund_request_id)`.
- Currency rounding: amounts allocated using `CurrencyScaleResolver` (already injected throughout POS services); residual cent allocated to the **last** payment in proration order; deterministic ordering by `(payments.amount DESC, payments.id ASC)`.

### 4.4 New: ExchangeService

`apps/api/app/Modules/POS/Application/Services/ExchangeService.php`:

- `processExchange(Terminal, User, Receipt $original, ReturnLineSelection[], CartItem[] $newSaleItems, RefundDestination, TenderInput $netPayment, Uuid $exchangeRequestId): ExchangeResult`.
- Idempotent on `exchange_request_id` (F28). Re-call returns the same `(returnReceiptId, saleReceiptId, voucherId?)` triple.
- Single DB transaction:
  1. Lock inventory rows for all SKUs touched by both halves (FOR UPDATE).
  2. Create the unsealed return receipt draft + the unsealed sale receipt draft. Both share `exchange_group_id`.
  3. Run inventory: returned lines emit `MovementReason::POSReturn` movements; new lines emit `MovementReason::POSSale`. Net stock effect computed from both halves; same-SKU return+sale of equal qty must net to zero net inventory change while still writing both audit movements (F26).
  4. Issue voucher if surplus → voucher.
  5. Seal both receipts via `ReceiptFinalizationService` in order (return first, then sale). Each hash payload includes `exchange_group_id`.
  6. Settle net payment.
- Returns receipt ids + voucher id (if any) + net amount.

### 4.5 New: ReceiptLookupService — QR token format (Codex review 2 finding G)

`apps/api/app/Modules/POS/Application/Services/ReceiptLookupService.php`:

#### Token format

Printed receipt QR contains `v:kid:receipt_uuid:mac` (base64url-encoded as a whole), where:

- `v` = token format version (currently `1`).
- `kid` = key id of the tenant signing key used (rotation support; see below).
- `receipt_uuid` = lowercase hyphenated UUID of the receipt.
- `mac` = `HMAC-SHA-256(canonical_json(payload), tenant_signing_key[kid])` truncated to 128 bits, base64url-encoded.

Where `payload` is the canonical JSON object:
```
{"company_id": "<UUID>", "issued_at": "<ISO-8601 UTC>", "kid": "<kid>", "purpose": "receipt_lookup", "receipt_uuid": "<UUID>", "tenant_id": "<UUID>", "v": 1}
```

#### Verifier rules

`findByQrToken(string $token, Terminal $terminal): Receipt` — verifier:

1. Parses `v:kid:receipt_uuid:mac` (rejects malformed / wrong version).
2. Loads the active signing key set for **`$terminal->tenant_id` only** by `kid`. If the kid is not in the active set for this tenant, return generic "invalid token" (no leak that a different tenant uses this kid).
3. Reconstructs the payload using `terminal->tenant_id` and `terminal->company_id` (NOT data extracted from the token — the verifier never trusts client-supplied tenant/company values).
4. Computes the HMAC and compares with **constant-time** equality (`hash_equals`).
5. On match, fetches the receipt by `(receipt_uuid, tenant_id, company_id)` — **never by receipt_uuid alone**. If not found in this tenant+company, returns generic "invalid token".
6. Same generic error for "wrong tenant", "tampered MAC", "expired key", "receipt deleted", "kid retired" — no information leak.

#### Cross-tenant scan behavior

If a receipt printed by tenant A is scanned at a tenant B terminal:
- The token's `kid` is looked up against B's signing key set; not found → generic invalid.
- Even if the kid happened to match (collision avoided by namespacing kids per tenant in the keystore), the HMAC would not verify because keys differ.
- The Receipt fetch at step 5 would also fail (different tenant_id).

#### Key rotation

Each tenant has at minimum **two active signing keys**: `kid` = `current` and `kid` = `previous`. Rotation:

- Admin triggers rotation via a back-office action (`pos.rotate_qr_signing_key` permission).
- New key generated, set to `current`. Old `current` becomes `previous`. Old `previous` is retired (deleted from the active set; receipts signed with it become un-lookupable via QR — typed ticket-number lookup still works).
- Default rotation cadence: opt-in per tenant; not forced. A tenant can rotate after a security incident or never.
- Receipts already printed before rotation remain lookupable until their kid retires (typically only after a second rotation moves them out of the active set).

#### Storage

Tenant signing keys stored in `tenant_signing_keys` table with `tenant_id`, `kid`, `key_material` (encrypted at rest using Laravel's app key — out of scope to redesign), `purpose = 'receipt_qr'`, `is_active`, `created_at`, `retired_at`. **Never logged**, never returned by any API.

#### Authorization scope

Bearer-receipt-QR grants **lookup only**. The cashier still needs `pos.process_returns` (or higher) to act on the lookup result. Anyone holding the printed receipt can fetch its details at the POS, but cannot initiate a refund without authenticated cashier-side authorization.

`findByReceiptNumber(string $number, Terminal): Receipt` — typed fallback (no token semantics; same `(receipt_number, tenant_id, company_id)` filter).

`findByCustomer(Partner $partner, User $cashier, Terminal): Receipt[]` — applies the §2.6 privacy rules: window from `customer_history_window_days`, masked totals until row is opened, audit log row written, layered rate limiter checked, alert events emitted on threshold breach.

### 4.6 New: RefundDestinationResolver

A domain service that, given `(tenant_settings, original_receipt, requested_destination, cashier_permissions)`, returns the actual allowed destination or throws `RefundDestinationNotAllowedException`. UI selects on this resolver's output; the API endpoint also runs it server-side (C12) so a malicious client can't bypass `out_of_window_policy = voucher_only`.

### 4.7 Customer-history search + voucher rate limiter (Codex review 2 finding H)

#### Customer-history search

- `CustomerHistorySearchService` — wraps the search; writes to a new `customer_history_searches` audit table.
- `CustomerHistorySearchRateLimiter` — Redis-backed per-cashier-per-day counter; rate limit per tenant defaults in §3.5.
- `BroadCustomerSearchAlert` event — emitted on threshold breach; back-office subscribes and surfaces in fraud-monitor UI (existing pattern).

#### Voucher lookup — layered rate limiting

Voucher lookup `GET /pos/vouchers/lookup?code=<code>` is the brute-force surface. Per-terminal-only rate limiting is insufficient for bearer codes that carry monetary value. Layered counters (all Redis-backed):

- **Per-terminal/day**: hard limit 200 lookups (default; tenant-configurable). Above limit, lookup endpoint returns generic "rate limited" without revealing whether the code exists.
- **Per-cashier/day**: hard limit 100 lookups.
- **Per-tenant/hour for failed lookups**: soft alert at 50, hard block at 200 (default; tenant-configurable). "Failed" = code not found OR found-but-invalid (expired, voided).
- **Per-IP/API-token/hour**: 300 lookups.
- **Per-code-prefix (first 4 chars after the tenant prefix)/hour**: 30. Catches sequential scans of `XXXX-A0001`, `XXXX-A0002`, etc.
- **Per-voucher failed attempts/24h**: 5. After 5 failed redemption attempts on the same voucher (e.g. wrong customer for a CustomerBound voucher, or repeated scan after expiry), the voucher is auto-`Voided` and a `VoucherFraudAlert` event is emitted. Manager can un-void from the back-office.

#### Lookup-vs-redeem disclosure boundary

The POS lookup endpoint behavior depends on whether a payment session is active:

- **Outside an active payment session** (casual scan, no cart): returns minimal `{exists: true|false, status: "active|expired|invalid"}` — never balance, never customer name, never expiry date. Generic invalid response on any rate limit trip.
- **Inside an active payment session** (cart open, cashier actively trying to apply the voucher): returns full `{balance, expires_at, redemption_mode, partner_id_match}` after all rate-limit checks pass.

This prevents drive-by enumeration of voucher balances; balance is exposed only to a cashier who has demonstrated intent by opening a cart.

#### Implementation

- `VoucherLookupRateLimiter` — Redis sliding-window counters on each layer; checks short-circuit on first failure.
- `VoucherLookupService::lookupForPayment(string $code, Terminal, Cart): VoucherLookupResult` — the in-session path with full disclosure.
- `VoucherLookupService::lookupGeneric(string $code, Terminal): GenericLookupResult` — the no-session path with status-only disclosure.

### 4.8 Compliance contract integration (H3 — already landed)

H3 landed on `dev` (2026-04-28) with `Nf525DataProviderContract` plus DTOs under `App\Shared\Contracts\Compliance\DTOs\`. The POS-side `Nf525DataProvider` implementation is the seam between POS and Compliance; refund flow extends the **provider implementation only**, not the contract.

Reserved extension points already on the DTOs (refund flow populates the nullable fields):

- `Nf525ReceiptData::$exchangeGroupId` — populated for both halves of an exchange (§3.4).
- `Nf525ReceiptData::$authorizedByUserId`, `$overrideReason`, `$outOfWindow` — populated for return receipts when a manager override fires (§3.6).
- `Nf525ReceiptData::$voucherLedgerEntries` (`array<Nf525VoucherLedgerEntryData>`) — populated with issuance rows on credit notes and redemption rows on sale receipts (§3.1).
- `Nf525ReceiptPaymentData::$instrumentType`, `$instrumentSerial` — populated whenever a payment row carries a voucher / gift-card / restaurant-voucher instrument (§5.1).
- `Nf525ZReportData::$reportData` — JSONB pass-through. Z-report v3 keys (§5.3) flow through unchanged at the contract level; the XML builder reads them directly from the array.

**Refund flow does not add new direct cross-module imports** and does not modify the contract. The single file refund flow extends on the Compliance seam is `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` (the POS-side implementation of the contract) — wiring the populated nullables and the v3 Z-report `reportData` keys. The XML-emit logic for the new DTO blocks inside Compliance is a Compliance-team follow-up, not a refund-flow PR.

### 4.9 ReceiptVoidService — out of scope, with a caveat

Existing void-flag behavior stays. New caveat documented: voiding a credit note that birthed unredeemed vouchers cascades via `VoucherCascadeService` (§3.1). Voiding a credit note with redeemed vouchers is blocked.

## 5. Fiscal & compliance — sealing details

### 5.0 Receipt hash v3 — canonical payload

**Codex review 2 finding D.** The v3 hash uses **canonical JSON (RFC 8785 / JCS)** as the byte format, not positional pipe serialization. This is necessary to keep PHP and TypeScript implementations byte-identical without per-field positional fragility.

**Top-level payload object**, in lexicographic key order:

```
{
  "audit_hash": "<sha256 of audit subobject, hex lowercase>",
  "currency": "<ISO-4217 code, uppercase>",
  "exchange_group_id": "<lowercase UUID>" | null,
  "payment_methods_hash": "<sha256 of payments array, hex lowercase>",
  "posted_at": "<ISO-8601 with explicit timezone, format YYYY-MM-DDTHH:MM:SSZ in UTC>",
  "previous_hash": "<hex lowercase>" | null,
  "receipt_number": "<string>",
  "schema_version": 3,
  "total": "<decimal string at currency_scale, e.g. \"12.345\" for TND>",
  "vat_breakdown_hash": "<sha256 of vat array, hex lowercase>",
  "voucher_ledger_hash": "<sha256 of voucher ledger entries, hex lowercase>"
}
```

**Subobject canonicalization rules:**

- `payment_methods_hash` is `sha256(canonical_json(sorted_payments))`. Each payment is `{"amount": "<decimal at currency_scale>", "instrument_serial": "<string>" | null, "instrument_type": "<string>" | null, "method_code": "<string>", "payment_type": "<string>"}`. Sort by `(method_code ASC, instrument_type ASC NULLS FIRST, instrument_serial ASC NULLS FIRST, amount ASC, position_in_array ASC)`. The position tiebreaker handles two identical voucher redemptions of the same code (which the §3.1 duplicate-in-transaction guard prevents at write time, but the hash is defensive anyway).
- `vat_breakdown_hash` keeps the existing `rate:amount` format but is wrapped in `sha256(canonical_json(sorted_array_of_{rate, amount}))` for v3 (sort by rate ASC).
- `voucher_ledger_hash` is `sha256(canonical_json(sorted_ledger_entries))`. Each entry is `{"amount": "<signed decimal at internal_precision>", "event": "<enum string>", "gl_journal_entry_id": "<UUID>" | null, "voucher_code": "<string>", "voucher_id": "<lowercase UUID>"}`. Sort by `(voucher_code ASC, ledger_row_id ASC)`.
- `audit_hash` is `sha256(canonical_json({...}))` over `{"authorized_by_user_id": "<UUID>" | null, "out_of_window": <bool> | null, "override_reason": "<string>" | null, "policy_trigger": "<string>" | null, "refund_request_id": "<UUID>" | null}`. Empty/all-null subobject hashes to a fixed sentinel `sha256("{}")` so receipts without any audit context still round-trip.

**Decimal formatting:**

- `currency_scale` comes from `CurrencyScaleResolver` (TND=3, EUR=2, etc.).
- All amounts in the payment subobject use `currency_scale`.
- Voucher ledger amounts in the ledger subobject use **internal precision = currency_scale + 2** (§5.5), serialized as a decimal string with trailing zeros (e.g. `"+10.0000"` for €10 at internal precision).
- No exponents, no plus sign except on signed voucher amounts, no thousands separators.

**Null sentinel:** JSON `null` (not `"null"`, not omitted). Canonical JSON serializes nullable absent fields with `null`.

**UUID format:** lowercase, hyphenated, RFC 4122 (`xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`).

**Timestamp:** UTC with `Z` suffix, second precision, no fractional seconds.

**Hash algorithm:** SHA-256, hex lowercase, no prefix.

**Test vectors (mandatory before service code lands):**

1. Cash-only EUR receipt, single tender — golden `fiscal_hash` and golden `payment_methods_hash`.
2. Mixed-tender TND receipt, two payments with different methods — golden hash; demonstrates sort order across methods.
3. Voucher tender + cash, EUR — golden hash; demonstrates `instrument_serial` in payload.
4. Two-voucher stacking (different codes, same tender method) — golden hash; demonstrates secondary sort by serial.
5. Return receipt with `out_of_window=true`, `authorized_by_user_id` set, voucher issuance ledger row — golden hash.
6. Exchange — both halves of the same `exchange_group_id` produce distinct hashes that both commit the group_id.
7. Offline TND receipt with sub-millime voucher residual ledger — round-trip golden hash through Tauri client and PHP server, identical bytes.

These vectors live at `apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/` (PHP) and `apps/pos/src/lib/fiscal/__fixtures__/v3-golden-hashes/` (TypeScript) — same test data, two languages, asserted identical at CI time.

### 5.1 What's sealed in each receipt's hash payload (revised)

For both sale and return receipts, the hash payload now includes:

- All existing fields (lines, totals, VAT breakdown, terminal, sequence, customer name)
- `previous_hash` (terminal's last_hash)
- All payment rows: `(payment_method.code, payment_type, amount, instrument_type, instrument_serial)` — instrument_serial fills the additional finding gap
- All voucher_ledger rows touching this receipt: `(voucher_id, voucher_code, event, amount, gl_journal_entry_id)` — for redemption rows on sale receipts and issuance rows on credit notes
- `exchange_group_id` (if set) — committing the exchange link cryptographically (B8)
- `refund_request_id` and `authorized_by_user_id` (if set)
- Schema-version field bumped to v3 in `ReceiptHashService` payload format

### 5.2 Voucher GL accounting matrix — non-taxable liability + tender (research-driven, Codex review 2 finding E)

**Critical:** voucher redemption does **not** route through `GeneralLedgerService::createPOSPaymentEntry()` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1017`). That existing method debits cash repository and credits revenue, which would double-count revenue when the original sale already booked it (the voucher just shifts who pays for the new sale, it doesn't generate fresh revenue at the voucher layer).

**No VAT at the voucher layer.** Per EU Directive 2016/1065 / FR BOI-TVA-CHAMP-10-10-40-50 / IT D.Lgs. 141/2018 / UK VATA Sch. 10B, an MPV (multi-purpose voucher) — which is what every refund-issued store credit and generic gift card is by default — has VAT accounted for **only at redemption, on the underlying sale**. The voucher itself never carries VAT. The credit note that birthed it already reversed the original sale's VAT.

A new `GeneralLedgerService::createVoucherLedgerEntry(VoucherLedger $entry)` posts the GL line for each ledger event. Each `voucher_ledger` row's `gl_journal_entry_id` references the entry created here.

**Accounting matrix** (the GL direction for every voucher ledger event — all entries are non-taxable):

| Event | Debit | Credit | Notes |
|---|---|---|---|
| `Issued` (from refund) | Sales-returns clearing | Voucher liability | Mirror of the credit-note posting. The credit note already reversed the original sale's VAT; this entry just shifts the customer's claim from "cash refund" to "store credit liability". |
| `Issued` (goodwill, back-office) | Marketing-goodwill expense | Voucher liability | Different debit account from refund-issuance; tenant chart configures both. |
| `Issued` (exchange surplus) | Sales-returns clearing | Voucher liability | Same as refund issuance. |
| `Redeemed` (full or partial) | Voucher liability | POS tender clearing | **`createPOSPaymentEntry` is called only for non-voucher tenders.** Voucher tender skips it. The redeeming sale's own journal entry handles VAT and revenue normally; this voucher entry just settles the tender. POS tender clearing nets to zero per shift. |
| `PartiallyRedeemed` | (no GL — projection-only event) | (n/a) | Status row in the ledger; no separate GL line. |
| `Voided` (no prior redemption) | Voucher liability | Same clearing account used at issuance | Symmetric reversal. |
| `Voided` (with prior redemptions — blocked path) | (n/a — blocks at application layer) | (n/a) | §3.1 / §4.9 hard-block in Phase 1. |
| `Expired` | Voucher liability | Voucher-breakage income (per tenant chart) | Per-jurisdiction policy. Default chart entry: a "voucher liability — manual review" account so accounting decides write-off treatment per-tenant. |
| `Reversed` (cancellation of a redemption) | POS tender clearing | Voucher liability | Exact mirror of `Redeemed`. Carries `reverses_voucher_ledger_id`. |
| `Transferred` | (no GL line by default) | (n/a) | Ownership-change metadata only. |
| `RoundingAdjustment` | Voucher liability | Rounding-loss expense | §5.5 final-redemption residual write-off. |

**SPV exception:** if a tenant ever issues an SPV (`voucher_kind = 'SPV'`) — a single-purpose prepaid coupon where the VAT rate and place of supply are known at issuance — the issuance entry must include VAT (debit clearing, credit voucher liability + VAT output), and redemption must NOT charge VAT a second time on the underlying sale. **Phase 1 does not implement SPV issuance** — the column exists, but issuance code refuses `voucher_kind = 'SPV'` with a clear "not yet supported" error. MPV is the entire Phase 1 path.

**Implementation guard**: `voucher_payment_method_id` is detected in `ReceiptFinalizationService` and excluded from the per-payment `createPOSPaymentEntry` loop. PHPStan-level test asserts no code path calls `createPOSPaymentEntry` with a `Payment` whose `payment_method_id` matches the voucher method.

**Per-tenant chart configuration**: voucher liability account, marketing-goodwill expense, sales-returns clearing, breakage income, rounding-loss expense are seeded by a tenant chart-of-accounts migration. Tenants must have these configured before refund-flow v3 is turned on for them.

**Fiscal hash chain placement**: voucher issuance appears on the credit note's hash payload via `voucher_ledger_hash` (§5.0); voucher redemption appears on the sale receipt's hash payload via the same. The voucher *balance state* (current_balance) is NOT on the chain — only the events that change it. This matches NF525's "events are immutable, balances are projections" model and avoids needing to chain non-fiscal balance updates.

The ledger row references the GL entry id; the GL entry references back to the voucher and ledger row id (bidirectional). This is the fiscal-grade audit trail; the payments row is a secondary view.

### 5.3 Z-report — sale vs return aggregation split (A4 fix)

`ReportGenerationService.php:842` is rewritten so the aggregation loop:

- Counts `Receipt::type = Sale` (or whatever the existing convention is for non-return) into `sales_count` / `gross_sales` / `net_sales`.
- Counts `Receipt::type = Return` into `refunds_count` / `refunds_amount`.
- Emits new fields: `vouchers_issued_count`, `vouchers_issued_amount`, `vouchers_redeemed_count`, `vouchers_redeemed_amount`.

`ZReportHashService.normalizeForHash()` is extended to normalize the new monetary keys (`refunds_amount`, `vouchers_issued_amount`, `vouchers_redeemed_amount`) at the configured currency scale. Schema-version v3 is introduced; v1 and v2 normalization paths remain valid for already-stored reports.

The JSONB-pass-through shape on `Nf525ZReportData::$reportData` (H3 contract) accommodates the new keys without DTO change — Compliance reads them as plain array keys. No contract-side migration; refund flow only changes how the POS provider populates `reportData` and how `ZReportHashService` normalizes it.

### 5.4 v2 → v3 chain transition — per-terminal Z-close cutover (Codex review 2 finding J)

The v3 cutover happens **per terminal at the next Z-close**, never mid-shift. The flip from v2 to v3 on a terminal is gated on:

- No open shift on the terminal.
- No un-Z-reported receipts on the terminal.
- `pos.fiscal_schema_cutover` permission (Admin only).
- For France tenants, additional gating per §10.1.

A back-office "Cut over to v3" action runs the validations server-side, writes a `Terminal.fiscal_schema_version = 3` flip + audit row + Z-fiscal-event entry, and from that point every new receipt and Z-report on that terminal is v3.

Daily Z-report aggregation is per-terminal anyway, so a Z-report cleanly contains either all-v2 or all-v3 receipts. No mixed-schema reporting is supported in Phase 1.

**Golden replay test:** a regression simulates a terminal with several v2 reports, performs the cutover at a Z-close, then writes new v3 reports whose `previous_z_hash` is the last v2 fiscal hash. `verify-chain` must return true for the full sequence. Hash inputs differ between schema versions; the verifier already handles `schema_version <2 vs >=2`, and v3 extends that switch (and reads `fiscal_schema_version` per receipt for chain dispatch). A second test asserts that the cutover refuses to run when the terminal has an open shift.

### 5.5 Currency rounding rules — voucher residual policy (Codex review 2 finding K)

- All monetary maths use `CurrencyScale::bcformat($value, $scale)` per the existing project convention (see `MEMORY.md` warning about float precision).
- `$scale` comes from `CurrencyScaleResolver` (already injected in `ReceiptCreationService`, `ReceiptVoidService`, `OrderManagementService`).
- Proration residual: when splitting an amount across N items at scale S, allocate `floor(total / N)` to N−1 items and `total − (N−1) × floor(total / N)` to the **last** item (deterministic ordering as in §4.3).

**Voucher precision:**

- **Voucher ledger stores at internal precision = `currency_scale + 2`.** TND vouchers store at scale 5; EUR vouchers store at scale 4. This precision is internal-only — never exposed in customer-facing UI.
- **Display and tender amounts are at currency_scale.** A TND voucher with internal balance `12.34567` is shown as `12.345`; redemption is allowed only in increments of `0.001` (the minimum currency unit).
- **Issuance amounts** must be at currency_scale (rounded at the entry point, not at storage). A €10 refund creates a voucher with `current_balance = "10.0000"` at internal precision.
- **Partial redemption rule:** the applied amount is at currency_scale; the voucher's `current_balance` decreases by exactly that amount. Sub-currency-unit residuals come only from internal-precision ledger arithmetic, not from issuance.

**Final-redemption residual write-off:**

When `current_balance < min_currency_unit` (i.e. below `10^-currency_scale`), the next redemption attempt:

- Cannot apply the residual as a tender (it's below the minimum currency unit).
- Triggers a `RoundingAdjustment` ledger event automatically: writes off the residual to a tenant-configured rounding-loss account (§5.2 matrix), sets `current_balance = 0`, transitions status to `FullyRedeemed`.
- Customer-facing receipt notes "voucher fully redeemed".

This guarantees no operational dust accumulates and no voucher remains "stuck" with a non-zero but unredeemable balance.

**Tests cover TND scale-3 and EUR scale-2 explicitly,** including:

- Issue €10 voucher → redeem €3.45 → balance `6.5500` internal, `6.55` display.
- Issue 10.000 TND voucher → redeem 3.456 TND → balance `6.5440` internal, `6.544` display.
- Issue €10 → redeem 9.99 → 0.005 residual triggers `RoundingAdjustment`, status `FullyRedeemed`, GL line in rounding-loss account.

## 6. Frontend — POS Desktop (apps/pos) — UX patterns research-validated

Industry consensus for our use case (small-shop fiscal POS, exchange-as-primary, single-terminal): **cart-style negative-and-positive lines (Lightspeed/Cegid/Tilroy pattern)**, dedicated entry button + scan-from-anywhere with confirmation sheet, never silent cart conversion. Visual treatment with section headers and red typography. Manager PIN at confirm step gated on cash destination + threshold (not at flow start; card-refund-to-original is low fraud risk).

### 6.1 Refund flow entry points (hybrid, all available)

1. **Primary entry: "Returns / Exchange" button on the POS home grid.** Opens the receipt-locator screen with two tabs: "Scan or type ticket number" (default) and "Find by customer" (visible only with `pos.search_customer_recent_purchases`).
2. **Secondary entry: scan-from-anywhere with confirmation sheet.** When the cashier scans a token that the receipt-token verifier (§4.5) recognizes (`v:kid:receipt_uuid:mac` prefix), the POS shows a confirmation sheet — **never** silently mutates the cart. Sheet copy: "This is a sale receipt from [date]. Start a refund or exchange?" with "Cancel" / "Start refund". This prevents accidental refund initiation when a customer's old receipt scans during an active sale.
3. **Tertiary entry (Phase 1.5): from a Recent Receipts list** on the cashier home — pick a recent sale, tap "Refund/Exchange". Phase 1 ships without this; not blocking.

The scan-detection logic dispatches before product-barcode lookup: if the scanned bytes match the receipt-token format and the verifier accepts them (terminal-tenant key, MAC valid, receipt found in local SQLite for this terminal), the confirmation sheet appears. Otherwise the bytes fall through to product-barcode lookup as today.

### 6.2 Receipt-located screen + cart-style refund/exchange

After the original receipt is located (any entry point above):

- The **active POS cart** loads the original receipt's lines as **negative line items** ("Returning" section).
- The cashier can adjust quantities per line (full or partial) or remove lines (= keep them, don't refund).
- **At any time during the refund flow**, the cashier can scan or pick a new product — it appears in the same cart as a **positive line item** ("Buying new" section). This is how exchange happens: it is not a separate mode the cashier toggles, it is just adding new lines to a cart that already has negatives. Validated by industry consensus.
- The **cart UI shows two sections** with clear headers: **"Returning"** (red, with a return-arrow icon ↺) above and **"Buying new"** (default styling) below. Negative quantities render with a red `−` prefix and red total. Strike-through is NOT used (ambiguous with discount).
- The **footer shows the net amount**: positive = customer pays, negative = customer is owed. The "Confirm" button label adapts: "Charge X" / "Refund X" / "No payment due".
- A **draft session** persists in local SQLite with `exchange_request_id` (only generated when a positive line is added — pure refunds don't need it). Interruption recovery: closing the app or a crash restores the cart on reopen; cashier can resume or discard.

### 6.3 Refund destination picker

At the confirm screen (only when net is negative — customer is owed money):

- **Radios** for Original Payment / Cash / Store Voucher; greyed and rejected by `RefundDestinationResolver` (server-side, §4.6) per tenant policy.
- For Original Payment: shows the **proration breakdown** (which original tender gets how much back).
- Default selection: `OriginalPayment` if available, else `StoreVoucher` if voucher policy allows, else `Cash`.

### 6.4 Manager PIN — at confirm, gated on cash + threshold

Inline manager-PIN prompt fires at the confirm step (not at flow start), only when:

- Refund destination is `Cash` AND amount exceeds `manager_override_threshold` (= `max(flat, percent × original_total)` per §3.5), OR
- Refund is no-receipt (goodwill voucher path), OR
- Daily refund cap reached (`pos.refund_extend_daily_cap` permission required).

**Card-refund-to-original below threshold does NOT prompt for a manager PIN.** Industry consensus: the money returns to the original payment instrument, fraud risk is low, friction at the cashier is high.

The PIN component is the same one used today for discount override.

### 6.5 Voucher tender at payment (sale flow, separate from refund flow)

When a customer pays for a normal sale and wants to redeem a voucher:

- New tender button alongside Cash/Card: **"Voucher / Bon"**.
- Tapping opens scan/type input.
- The lookup hits **local SQLite first** (the in-payment-session disclosure boundary per §4.7 reveals balance only when an active cart is open).
- On valid voucher: balance + expiry + redemption_mode shown; cashier confirms amount to apply.
- **Stacking**: multiple vouchers per sale until remaining due hits zero. Server-side guard against same-voucher-twice-in-one-transaction.
- The redemption is a `pos_receipt_payments` row with `payment_method.code = 'store_voucher'` and `instrument_serial = <code>` (committed in v3 hash payload, §5.0).

### 6.6 Print artifacts

- **Sale receipt** — gains a QR token (signed receipt token, §4.5) printed at the bottom. This QR is what the cashier scans at refund time.
- **Refund receipt (avoir)** — header "REMBOURSEMENT" or "AVOIR" prominently. Body shows: original ticket number, original ticket date, returned lines with negative amounts, VAT breakdown of the refund, refund destination (cash dispensed / card reversal pending / voucher issued with code), v3 fiscal hash chain footer. **Reprint of the original receipt's QR** for traceability (industry best practice; not strictly mandated by NF525, but the original ticket reference IS).
- **Exchange receipt** — single customer-facing document showing both halves: the Returning section (negative amounts), the Buying new section (positive amounts), the net at the bottom. Internally this is two fiscal documents (return receipt + sale receipt joined by `exchange_group_id`); the printed customer document is presentation-only.
- **Voucher ticket** — when a voucher is issued: code, QR for scanning at redemption, expiry, balance, "Bearer" / "Customer-bound" label, terms note ("non-refundable for cash unless [tenant policy]"). Can be printed on the same paper as the refund receipt or on a separate ticket per printer profile.

### 6.2 Customer history finder (gated, hardened)

A modal entered from the Returns home (Find-by-customer tab). Accepts only specific identifier input (phone full / email full / loyalty-QR scan). Result list shows masked totals; clicking a row reveals total and shows "Return this" button. Rate-limit and audit are server-side; UI shows a soft "approaching limit" warning at 80% of daily quota.

### 6.3 Receipt printer changes

- Sale receipts gain a QR token (HMAC-signed receipt-UUID payload) printed at the bottom for future returns.
- Return receipts include the original receipt number + QR if reachable.
- Voucher tickets: code, QR, expiry, balance, customer note, "Bearer / Customer-bound" indicator.
- Voucher cash-refund denied receipts: clear messaging "Cannot redeem voucher for cash. See terms."

## 7. Frontend — Back-office (apps/web)

### 7.1 New pages — unified "Vouchers & Credits" surface

The user explicitly asked for a logical, easy-to-find surface across all redeemable instruments (refund vouchers, goodwill vouchers, loyalty-credit redemptions, future gift cards / promotional). Phase 1 builds the unified surface; Phase 1.5+ wires loyalty and Phase 2+ wires gift cards / promotional.

- **Vouchers & Credits list** (`apps/web/src/features/vouchers/pages/VoucherListPage.tsx`) — single page with **source filter** (default: All; tabs/chips for Refund / Exchange surplus / Goodwill / Loyalty credit / Gift card / Promotional). Common columns: code, source, customer (if any), balance, status, expires_at, issuing terminal/user. Bulk actions per row: void (privileged), extend expiry (privileged). Source filter persists in the URL so a tenant who only cares about refund-issued vouchers can bookmark that view.
- **Voucher detail** — single voucher with full ledger history (issuance, every redemption, transfer events, void). Source-specific links rendered in a "Provenance" section: refund vouchers link back to the credit note; loyalty vouchers (Phase 1.5+) link to the loyalty transaction; goodwill vouchers show issuer + reason.
- **Issue goodwill voucher** modal — manager creates a voucher (amount, expiry, customer optional, bearer/customer-bound, reason, GL clearing account selection if multiple). Backed by a goodwill GL entry per tenant chart. The dual-approval flow for amounts above `goodwill_four_eyes_threshold` requires a second admin's approval before the voucher row is committed.
- **Voucher transfer** modal (Admin only) — explicit reassignment between partners with audit. Transferring a customer-bound voucher requires source-partner consent capture (free-text note).
- **Customer-history search audit** — list view of `customer_history_searches` with cashier, terminal, partner, timestamps; alert badges.

### 7.2 POS Refund Policies settings page

`apps/web/src/features/settings/pages/PosRefundPoliciesPage.tsx`:

- Edits the §3.5 settings, written into `Company.reservation_settings` via `CompanyController` (extends existing endpoint).
- Settings are cached and read by POS at session-open; cache invalidated on save.

## 8. Migrations summary

1. `create_vouchers_table` — voucher entity (with `redemption_mode`, `issued_to_partner_id`, audit fields).
2. `create_voucher_ledger_table` — append-only ledger.
3. `add_exchange_group_id_to_pos_receipts` — UUID + index.
4. `add_audit_fields_to_pos_receipts` — `authorized_by_user_id`, `override_reason`, `out_of_window`, `policy_trigger`, `refund_request_id`.
5. `add_audit_fields_to_payments` — `original_payment_id`, `refund_request_id`, `authorized_by_user_id`, `policy_trigger`.
6. `add_eco_tax_fields_to_pos_receipt_lines` + `add_eco_tax_fields_to_document_lines` — Phase-2 forward compatibility.
7. `rename_voucher_serial_to_instrument_serial_on_pos_receipt_payments` — rename + add `instrument_type`, backfill legacy data.
8. `add_voucher_payment_method_seed` — per-tenant seeder (ran via existing tenant-bootstrapping pipeline).
9. `extend_company_reservation_settings_for_refund_policy` — data migration that adds defaults to existing rows.
10. `create_customer_history_searches_table` — privacy audit log.
11. `bump_pos_receipt_hash_schema_to_v3` — schema-version flag in payload normalizer; data-only, no column.
12. `extend_z_report_schema_to_v3` — payload-level; no column.
13. (H3 — already landed on `dev` 2026-04-28) — `Nf525DataProviderContract` + DTOs exist with reserved extension points. Refund flow does not modify any of these; it populates the reserved nullables in the POS provider. Compliance-side XML emit logic for new blocks is a Compliance-team follow-up, not part of refund-flow PRs.

## 9. Tests (PHPUnit + Vitest)

### Backend — Unit / Feature

- `Voucher/VoucherIssuanceServiceTest`: issue from refund, exchange surplus, goodwill; expiry default; tenant + company scoping; ledger row written; GL entry id present; audit fields propagated.
- `Voucher/VoucherRedemptionServiceTest`: full redeem, partial redeem, expired rejection, voided rejection, insufficient balance, customer-bound mismatch rejection, duplicate-in-transaction rejection, rate limiter trip.
- `Voucher/VoucherCascadeServiceTest`: void unredeemed cascades to voucher void; void with redemptions blocks with explicit error.
- `Voucher/VoucherCurrencyScaleTest`: TND scale-3 partial redemption residual; EUR scale-2 partial redemption residual; voucher applied amount rounded correctly.
- `POS/ReceiptLookupServiceTest`: QR token verification (valid, tampered, wrong tenant), ticket number, customer-history with permission window enforcement, masked-totals default, audit row written.
- `POS/ReceiptFinalizationServiceTest`: hash includes payment rows, instrument_serial, voucher_ledger redemption rows, exchange_group_id; pre-change cash-sale parity (golden hashes).
- `POS/ExchangeServiceTest`: net positive, net negative → voucher, net zero, both halves chain correctly, `exchange_group_id` committed to hash on both, transactional rollback on partial failure, idempotency on `exchange_request_id`, same-SKU return+sale stock movements both written.
- `POS/CustomerHistorySearchPrivacyTest`: minimum specificity rejection, rate limit trip, masked totals, alert event emission, company-scoped default.
- `Treasury/PaymentRefundServiceProrationTest`: proportional, largest-first, cashier-choice; idempotency; residual-to-last; TND vs EUR rounding.
- `POS/ReceiptReturnServiceTest` extensions: return-window enforcement, out-of-window voucher-only resolver, manager-override `max(flat, percent)` path, daily cap with manager extension, audit field write-through, voucher-cash-refund forbidden by default + permitted when both setting and permission align.
- `POS/Settings/PosRefundPoliciesTest`: defaults, owner-edit, multi-tenant isolation, `Company.reservation_settings` reuse (no parallel storage).
- `Fiscal/VerifyChainTest`: chain integrity across exchanges + voucher flows; v2-to-v3 transition golden replay test.
- `POS/ZReportTest`: schema-v3 sale-vs-return split, voucher counters present, normalize-for-hash extends to new keys, v2 reports still verify.

### Frontend — Vitest

- POS: `ReturnsHome.test.tsx`, `ReceiptScanner.test.tsx`, `RefundDestinationPicker.test.tsx` (resolver-driven), `ExchangeMode.test.tsx` (draft resume), `VoucherTender.test.tsx` (stacking + duplicate guard), `MaskedTotalsCustomerHistory.test.tsx`.
- Web: `VoucherListPage.test.tsx`, `IssueGoodwillVoucherModal.test.tsx`, `VoucherTransferModal.test.tsx`, `PosRefundPoliciesPage.test.tsx`, `CustomerHistorySearchAuditPage.test.tsx`.

### E2E (Playwright if available, else manual scripts)

- Happy: scan receipt → partial refund → cash; exchange with net pay; exchange with voucher surplus.
- Edge: out-of-window with voucher-only policy; manager override over `max(flat, percent)` threshold; daily cap reached → manager extension; voucher cross-terminal redemption inside same tenant.
- Negative: expired voucher rejected; voided voucher rejected; reusing single-use voucher in same transaction rejected; customer-bound voucher rejected for wrong customer; broad customer-history search triggers alert; voucher-to-cash refund denied when policy disallows.

## 10. Release gates and known gaps

### 10.1 NF525 editor attestation — tracked follow-up, NOT a release gate

The v3 hash payload + JET export field changes alter the "securing functions" (BOFiP BOI-TVA-DECLA-30-10-30 §339-347 inalterability/security/conservation/archiving). The editor attestation pack must be updated to cover the new behaviors.

**Project status (2026-04-28):** we have no production France tenants yet. The system is in active development, and the owner has explicitly accepted that fiscal-chain modifications (NF525 etc.) are acceptable at this stage.

**Phase 1 ships v3 for everyone, including France tenants.** No `pos_refund_v3_france_enabled` feature flag. Per-terminal Z-close cutover (§5.4) applies uniformly.

**Tracked follow-up tasks** (post-Phase-1, before first France production tenant):
- Update editor attestation to cover: v3 canonical hash payload (§5.0), voucher liability ledger + accounting matrix (§5.2), new return audit fields + exchange-group cryptographic linking, Z-report v3 schema fields.
- Bump the software-version string embedded in the JET export header.
- Re-validate JET fixtures against the new field set and submit to the certifying body if/when applicable.

If we onboard a France tenant before the attestation is updated, this becomes a release gate and we revisit; until then it's a backlog item, not a Phase 1 blocker.

### 10.2 Known gaps explicitly NOT addressed in Phase 1

- **Void across Z-close.** Today's `ReceiptVoidService` is a status flag; voiding after the daily Z-close does not produce a chain-aware reversal. Phase 1 documents this as a known limitation; future work to model fiscal-grade post-close cancellation.
- **EU e-invoicing / Peppol / UBL.** Separate session.
- **Two-person rule for very high-value refunds.** Manager override is single-person. Acceptable for retail; revisit if a tenant requests.
- **System-correction refunds (no customer interaction).** Out of scope.
- **Receipt-printer template overhaul.** Spec assumes existing printer pipeline can render new fields and a QR; if it can't, a small printer-template task is added during planning.
- **Phase-2 eco-tax pro-rata reversal computation.** The fields are persisted by Phase 1; the reversal logic ships in Phase 2.
- **Restaurant-ticket tender (Sodexo / Up Déjeuner / Edenred / Pluxee / Restaurant Pass / etc.).** Phase 1 reserves the `restaurant_voucher` discriminator value but does not implement issuance/redemption (§3.2.1). Phase 2 builds `RestaurantTicketTenderService` with per-issuer settlement-provider abstraction, per-country compliance (FR Code du travail Art. R3262, TN/EU equivalents), settlement batch + fees ledger, and a separate POS tender button. Distinct accounting from store vouchers (third-party-issued cash-equivalent, not our liability).

## 11. Phase 2 preview (automotive — Otospex)

- **Cores / `consigne`** — separate inventory/deposit workflow with a physical-part receipt and condition checks. Voucher entity is **not** extended for cores (F29). Cores may optionally pay out via voucher when the deposit is reclaimed.
- **Eco-tax / `éco-participation` pro-rata reversal** — uses §3.7 line fields written in Phase 1. No data migration needed at Phase-2 start.
- **Warranty redo / `reprise sous garantie`** — zero-charge return tied to a workshop job. Depends on workshop module.
- **Installed parts non-returnable** — product flag + workshop job linkage. Additive.
- **Supplier returns** — B2B credit note, not customer refund. Out of scope of this whole flow.

## 12. Decisions to confirm before plan writing

Carried over from review 1 (some now updated by review 2):

1. **§3.1 voucher entity model:** confirm one table with `redemption_mode` discriminator (vs. two tables, bearer + customer-bound). Spec assumes one.
2. **§3.5 settings storage:** confirm extension of `Company.reservation_settings` (vs. new `pos_refund_settings` table). Spec assumes extension.
3. **§3.2 PaymentMethod, not PaymentType:** confirm voucher is a `PaymentMethod` row, not a Treasury `PaymentType` enum case. Spec assumes this.
4. **§3.5 `voucher_cash_refund_allowed` default:** confirm `false`. Spec assumes this.
5. **§4.1 receipt finalization rewrite:** confirm willingness to absorb the blast radius — including the offline path (Tauri client + ReceiptSyncService) and the per-terminal Z-close cutover gate. Spec assumes yes.
6. **§5.4 schema v3 cutover policy:** per-terminal at next Z-close, no mid-shift mixed-schema reporting. Confirm.
7. **§4.8 H3 dependency:** RESOLVED — landed on `dev`. No further sequencing.
8. **§3.3 `instrument_serial` rename:** rename + backfill (vs. add a new column and deprecate). Spec assumes rename + backfill.
9. **§2.6 / §3.5 customer-history defaults (Codex review 2 finding M):** 15/day per cashier, 3 rejected-specificity/hour, 8 same-partner/day, immediate cross-company alert. Confirm.
10. **Manager override threshold defaults:** flat 50.00, percent 10%. Per-tenant setting; confirm defaults are acceptable for both EUR and TND tenants (TND is roughly 1/3 of EUR — flat 50 may be too high in TND).

New decisions surfaced by Codex review 2:

11. **§5.0 canonical hash format:** RFC 8785 / JCS canonical JSON for v3 (vs. positional pipe serialization). Spec assumes JCS. Confirm — this locks the byte format for both PHP and TypeScript.
12. **§5.2 voucher GL routing:** voucher redemption uses a new `createVoucherLedgerEntry` method, NOT `createPOSPaymentEntry`. Confirm we're willing to refactor `ReceiptFinalizationService` to skip the voucher tender in the per-payment GL loop.
13. **§5.5 voucher residual policy:** internal precision = currency_scale + 2; sub-minor-unit residual triggers `RoundingAdjustment` ledger event with GL write-off. Confirm vs. alternative: forbid sub-minor balances entirely (rounded at every redemption to currency_scale) — simpler but loses customer value.
14. **§4.5 QR token rotation:** two active keys per tenant (`current` + `previous`); rotation is a manual admin action, not auto-cadenced. Confirm.
15. **§4.5 / §10.1 cross-tenant scan policy:** receipt printed by tenant A scanned at tenant B returns generic "invalid token" without leaking that the kid exists elsewhere. Confirm.
16. **§4.7 voucher rate limiting:** layered per-terminal/cashier/tenant/IP/code-prefix/voucher counters; balance hidden until in-session. Confirm thresholds (200/100/50-soft/200-hard/300/30/5-auto-void).
17. **§3.5 / §3.8 goodwill voucher controls (Codex review 2 finding I):** named-customer threshold 100, four-eyes threshold 250, daily cap optional, bearer goodwill default off. Confirm thresholds.
18. **§3.1 / §4.9 credit-note void with redeemed vouchers:** Phase 1 hard-blocks with a runbook reference. Phase 1.1 adds the in-system compensating-correction workflow. Confirm we accept the hard-block in Phase 1.
19. **§10.1 NF525 editor attestation:** RESOLVED — owner accepted that we ship v3 for everyone (including France) at this development stage. Editor attestation is a tracked follow-up, not a release gate. Re-evaluate before onboarding the first France production tenant.
20. **§3.7 eco-tax (Codex review 2 finding N):** Phase 1 ships columns + DTO nullables only; writer integration is Phase 2. Confirm — alternative is to wire the writer in Phase 1 (more scope, but the data is "lossless" from day 1).

If any of §12 surfaces a blocker, the plan raises it explicitly rather than guessing.

---

**End of revised spec.** A separate implementation plan (`docs/superpowers/plans/2026-04-28-pos-refund-flow.md`) will decompose this into TDD-style bite-sized tasks once the §12 decisions are confirmed and §4.8 H3 sequencing is resolved.
