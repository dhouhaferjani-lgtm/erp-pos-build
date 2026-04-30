# POS Refund Flow — Codex Review 2

Date: 2026-04-28
Scope reviewed:
- `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md`
- `docs/sessions/2026-04-28-refund-vs-deferred-coordination.md`
- current repo services on local `main` plus local `dev` diff where available

## Verdict

**ship-with-changes** — the plan is directionally sound, but not safe to implement as written. The remaining blockers are mostly around the receipt-finalization rewrite and the stated H3 dependency state: the spec claims cash-sale hash parity that the current code cannot preserve if payment rows are newly sealed, and the coordination doc says the NF525 contract landed on `dev` while the local `dev` ref still lacks those files and still has direct Compliance → POS Domain imports. One-line summary: **do not start implementation until the hash-version/cutover design and the H3 branch state are corrected.**

## Findings

### A. Blocker — receipt-finalization parity claim is false as written

**Issue:** §4.1 says existing cash-sale callers can record payments and then finalize, while preserving the old hash for a one-cash-tender sale because the payload includes the same payment row content. Current receipts do not seal any payment row content. They seal `payment_methods_hash = hashPaymentMethods([])` before the receipt is inserted, then payments are inserted later. Adding payment rows to the sealed hash necessarily changes the hash input for otherwise identical sales.

**Evidence:**
- Spec claims old behavior is preserved with one cash tender: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:263` and `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:264`.
- Current creation path hashes an empty payment array before insert: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:478`, `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:480`, `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:549`, `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:563`.
- Current payment path appends rows after sealing: `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:190`, `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:199`.
- Current hash formatter only serializes `receipt_number|posted_at|total|currency|vat_breakdown_hash|payment_methods_hash`: `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:62`, `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:70`.
- Current receipt model says receipts are always sealed after creation: `apps/api/app/Modules/POS/Domain/Receipt.php:329`, `apps/api/app/Modules/POS/Domain/Receipt.php:333`.
- Current PostgreSQL trigger blocks non-void updates, so a draft-to-sealed transition needs an explicit trigger redesign: `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:154`, `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:174`.

**Proposed change:** Rewrite §4.1 to state that v1/v2 legacy receipts keep the empty-payment hash format, v3 receipts use a new canonical payload that includes payments/vouchers/exchange fields, and parity is only “legacy verifier still passes,” not “same cash sale produces identical new hash.” Add migrations for `pending_seal` semantics: nullable `fiscal_hash`, nullable/held `chain_sequence`, trigger allowance for a single `pending_seal → fiscalized` transition, and exclusion of pending receipts from fiscal exports/Z reports.

**Decision needed before implementation:** Decide whether v3 starts only for new receipts at a Z-close/closed-shift boundary, or whether the team is intentionally breaking hash equality for new cash-only receipts and documenting that as a schema-version cutover.

### B. Blocker — H3 dependency is not actually present in the reviewed `dev` ref

**Issue:** The coordination doc and spec say `Nf525DataProviderContract`, DTOs, and POS-side `Nf525DataProvider` landed on `dev`. In the local repo refs available for this review, neither current `main` nor local `dev` contains those paths, and the Compliance NF525 classes still import POS Domain models directly. Implementation that follows §4.8 will not compile on this checkout.

**Evidence:**
- Spec says H3 landed and refund flow only populates existing nullable DTO fields: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:27`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:319`.
- Coordination doc says the contract file and DTO namespace exist: `docs/sessions/2026-04-28-refund-vs-deferred-coordination.md:168`, `docs/sessions/2026-04-28-refund-vs-deferred-coordination.md:190`.
- Current Compliance controller still imports POS Domain types and hash services: `apps/api/app/Modules/Compliance/Presentation/Controllers/Nf525ExportController.php:9`, `apps/api/app/Modules/Compliance/Presentation/Controllers/Nf525ExportController.php:14`.
- Current JET export service still imports POS Domain types: `apps/api/app/Modules/Compliance/Services/Nf525/Nf525JetExportService.php:9`, `apps/api/app/Modules/Compliance/Services/Nf525/Nf525JetExportService.php:16`.
- Current XML builder still imports POS Domain types: `apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php:9`, `apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php:15`.
- `git ls-tree -r dev` returned no `apps/api/app/Shared/Contracts/Compliance/*`, no `Nf525DataProvider.php`, and no `Nf525*Data` DTO files in the local `dev` ref.

**Proposed change:** Update the coordination doc to reflect the actual merge state, or rebase this review/spec branch onto the H3 branch that really contains the contract. Do not let the refund-flow implementation assume those DTOs until `git ls-tree -r dev` proves they exist.

**Decision needed before implementation:** Confirm whether the authoritative integration base is local `dev`, remote `origin/dev`, or an unmerged H3 branch; then update §4.8 and the overlap matrix accordingly.

### C. Blocker — offline fiscal receipts are a separate finalization path and the spec misses it

**Issue:** The POS desktop offline path already computes a client fiscal hash with payment rows, but server sync ignores `offline_fiscal_hash`, recomputes a server hash with `hashPaymentMethods([])`, and then appends payment rows. The finalization rewrite cannot be only `ReceiptCreationService + ReceiptPaymentService`; it must cover `ReceiptSyncService` and the TypeScript hash algorithm, or online and offline chains will diverge further.

**Evidence:**
- Offline client hashes payment entries: `apps/pos/src/lib/offline/receiptService.ts:139`, `apps/pos/src/lib/offline/receiptService.ts:146`.
- TypeScript fiscal hash input includes `payments`: `apps/pos/src/lib/fiscal/hashService.ts:6`, `apps/pos/src/lib/fiscal/hashService.ts:13`, `apps/pos/src/lib/fiscal/hashService.ts:40`, `apps/pos/src/lib/fiscal/hashService.ts:52`.
- Sync DTO requires `offline_fiscal_hash`: `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php:51`, `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:70`.
- Server sync recomputes `paymentHash` over `[]`: `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:280`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:282`.
- Server sync creates payment rows only after fiscal hash/save: `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:329`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:333`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:372`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:383`.

**Proposed change:** Add `ReceiptSyncService`, `SyncReceiptPayload`, `SyncReceiptsRequest`, and `apps/pos/src/lib/fiscal/hashService.ts` to the blast-radius list and tests. Define whether the server trusts/verifies the offline hash or canonicalizes/recomputes it, but make both implementations byte-identical for v3 including payment order, null handling, instrument fields, voucher ledger rows, and `exchange_group_id`.

**Decision needed before implementation:** Choose the authoritative v3 hash implementation for offline receipts: server-recomputed with strict parity test vectors, or client hash accepted only after server independently verifies identical bytes.

### D. Major — v3 receipt hash canonicalization is under-specified

**Issue:** §3.4 and §5.1 say `exchange_group_id`, payments, voucher ledger entries, and audit fields are included, but they do not specify byte-level ordering, null sentinels, schema-version placement, or canonical JSON vs pipe serialization. The current service uses positional pipe serialization; `hashPaymentMethods()` sorts only by `payment_type`, which is insufficient for duplicate payment methods and voucher stacking.

**Evidence:**
- Spec only says the field is included “alongside existing fields”: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:181`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:183`.
- Spec lists v3 fields but not a canonical byte format: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:339`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:347`.
- Current receipt serialization is positional pipe-separated: `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:62`, `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:71`.
- Current payment hash sorts by only `payment_type`: `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:114`, `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:117`.
- Spec allows multiple vouchers per sale: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:103`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:104`.

**Proposed change:** Add a formal “Receipt hash v3 canonical payload” section. Use either RFC-8785-style canonical JSON or a fully specified ordered tuple list. Required details: field order, `null` token, decimal scale formatting per currency, timestamp timezone, stable line ordering, stable payment ordering, stable voucher ledger ordering, `exchange_group_id` lowercase UUID format, and test vectors shared between PHP and Tauri.

**Decision needed before implementation:** Choose canonical JSON vs positional serialization and lock it with golden PHP + TypeScript vectors before writing services.

### E. Major — voucher GL directions are incomplete and redemption likely double-credits revenue

**Issue:** The spec correctly moves voucher value into a liability ledger, but §5.2 only defines Issued and Redeemed and then says void/expiry are “similar.” Redemption says debit voucher liability and credit revenue/cash-clearing. If POS sale posting already credits revenue, voucher redemption must not also credit revenue or revenue is double counted. The existing POS payment GL path credits revenue for every tender, which is already too coarse for a voucher tender.

**Evidence:**
- Spec issuance direction: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:351`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:354`.
- Spec redemption direction: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:356`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:359`.
- Spec leaves void/expiry ambiguous: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:361`.
- Existing POS payment entry always debits repository and credits revenue: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:977`, `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:983`, `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1017`, `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1035`.
- `PaymentType::POS` remains incoming, while voucher is not real incoming cash: `apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php:24`, `apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php:81`.

**Proposed change:** Add a voucher accounting matrix for every ledger event:
- `Issued`: debit sales returns/refund clearing or AR reduction clearing; credit voucher liability.
- `Redeemed`: debit voucher liability; credit POS tender clearing, not revenue, unless the POS sale journal is explicitly reworked to debit voucher liability directly and credit revenue/VAT once.
- `Voided` before redemption: debit voucher liability; credit the same clearing account used at issuance.
- `Expired`: debit voucher liability; credit breakage/other income only if legal/accounting policy allows it, otherwise keep liability/manual review.
- `Reversed`: exact reversal of the referenced ledger event, with `reverses_voucher_ledger_id`.
- `Transferred`: zero-amount audit row unless jurisdictional escheatment/fees apply.

**Decision needed before implementation:** Decide whether voucher redemption participates in the existing `createPOSPaymentEntry()` path or gets a separate GL path that avoids repository cash/bank and revenue double-credit.

### F. Major — credit-note void with redeemed vouchers is a placeholder, not a workflow

**Issue:** §3.1 blocks voiding a source credit note if any issued voucher was redeemed and punts to an “explicit fiscal-reversal workflow.” That workflow is not defined anywhere. This is not a harmless Phase-2 edge case: Phase 1 introduces voucher issuance from refunds, so Phase 1 must define how to correct a misissued credit note after redemption.

**Evidence:**
- Spec says redeemed vouchers block and require explicit reversal: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:154`.
- Spec §4.9 repeats the block but does not define the workflow: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:331`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:333`.
- Known gaps leave post-Z void as future work: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:475`.
- Existing receipt void model is only a status transition exception in the immutability trigger: `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:156`, `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:171`.

**Proposed change:** Either remove “void source credit note after redeemed voucher” from Phase 1 operational support and hard-block with an explicit unrecoverable/manual-accounting message, or define the compensating fiscal workflow now: corrective sale/debit note, voucher ledger `Reversed` rows, GL reversals, manager permission, printed correction receipt, and JET export representation.

**Decision needed before implementation:** Decide whether Phase 1 supports fiscal correction after voucher redemption or explicitly declares it unsupported with a manual accounting procedure outside POS.

### G. Major — QR token verifier needs tenant/company/key semantics, not just signed payload

**Issue:** §4.5 includes `tenant_id` in the HMAC payload, but it does not specify how the verifier selects a key, how rotation works beyond “version tracked,” or how it rejects cross-tenant/company lookups. If a tenant A receipt is scanned at tenant B’s terminal, the only safe outcome is a generic not-found/invalid response after verifying against terminal B’s tenant/company context, not a global receipt UUID lookup.

**Evidence:**
- QR payload is defined as HMAC over `receipt_uuid|tenant_id|company_id|version` plus receipt UUID: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:303`.
- The lookup signature receives a `Terminal`, so it can bind to terminal tenant/company but the spec does not say it must: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:301`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:303`.
- Tests include “wrong tenant” but not key rotation or cross-company terminal mismatch: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:452`.

**Proposed change:** Define token format as `v:kid:receipt_uuid:mac`. MAC should cover a canonical JSON payload including `tenant_id`, `company_id`, `receipt_uuid`, `purpose=receipt_lookup`, and optionally `issued_at`. Verifier must select keys only from `Terminal.tenant_id`, fetch receipt by `receipt_uuid + tenant_id + company_id`, use constant-time MAC comparison, return the same error for wrong tenant/tampered/not-found, and support at least two active keys per tenant for rotation.

**Decision needed before implementation:** Decide where tenant QR signing keys and `kid` rotation state live, and whether old printed receipt QR tokens remain valid after rotation.

### H. Major — voucher brute-force limiter scope is too narrow

**Issue:** Voucher lookup is rate-limited per terminal per day. That is weak against cashier rotation, multiple terminals, and API-origin brute force. Per-terminal is useful for local device abuse, but it is not sufficient for bearer voucher codes with monetary value.

**Evidence:**
- Spec validates voucher by scanned/typed code and rate-limits per terminal: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:100`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:101`.
- POS lookup endpoint returns status/balance for a code: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:157`.
- Voucher code has tenant prefix plus 12 characters and a check digit, but enumeration defense still matters because balance/status leaks value: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:151`.

**Proposed change:** Keep per-terminal throttling, but add layered counters: per-tenant failed voucher lookups, per-cashier, per-IP/API token, per-code-prefix, and per-voucher failed attempts. Return generic lookup failures until redemption authorization succeeds; reveal balance only after a valid voucher passes all checks.

**Decision needed before implementation:** Decide whether balance/status lookup is allowed to ordinary cashiers before customer intent is established, or only inside an active payment/refund session.

### I. Major — goodwill voucher issuance lacks four-eyes controls and self-dealing prevention

**Issue:** §2.3 and §7.1 allow Manager/Admin back-office goodwill voucher issuance. §10 explicitly defers two-person control as a known gap. For high-value bearer vouchers, this is a fraud path: a privileged user can issue to themselves or bearer, then redeem with another cashier or terminal. This should be in Phase 1 because goodwill issuance is Phase 1 scope.

**Evidence:**
- Goodwill issuance is in Phase 1 trigger list: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:91`.
- Back-office modal allows manager issuance: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:417`.
- Permission grants `pos.issue_goodwill_voucher` to Manager/Admin: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:245`.
- Two-person rule is explicitly out of scope: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:477`.

**Proposed change:** Put goodwill vouchers behind a stricter policy than refund-generated vouchers: required named customer for amounts over threshold, no issuer-as-recipient, no issuer redemption, daily issuance caps, mandatory reason codes, and four-eyes approval above `max(flat, percent)` or an absolute tenant threshold. Bearer goodwill vouchers should default off.

**Decision needed before implementation:** Decide the high-value threshold and whether goodwill voucher approval is mandatory for all tenants or configurable with a safe default.

### J. Major — Z-report v3 cutover cannot happen mid-shift/mid-day without explicit aggregation rules

**Issue:** §5.4 says v2 and v3 chain verification will coexist, but §5.3 rewrites daily aggregation to emit a single v3 `report_data` shape. Current report generation aggregates all receipts in the shift window. If a tenant flips to v3 while a shift/day contains v2 receipts, the hash verifier may survive, but daily totals and schema semantics can mix old receipts that did not seal payment/voucher/exchange fields with new receipts that do.

**Evidence:**
- Spec says v3 extends Z report keys and normalizer: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:365`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:373`.
- Spec only tests v2 reports followed by v3 reports, not one report containing mixed receipt hash schemas: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:375`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:377`.
- Current report generation selects all receipts by `posted_at` in a period: `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:811`, `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:819`.
- Current loop counts every non-voided receipt as a sale and aggregates into one shape: `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:835`, `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:842`, `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:887`.

**Proposed change:** Gate v3 cutover at Z-close/closed shift only. Add a migration guard that refuses enabling v3 on any terminal with an open shift or un-Z-reported receipts. If mid-day cutover is required, report data must contain separate `schema_version_2_totals` and `schema_version_3_totals` sections and a deterministic combined normalization.

**Decision needed before implementation:** Decide whether v3 cutover is terminal-level at next Z-close or tenant-level at a calendar cutoff with mixed-schema reporting support.

### K. Major — voucher residual policy can create unrecoverable dust

**Issue:** §5.5 says voucher applied amount is rounded down and sub-cent/millime fraction remains in `current_balance`. If the voucher ledger stores scale-currency amounts, sub-minor-unit value cannot be represented. If it stores higher precision, the customer can be left with balances below the minimum redeemable unit. Across many partial redemptions this becomes operational dust and a liability cleanup problem.

**Evidence:**
- Spec leaves residual in `current_balance`: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:384`.
- Spec only promises TND/EUR partial redemption tests, not cleanup or write-off: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:385`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:451`.
- Current project already treats silent rounding loss as fiscal-risky in payment tolerance and fails loud when representability is lost: `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:122`, `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:131`.

**Proposed change:** Define voucher ledgers with internal precision at least scale+2, display/redeem at currency scale, and add a `RoundingAdjustment` or `ResidualWrittenOff` voucher ledger event. On final redemption, if remaining balance is below the minimum currency unit, either apply it to the last redemption with a rounding GL line or write it off to a configured rounding account with explicit audit.

**Decision needed before implementation:** Decide whether vouchers store internal precision above currency scale or forbid creating sub-minor-unit balances entirely.

### L. Major — NF525 certification/version impact is missing from known gaps and decisions

**Issue:** The spec changes hash payloads, finalization timing, JET export fields, Z-report normalization, and archive content. BOFiP currently says secured cash-register systems must satisfy inalterability/security/conservation/archive requirements, the relevant transaction/payment data and corrections must be traceable, and modifications that alter these securing functions affect the certificate/attestation basis. This is not an implementation blocker for code, but it is a France go-live blocker and should be a Phase 1 decision/gate.

**Evidence:**
- BOFiP current version is effective from 2026-03-25: https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant%3DBOI-TVA-DECLA-30-10-30-20260325 lines 58-59.
- BOFiP requires secured systems for B2C VAT cash-register recording and proof via certificate or editor attestation: same source lines 92-100.
- BOFiP says relevant data includes transaction details, payment data, correction traces, and integrity/traceability data: same source lines 172-184.
- BOFiP allows technical solutions but requires inalterability, security, conservation, and archiving: same source lines 196-202.
- BOFiP says changes that alter the securing functions invalidate the certification/attestation basis: same source lines 339-347.
- Spec changes the core hash payload and finalization semantics: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:255`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:267`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:337`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:347`.

**Proposed change:** Add a Phase 1 release gate: “France tenants cannot enable refund-flow v3 until the certificate/attestation version story is updated.” Add a software-version bump and archive/JET fixture versioning task to the implementation plan.

**Decision needed before implementation:** Decide whether this feature is behind a France-disabled flag until certificate/attestation materials are updated, or whether certification work is part of the same Phase 1 release.

### M. Major — customer-history alert defaults are weak and internally inconsistent

**Issue:** I would not accept the proposed defaults. `30/day` per cashier is permissive for a privacy-sensitive purchase-history tool in small retail. `10 broad/hour` is inconsistent because broad searches should be rejected by the minimum-specificity rule. `5 same-partner/hour` is noisy for legitimate troubleshooting and weak for slow abuse over a day.

**Evidence:**
- Minimum specificity rejects partial name and requires full phone/email/loyalty identifier: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:125`.
- Rate limit default is 30/day per cashier: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:126`.
- Alert defaults are 10 broad/hour and 5 same-partner/hour: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:129`.
- Settings repeat these defaults: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:202`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:203`.

**Proposed change:** Default to 15/day per cashier, 3 failed-or-rejected searches/hour, 8 same-partner/day, and immediate alert on cross-company search. Replace “broad/hour” with “rejected specificity/hour” because broad searches should not execute at all.

**Decision needed before implementation:** Choose privacy defaults before seeding tenant settings, because changing defaults later will create inconsistent tenant behavior.

### N. Minor — Phase-2 eco-tax fields will stay empty unless writers explicitly pass them through

**Issue:** The spec says Phase 1 persists eco-tax fields whenever upstream emits them, but current receipt creation builds an explicit whitelist of line attributes and will drop unknown fields. Nullable migrations will not break PHPStan by themselves, but the “lossless data without backfill” promise is not true unless POS/document writers are updated and fixtures default the new fields.

**Evidence:**
- Spec adds nullable eco-tax fields and says they are persisted when upstream emits them: `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:225`, `docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md:233`.
- Current `ReceiptCreationService` explicitly constructs line payloads and does not copy unknown tax metadata: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:306`, `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:324`.
- `ReceiptLine` fillable must also be extended or mass-assignment drops fields: `apps/api/app/Modules/POS/Domain/ReceiptLine.php` should be checked during implementation.

**Proposed change:** Either remove the “persisted whenever upstream emits” promise from Phase 1, or include explicit writer/model/resource/test updates that set `eco_tax_*` to null by default and pass through non-null values from the tax engine.

### O. Major — coordination overlap matrix is stale against current local refs

**Issue:** The matrix says H3 is landed and the only remaining overlaps are mostly mechanical. Against the local refs, H3 is absent, while `dev` contains changes in files the matrix discusses as already separable. There is also preflight drift: current `main` has `512M`, local `dev` has `2G`, reversing the doc’s session1 status implication.

**Evidence:**
- Coord doc says H3 landed and refund-flow is unblocked: `docs/sessions/2026-04-28-refund-vs-deferred-coordination.md:29`, `docs/sessions/2026-04-28-refund-vs-deferred-coordination.md:86`.
- Coord doc says net assessment only blocking overlap was H3 and it is resolved: `docs/sessions/2026-04-28-refund-vs-deferred-coordination.md:69`.
- Local diff versus `dev` shows H2 changes in `DocumentLine` and `CopiesDocumentData`: `apps/api/app/Modules/Document/Domain/DocumentLine.php:80`, `apps/api/app/Modules/Document/Domain/Services/Conversion/Concerns/CopiesDocumentData.php:127`.
- Local diff versus `dev` shows `scripts/preflight.sh` memory drift: `scripts/preflight.sh:28`.
- Local diff versus `dev` shows `packages/shared/types/generated.d.ts` drift around `CompanyFraudSettingsData`.

**Proposed change:** Regenerate the overlap matrix from `git diff --name-status <actual-base>` immediately before implementation planning. Treat H3 as unresolved until its files exist in the implementation base. Add `ReceiptSyncService`, POS fiscal hash TypeScript, and offline receipt repositories to the refund-flow touched-file list.

**Decision needed before implementation:** Pick the exact git base and rerun the overlap matrix; do not rely on the current coordination doc as authoritative.

## Additional notes

- The two-fiscal-document exchange model is defensible under BOFiP as long as both documents are inalterable, line-level, traceable, and printed/exported together for customer/operator clarity. BOFiP requires plus/minus correction operations rather than direct mutation; it does not prescribe a single customer-facing document format. The spec’s “printed ticket renders both halves on one page” should remain mandatory, not optional.
- Voucher liability as a real GL liability is the right shape, but only after the event accounting matrix is made explicit and redemption no longer double-counts revenue.
- `PaymentRefundService` currently creates refund payments without setting `payment_type`, so the DB default can make refunds look like `document_payment`; the proration extension should fix that while adding `original_payment_id` and `refund_request_id`. Evidence: `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:54`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:69`, `apps/api/database/migrations/2025_12_06_100002_add_payment_type_to_payments.php:15`.
