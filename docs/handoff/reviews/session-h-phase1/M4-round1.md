## M4 merge-gate register — lane H1-a1 (`fix/h1-a1-buyer-block`), round 1
Diff reviewed: `23b1b8a65..a59826588` (11 commits). Lens: **fiscal-pos**. Read-only; nothing modified.

**What I ran:** `git diff/show` across the range; `pint --test` on the four touched PHP files → `{"result":"pass"}`; `npx vitest run` on the five changed POS test files → **163 passed / 5 files**. PHPUnit was **not** executed (needs a live per-session PG leg per the standing rule) — all PHP claims below are code-verified, not run-verified.

---

### 1. P1 — CONFIRMED — production v1 key-set contract was loosened to make a self-authored test pass
`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:553-561` and `:1440-1442`

M4.6 (`5a4fbefd3`) added an unconditional grandfather: for `SALE_RECEIPT` + `event_version === 1`, a payload that omits `approval_references` has that key **dropped from the expected set**, and `validateSaleReceiptPayload` substitutes `[]` instead of calling `requireList`.

Nothing in the brief §3/M4, the M4.0 ruling, or the amending authority (none) authorizes touching the key set. The ruling explicitly scoped the tightening to `buyer.customer_id` and said fixtures are **not** touched. This goes the other way: it *relaxes* a frozen contract. The device declares the opposite invariant in the same wave's untouched code — `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1042` + `:1084` "the v1/v2 record stays frozen at 28 keys forever" — so server and device now disagree on the v1 SALE_RECEIPT key set. That divergence is precisely what the key-drift gate exists to prevent.

It was also **unnecessary**. `git show 5a4fbefd3` proves M4.3 already had the correct implementation and deleted it:
```
-        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];
+        $payload = json_decode($bytes, true, ...);   // stale 27-key on-disk file
+        self::assertCount(27, $payload);
+        self::assertNull($this->validator->validatePayloadKeySet(..., eventVersion: 1));
```
`GoldenFixtureBuilder::baseEnvelope()` emits `'approval_references' => []` (`tests/Helpers/Fiscal/GoldenFixtureBuilder.php:464`), so builder-built F-07 is 28 keys and validates at v1 **with no validator change**. The committed `F-07/payload.json` is a drifted artifact: F-15 (28 keys) and F-16 (33) are byte-pinned against their generators (`FiscalPayloadConstraintValidatorTest.php:1597, :2137`); F-01 and F-07 (27 keys each) are not, and before this commit **no test read `F-07/payload.json` at all**.

Failure scenario: a future v1 SALE_RECEIPT payload that genuinely omits `approval_references` now clears `validatePayloadKeySet` on the server and is rejected by the device engine — and the new test `test_legacy_f07_...:1383` pins that asymmetry as *expected*, so the next drift audit reads green. Fix: revert `:553-561` and `:1440-1442`, restore M4.3's `GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur']` form, and drop the `assertCount(27)`/`validatePayloadKeySet(..., 1)` assertions against the stale file.

**Bypass attempted and FAILED (severity honesty):** I could not turn this into a live ingestion hole. `StrictCanonicalParser.php:222-228` hydrates `SaleReceiptPayload::fromArray` (→ `FiscalPayloadArrayGuards::requireArray`, which throws on a missing key) *before* `validatePayloadKeySet`, so real ingestion still rejects with `schema_violation:` instead of `payload_missing_required:`. `ParseFailureResolutionService.php:316` is DTO-first too, and `VerifyEventChainCommand.php:610` is `DEPOSIT_RECEIPT`-only. This is a contract/doctrine regression, not a currently-exploitable bypass — but it is squarely outside M4 and it replaced working code, so it blocks.

### 2. P2 — CONFIRMED — archived (soft-deleted) and supplier-typed buyers silently lose their FK and their loyalty earn
`apps/api/app/Modules/Partner/Application/Services/PartnerService.php:18-31`, consumed at `PosCoreReceiptProjection.php:1670` and `:377/:1711`

`Partner` uses `SoftDeletes` (`app/Modules/Partner/Domain/Partner.php:88`), so `Partner::query()` applies the soft-delete scope; the resolver also filters `whereIn('type', [Customer, Both])`. The M4.0 ruling said "resolves to a partner scoped to tenant+company" — it did not add "not archived, customer-capable".

Failure scenario: cashier attaches customer X and seals an offline receipt; X is archived (soft-deleted) before the device syncs; the receipt projects with `partner_id = null`, so it never appears in X's purchase history, and `earnLoyaltyPoints` now receives the resolved `null` (`:1711`) instead of the sealed id, so the sale earns nothing. Pre-M4 the raw id was written and the row (still present under soft delete) satisfied the FK. Same shape for a `type = supplier` partner who buys at the till — pinned as *expected* by `test_supplier_only_uuid_buyer_lands_snapshot_with_null_partner_fk`. Fix: `->withTrashed()` and either drop the type filter or get it ruled.

### 3. P2 — CONFIRMED — the milestone's own gate evidence does not exist
- `docs/handoff/reviews/session-h-phase1/M4-round1.md` contains exactly `# REVIEW TOOL ERROR (milestone M4, round 1)` — no register (brief §4).
- `docs/handoff/progress/session-h-phase1.progress.yaml` still has M4 `status: in_progress`, `commit: null`, `verdict: null`.
- `.playwright-mcp/session-h/` does not exist; `docs/sessions/session-H-party-model-2026-08-29/` does not exist. The YAML's `browser_verification` line and brief §0.1 require screenshots cited in the register, and brief §4 requires the lane report.
- There is consequently **no evidence the M4 browser/API spec was ever executed** against :8011. The spec is well built, but an unrun spec is not the gate the brief asks for.

### 4. P2 — CONFIRMED — the e2e negative case does not assert the required failure reason
`apps/web/e2e/session-h/m4-sale-receipt-buyer.spec.ts:376`

Brief M4 gate item (iii): a non-uuid pending-style `customer_id` → **`payload_buyer_invalid`**. The spec asserts only `exceptionClass: 'canonical_parse_failure'`, which is the generic bucket — the assertion passes identically if the fixture is rejected for a typo'd `vat_breakdown` row, a bad `seller`, or any other parse failure, i.e. it does not prove the buyer gate fired. Either surface the reason via the quarantine/parse-failure read endpoint and assert on `payload_buyer_invalid`, or state in the register that the reason is not exposed and add the specific assertion at the PHPUnit HTTP layer (the brief explicitly allows that substitution — "do not skip the assertion").

### 5. P3 — CONFIRMED — the D16 grep guard was not extended to the new seam
`apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionD16Test.php:52-72` vs `PosCoreReceiptProjection.php:92-97`

The projector's docblock invariant was rewritten to permit a partner lookup, but the guard's pattern list still only forbids `App\Modules\Customer|Contact|B2B\`, `App\Shared\Contracts\Customer|Contact|B2B\`, and `Customer::`/`Contact::`. `App\Shared\Contracts\PartnerServiceInterface` matches none of them (I verified the `resolve-container-resolved` regex `/(?<!->)(?<!::)\bresolve\s*\(/` also does not fire on `resolveBuyerPartnerId(` or `->resolveScopedCustomerId(`). A later change that fed `customer_name` / `customer_identifier` from `$this->partnerService` — the exact regression D16 exists to stop — would now pass every guard. Add a positive-scope assertion (partner service may only produce `partner_id`) or a pattern pinning the single call site.

### 6. P3 — CONFIRMED — untranslated hard failure aborts checkout
`apps/pos/src/lib/offline/receiptService.ts:143` throws a raw English `Error` on a tenant/company mismatch, blocking the sale rather than degrading the buyer. Low reachability (`usePaymentStore` is not persisted — no `persist(` in `paymentStore.ts` — so a stale cross-company selection is unlikely), and it matches the raw-`Error` style already at `:475`. Note only; CLAUDE.md rule 11 if it ever surfaces to the cashier.

### 7. P3 — CONFIRMED — a second authoring surface still drops the buyer
`apps/pos/src/lib/offline/offlineCheckoutService.ts:124` calls `createOfflineReceipt` without `customer`. It has no production importer today (only `vi.mock` in `paymentStore.*.test.ts` and type-only imports in `buildReceiptData`), and all three live `createReceiptLocalFirst` call sites (`paymentStore.ts:1109, 1257, 1492`) go through the wired helper — so no live sale drops the buyer. Flag for `owes_parent` so it is not resurrected unwired.

### 8. P3 — red-first evidence not demonstrable
Tests and implementation land in the same commits (`5a21b0fa2`, `5023a833f`, `5a4fbefd3`), and with no register/lane report there is no record of a failing run preceding any fix. Not disqualifying on its own, but the brief's "Tests (red first)" wording is unevidenced.

---

### Checks that PASSED (recorded so the next round does not re-litigate them)
- **Null-buyer byte identity** — `buyer: input.buyer ?? null` (`SaleReceiptPayload.ts:155`, `SaleReceiptV5Payload.ts:133`); omitted vs explicit-null produce identical JSON, pinned by `SaleReceiptV1V2ByteStability.test.ts:88-95`. Verified green.
- **No new key** — buyer stays the same six-key block; `assertExactKeySetWithPath(buyer, BUYER_KEYS)` untouched on both sides; key-set constants unchanged.
- **`customer_id` is never a device UUID** — `resolveSaleReceiptBuyer` (`receiptService.ts:133-166`) takes `customer.id` only when `customer_sync_status === 'synced'`, and `'synced'` is produced exclusively by the server-mirror mapper (`CustomerAttachPanel.tsx:65`); locally minted rows are `'pending_create'` (`:176`) and resolve only through `customer_aliases` via the existing `getCustomerAlias` helper — no second resolver was added. The lowercase-only regex also rejects the uppercase form (`receiptService.test.ts` "never seals an uppercase mirrored UUID"). **Bypass attempted, FAILED.**
- **Version gate** — `SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION = 5` used on both sides; ACCOUNT_CHARGE keeps its pre-M4 nullable-`name` path via the `null` default (`FiscalPayloadConstraintValidator.php:1919`, `FiscalEventEngine.ts:2073`). PHP `/D` and JS `$` are both end-anchored.
- **Worker context (rule 20)** — the resolver takes `tenant_id`/`company_id` off the event, never `CompanyContext`; `Partner` has no company global scope (`Partner.php:181` registers only a `saving` guard); every new projection test calls `app(CompanyContext::class)->clear()` before `apply()`, and the pre-existing positive test was correctly migrated to v5 + cleared context (`PosCoreReceiptProjectionTest.php:1455-1469`) — so the "real scoped partner uuid → `partner_id` set" leg of the M4.0 ruling **is** covered.
- **DI** — `PartnerServiceInterface` constructor-injected; binding exists (`AppServiceProvider.php:105`); no `app()` in production code.
- **Rule 19** — no money/quantity path touched; no float, no scale resolution in the diff. **Lens N/A.**
- **Migrations / queues / device version** — none added; no schema change, so the brief's "no POS device version bump needed" reading is correct.
- **Pint** clean on all four touched PHP files.

**Blocking set: #1 (revert the key-set relaxation, restore M4.3's builder-based test), #2 (`withTrashed` / type-filter ruling), #3 (produce the register + YAML + browser evidence), #4 (assert the actual failure reason).**

VERDICT: CHANGES-REQUIRED
