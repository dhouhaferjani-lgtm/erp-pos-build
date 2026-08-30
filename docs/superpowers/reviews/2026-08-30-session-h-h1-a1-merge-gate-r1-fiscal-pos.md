<!-- fiscal-pos-reviewer (Claude), merge-gate round 1, lane fix/h1-a1-buyer-block @ ed74968cd, dispatched by Session H 2026-08-30 -->

# Merge-gate register — lane H1-a1 (`fix/h1-a1-buyer-block`)

| | |
|---|---|
| **Branch** | `fix/h1-a1-buyer-block` (worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/h1-a1-buyer`) |
| **HEAD at review** | `ed74968cd` — *"h1-a1 M5.4-state: close session H phase one"* (advanced past the `5d5fa7151` in the task brief by `8518e7cd1` LANE-REPORT + `ed74968cd` YAML `status: passed`; both docs-only, verified with `git show --stat`) |
| **Range** | `dev...HEAD`, merge-base `23b1b8a65` (= the brief's `base_sha`; `dev` itself has since moved to `cff5e9620`, so the three-dot base is stable) |
| **Lens** | `fiscal-pos` (first Claude review; M4/M5 registers were lane-authored) |
| **Ran** | `git diff/show/log`, `grep`, `python3 hashlib` on both golden fixtures. **No PHPUnit, no PG, no POS vitest** (parent's runs taken as given: 203/203 PHP, 1051 vitest). |
| **Production files touched** | `FiscalPayloadConstraintValidator.php`, `PosCoreReceiptProjection.php`, `PartnerService.php`, `PartnerServiceInterface.php`, `FiscalEventEngine.ts`, `SaleReceiptPayload.ts`, `SaleReceiptV5Payload.ts`, `receiptService.ts`, `paymentStore.ts` |

---

## Findings

### 1. [MAJOR] `buyer.contact_id` is still written raw into the `pos_receipts.contact_id` uuid FK — the sibling the lane hardened
`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:380,411` (and `:1712` for the loyalty context)

`customer_id` now goes through `resolveBuyerPartnerId()` (`:1672-1682`, `Str::isUuid` + scoped partner probe), but `contact_id` is assigned straight from the sealed payload and inserted into a `foreignUuid(...)->constrained('contacts')` column (`apps/api/database/migrations/tenant/2026_03_10_100003_add_contact_id_to_pos_receipts.php:17-21`). The validator accepts an **arbitrary non-empty string** for `contact_id` at *every* version including v5 — `$nullableStringFields = ['codice_fiscale', 'contact_id', 'customer_id']` (`FiscalPayloadConstraintValidator.php:2337`), no uuid gate anywhere. The repo already contains a payload of exactly that shape: `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-15-large/payload.json` → `"contact_id": "contact-f15-001"`.

*Why it matters:* such a receipt does not "land with a null FK and still project" — the PG insert raises `22P02` / FK violation, the projection job fails, and the sealed sale never becomes a `pos_receipts` row (no GL, no stock). This directly contradicts the new docblock the lane wrote at `PosCoreReceiptProjection.php:92-99`: *"Missing, legacy, malformed, or out-of-scope candidates land with a null partner FK while the sealed snapshot still projects."* Reachability today is low (the device seals `contact_id: null` by decision, and `grep -rn "'buyer'" apps/api/app` finds **no** server-side buyer author — only DTO read/write in `SaleReceiptPayload.php:129,208` / `AccountChargePayload.php:106,159`), but the guarantee the diff asserts is not the guarantee the code provides.

*Fix:* mirror `resolveBuyerPartnerId` for contacts (uuid + tenant/company-scoped existence probe via a Shared contract, else `null`), pass the guarded value to both the insert row and `SaleEarnContext(contactId: …)` at `:1712`; or, if deferring, correct the docblock to say the safety property covers `partner_id` only and record the residual in `owes_parent`.

### 2. [MAJOR] The non-empty `buyer.name` rule is NOT version-gated, unlike the `customer_id` rule the M4.0 ruling authorised
`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2337-2356` · device twin `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2757-2769`

`name` is removed from the nullable set for **every** SALE_RECEIPT version (the gate is `$saleReceiptEventVersion !== null`, which is true for v1 as much as v5), while `customer_id` correctly gates on `>= SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION` (`:2361`, const = 5 at `:433`). The M4.0 ruling grandfathered v1–v4; the brief's one-liner *"`name` required non-empty string when buyer is an object"* is the only authority for the wider scope, and it predates the ruling.

*Why it matters:* this validator is **not** ingest-only — `StrictCanonicalParser::parse()` re-parses **stored** bytes for `VerifyEventChainCommand.php:619` and `ParseFailureResolutionService.php:335`. Any historical v1–v4 sealed receipt with a buyer object and `name: null` would flip from verified to `canonical_parse_failure` on re-verification. No test covers v1–v4 buyer-name behaviour (the four new name/uuid tests are all `eventVersion: 5`; `FiscalPayloadConstraintValidatorTest.php` additions), and `grep -rn -A3 "'buyer' => \[" apps/api/tests | grep "'name' => null"` returns nothing, so nothing in-repo proves the direction is safe.

*Fix (cheap, no loss):* add `&& $saleReceiptEventVersion >= self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION` to the name check on both sides — the device only authors v5, so the intended guarantee is unchanged — **or** add an explicit v1/v4 "buyer with null name still validates" test and a parent ruling line recording the deliberate widening.

### 3. [MINOR] The "quarantine" outcome is stronger than the diff's wording — document it
`apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:793-805,987-990` (see explicit answer to lens 4 below). The projection's defensive `partner_id = null` only rescues values that **pass** the validator; anything the validator rejects loses the whole projection, not just the buyer. The docblock at `PosCoreReceiptProjection.php:92-99` and the lane report both read as if the softer outcome always applies.

### 4. [MINOR] The resolver drops a known `tax_number` while still sealing the name
`apps/pos/src/lib/offline/receiptService.ts:160-166` — `tax_number: resolved ? customer.tax_number : null`. For an offline-created (alias-pending) B2B customer the device *has* the matricule fiscal / VAT the cashier typed, but seals `buyer.name` without it, so `pos_receipts.customer_identifier` is null on a receipt that names a business. This matches the brief's Flow 1 shape verbatim, so the lane complied — but it is a fiscal-content decision (claim the unverified name, drop the unverified number) that deserves an explicit owner line rather than inheriting from a brief bullet.

### 5. [MINOR] Blank-name / wrong-scope customer data hard-fails the whole checkout, in raw English
`apps/pos/src/lib/offline/receiptService.ts:143` (`throw new Error('Selected customer does not belong to the active tenant and company.')`) and `:164` (`name: customer.name` passed verbatim). `BuyerBlockInput.name` is typed `string | null` (`FiscalEventEngine.ts:296`) while v5 now demands non-empty, so a mirrored customer row with a blank/whitespace name makes `engine.append` throw *before* sealing and the sale cannot be completed. Failing closed before the seal is the right side of the trade (no chain hole), but the degradation should be `buyer: null` + telemetry, and the message must go through `t()` (rule 11) if it can reach a cashier via `formatCheckoutError`. Partially disclosed by the lane ("POS UX debt").

### 6. [MINOR] Newly-populated `pos_receipts.partner_id` activates several downstream surfaces that were dead while buyer was always null
`PosCoreReceiptProjection.php:1712` (loyalty accrual now fires with a real `partnerId`), `ReceiptReturnService.php:864` (return drafts inherit it) and `:919` → `VoucherIssuanceRequest(issuedToPartnerId: …)`, `ReceiptController.php:122` (`customer_id` receipt filter), `CustomerAnalyticsData.php:14` (`top_customers`). I verified the voucher path is **not** a regression: `VoucherIssuanceRequest.php:55` defaults `redemptionMode: RedemptionMode::Bearer` and the partner match is only enforced in `CustomerBound` mode (`VoucherRedemptionService.php:127-135`), so named refund vouchers stay bearer-redeemable. Only the loyalty consequence is test-covered (captured `SaleEarnContext` in `PosCoreReceiptProjectionTest.php`). Flag at promotion — this is a live points-liability change, not a shape-neutral one.

### 7. [MINOR] Second UUID regex on the device
`apps/pos/src/lib/offline/receiptService.ts:123` `UUID_PATTERN` duplicates `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1447` `LOWER_HEX_UUID` (one-surface-per-concept). Export and reuse the engine constant so the seal-time and validate-time definitions cannot drift. Both are non-global regexes, so there is **no** `lastIndex` statefulness bug.

### 8. [MINOR] Two buyer-blind authoring paths remain (disclosed by the lane)
`apps/pos/src/lib/offline/offlineCheckoutService.ts:114-145` builds `createOfflineReceipt` input without `customer` — currently **callerless** in production (`grep -rn "executeCheckout" apps/pos/src` finds only its own definition/comments), so dormant, not broken; and v4 refund authoring (`RefundReceiptV4Payload.ts`, `refundCheckoutStore.ts:118`) still seals no buyer, so a refund of a named B2B sale carries no buyer in its own bytes (the projected `pos_receipts` row still inherits `partner_id` from the original via `ReceiptReturnService.php:864`). Ensure both land in the Phase-2 backlog.

### 9. [MINOR] Live gate is local-only and mutates the demo tenant
`apps/web/e2e/session-h/m4-sale-receipt-buyer.spec.ts:13-18` skips in CI without `SESSION_H_API_BASE`; each run creates a partner and three terminals and writes immutable fiscal events into PharmaBio. Good assertions (`:374,389,408` — `stored: true` in all three cases, `exceptionClass: 'canonical_parse_failure'` + reason `toContain('payload_buyer_invalid')` for the pending id), but it is evidence, not a gate. Disclosed.

### 10. [MINOR] YAML/attribution mismatch on the M4/M5 reviewer
`docs/handoff/progress/session-h-phase1.progress.yaml` sets `reviewer_model: codex-fallback` on **M1 and M3 only**; M4 and M5 carry no override and therefore inherit the top-level `reviewer_model: opus`, contradicting the parent's premise that they were self-reviewed. The registers themselves read first-person-lane (`M4-round2.md` line 4: *"Amending authority applied: `.superpowers/sdd/…/task-4-brief.md`"*). Set the override on M4/M5 or record that this Claude pass is the gate of record.

---

## Verified OK

**Sealed bytes (lens 1) — clean.**
- The **entire** `FiscalPayloadConstraintValidator.php` diff vs `dev` is three hunks: a docblock, `validateBuyer($payload, $eventVersion)` at `:1413`, and the `validateBuyer` body. The v1 key-set relaxation that M4-round1 caught (`approval_references` dropped for v1) is **gone** from the cumulative diff — the frozen v1 receipt key contract is byte-identical to `dev`, proven by the diff itself, not by the register's claim.
- `BUYER_KEYS` (`FiscalEventEngine.ts:2107`) and `SALE_RECEIPT_PAYLOAD_KEYS_V5` untouched; `FiscalPayloadKeyDrift.test.ts` gained a comment only. No key-set change on any event type.
- `git diff --stat dev...HEAD -- apps/api/tests/Fixtures/Fiscal/sale-receipt-golden` is **empty** — F-07/F-15 bytes and hashes untouched, and `test_stale_f07_file_remains_byte_pinned_with_documented_27_key_drift` pins F-07's file hash `96e325ee…`.
- Null-buyer bytes unchanged: `buyer: input.buyer ?? null` (`SaleReceiptPayload.ts:155`, `SaleReceiptV5Payload.ts:133`) plus a new byte-stability test asserting `omitted === explicit null` (`SaleReceiptV1V2ByteStability.test.ts:88-95`). The v5 null golden `4343092a…` is untouched.
- I independently recomputed both fixtures with Python: same key set, **only `buyer` differs**, and each `expected_sha256_hex` is the true SHA-256 of its `expected_canonical_string`.

**Device resolver (lens 2).** `apps/pos/src/lib/offline/receiptService.ts:133-171` — `synced` → the mirrored `customers.id` only if it matches lowercase-hex uuid; `pending_create` → `getCustomerAlias(db, tenantId, companyId, customer.id)` (`pendingCustomerRepository.ts:187-206`, the canonical existing helper, no second resolver introduced) → `server_partner_id` only if uuid; otherwise `null`. A device-minted pending uuid is **never** sealed. Cross-scope customers throw before authoring (`:141-144`). Tests: `receiptService.test.ts` — mirrored uuid, alias hit, alias miss → null, uppercase uuid → null; module mocks for `terminalStateRepository`/`offlineReceiptRepository` mean the first `db.select` really is the alias query, so the `mockResolvedValueOnce` is not misdirected. Wiring: `paymentStore.ts:700,716`; the alias read is outside `withWriteTransaction` (`:488` vs `:650`), so no write-gate deadlock.

**Server validator (lens 3).** Version gate uses the shared const (`:2361`, `SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION = 5` at `:433`), threaded from the single dispatch site `:589`. ACCOUNT_CHARGE's reuse (`:1907`) passes no version and keeps its pre-M4 behaviour byte-for-byte. Error code is `payload_buyer_invalid:` on both sides. Tests cover uuid-ok / null-ok / non-uuid-reject / uppercase-reject at v5, plus `test_legacy_f07_builder_semantics_accept_non_uuid_buyer_at_v1` (F-07 with `cust-007` validates at `eventVersion: 1`) and the byte/hash pin. Device twin at `FiscalEventEngine.ts:2771-2781` runs **before** sealing (Step 1 `validateRequestPayload` at `:573`, canonical bytes not built until `:637`), so a bad buyer fails the checkout rather than the chain.

**Projection (lens 4 mechanics).** `partner_id` via `resolveBuyerPartnerId` → `PartnerServiceInterface::resolveScopedPartnerId` (`PartnerService.php:18-31`) with `withTrashed()` + `tenant_id` + `company_id` + `whereKey` and **no** `type` filter; bound at `AppServiceProvider.php:105`; `Partner` uses `SoftDeletes` and there is **no** `addGlobalScope` anywhere in `apps/api/app`, so the query needs no `CompanyContext` (rule 20 respected — event scope is passed explicitly). FK always satisfiable: uuid-syntax gate + existence probe, and the FK is `nullOnDelete`. `customer_name` / `customer_identifier` remain pure snapshot reads (`:378,381`). D16 is untouched and **extended** (`PosCoreReceiptProjectionD16Test.php:132-157` pins exactly one `partnerService->` call site and the two snapshot assignments); the forbidden-module set lost nothing. Projection tests call `app(CompanyContext::class)->clear()` **before** `apply()` in all five new/updated cases, and assert data meaning (legacy non-uuid → null FK + snapshot intact; **second company** partner → null FK; supplier-typed partner → FK kept; archived-after-seal partner → FK kept, with the loyalty `SaleEarnContext` captured through a real contract implementation, not a mock of the unit under test). No `new PosCoreReceiptProjection(` anywhere — all container-resolved, so the added dependency breaks no call site. No new write inside `apply()`; the resolver is a read, so the `pos_receipts.fiscal_event_id` idempotency anchor still covers everything.

**Golden fixture (lens 5).** Device `saleReceiptV5CanonicalParity.test.ts:17` and server `SaleReceiptV5GoldenParityTest.php:36` pin the **same** literal `02bbf732ede83ae131989df29db7deb651548704024527a43c24a86e186d4fef`; the device builds the payload and encodes it, the server re-encodes and re-hashes; the server test also runs `validatePayloadKeySet` + `validatePerEventConstraints` at `eventVersion: 5`, so the vector is non-vacuous against the M4 gate. Fixture carries a self-documenting `_comment` forbidding its use to re-pin F-07/F-15. The key-drift gate points at it in a comment.

**Lens 6 — rule 8:** no Event class in the diff; no rename/restructure/delete; the change is a value population on an existing key at the already-live `event_version = 5`. No parallel refund event invented.

**Lens 7 — device schema:** `apps/pos/src/lib/db/migrations.ts` is **not** in the diff; `customer_aliases` already existed (`migrations.ts:1220-1225`). No SQLite schema change, therefore **no POS device version bump is needed — I agree with the lane's assertion.** No `.toISOString()` bound into a SQLite time comparison anywhere in the diff (the only new SQL is `getCustomerAlias`, keyed on ids).

**Lens 8 — second-of-everything: N/A.** `pos_receipts` is a fiscal projection, not a code/SKU/number-keyed catalogue entity; the diff adds no table, no `unique([...])`, and writes no catalogue row — it only *reads* `partners` scoped by `(tenant_id, company_id)`. The convention's spirit is nonetheless honoured by `test_uuid_buyer_outside_event_company_lands_snapshot_with_null_partner_fk` (second company in the same tenant → FK refused).

**Lens 9 — `apps/web/e2e/money-campaign`:** the single change is `function canonicalEncode` → `export function canonicalEncode` (`statement-support.ts:404`), so the new M4 spec can reuse the campaign's canonical JSON encoder instead of writing a third one. No behaviour change, no other consumer affected — correct reuse.

**Precision (rule 19):** no float, no `parseFloat`/`Number()`, no `(float)`, no money/quantity arithmetic added anywhere in the diff. No new `onQueue(...)`, so no `horizon.php` coverage obligation.

---

## Explicit answer — lens (4), quarantine semantics

**A v5 SALE_RECEIPT whose `buyer.customer_id` is malformed is NOT rejected at ingestion, and it does NOT merely degrade the projection. The sealed event is stored; the whole payload is dropped from projection.**

Chain of evidence: `validateBuyer` throws → `StrictCanonicalParser.php:262-263` converts it to `ParseResult::failure('sub_array_shape:payload_buyer_invalid:…')` → `OutboxIngestor::deriveIntegrity()` maps `! $parseResult->ok` to `IntegrityExceptionClass::CanonicalParseFailure` with `payload = NULL` and `payload_parse_status = Failed` (`OutboxIngestor.php:793,800-805`) → the row is still INSERTed into `fiscal_events` under the class invariant *"The row is ALWAYS persisted somewhere … The device is NEVER blocked by a server-side anomaly"* (`:46-52`) → `dispatchProjections()` returns early for `CanonicalParseFailure` (`:987-990`), so **no `pos_receipts` row, no GL, no stock movement, no loyalty** for that sale until an operator repairs it through `ParseFailureResolutionService`. The lane's own live e2e confirms exactly this shape (`m4-sale-receipt-buyer.spec.ts:408-412`: `stored: true`, `exceptionClass: 'canonical_parse_failure'`, reason contains `payload_buyer_invalid`).

**Is that the safe choice? Yes — for the chain, and acceptably for the sale.** The hash chain is preserved (canonical bytes + `current_hash` + sequence stored; no gap, no server re-authoring of a device-signed fact), and the device is never blocked. It is safe for the sale too **because the device runs the identical gate before sealing** (`FiscalEventEngine.ts:573` runs `validateSaleReceiptPayload` at Step 1, before canonical bytes are built at `:637`), so a compliant device cannot emit such an event; only a buggy/forged/older-shape client can. The residual exposure is that the failure mode for a buyer-only defect is a **fully invisible sale**, not a receipt with a null partner — and that is precisely the outcome the M4.0 ruling's item 2 ("projection defensive") was written to avoid. The projection's defensiveness is a second line that only ever fires for values the validator **accepts** (legacy non-uuid at v ≤ 4, or a uuid that does not resolve in scope). Finding 3 asks only that this be stated where the code claims otherwise; finding 1 is the case where the same class of defect is *not* even caught by the validator and therefore hard-fails the projection job.

---

**What to fix before merge:** version-gate (or explicitly test + rule) the non-version-gated `buyer.name` requirement for v1–v4, and either guard `buyer.contact_id` the way `customer_id` is guarded or correct the `PosCoreReceiptProjection` docblock (`:92-99`) so it stops asserting a safety property that only `partner_id` has.

VERDICT: ACCEPT-WITH-CONDITIONS
