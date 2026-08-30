## M4 merge-gate register — lane H1-a1 (`fix/h1-a1-buyer-block`), round 2

Diff reviewed: `23b1b8a65..b4210a155` (16 commits, 4 of them the round-1 fix set). Lens: **fiscal-pos**. Read-only; nothing modified.
Amending authority applied: `.superpowers/sdd/CODEX-DISPATCH-session-H-phase1-2026-08-29/task-4-brief.md` (parent M4.0 ruling; register/YAML explicitly the controller's job, execution evidence in scratch `task-4-report.md`).

**What I ran:** `git diff/show` across the range; `npx vitest run` on the five changed POS test files → **164 passed / 5 files**; `pint --test` on all seven touched PHP files → `{"result":"pass"}`. PHPUnit was **not** executed (needs a live per-session PG leg — standing rule); PHP claims are code-verified, with the lane's own PG run (`autoerp_test_h`: validator 160/347, projection 35/191, D16 3/8) taken as reported, not re-run. Screenshot evidence read directly (`.playwright-mcp/session-h/m4/signed-buyer-ingestion-summary.png`).

---

### Round-1 blocking set — all four resolved (verified in code, not from the report)

1. **#1 v1 key-set relaxation — REVERTED.** `grep -n approval_references` on the validator shows `requireList($payload, 'approval_references')` unconditional at `FiscalPayloadConstraintValidator.php:1430`, and the cumulative `23b1b8a65..HEAD` validator diff contains **only** the version-gated buyer changes plus a docblock. `validatePayloadKeySet` (`:545-570`) has no SALE_RECEIPT/v1 branch. The three replacement tests are non-vacuous and split the concerns correctly: `test_stale_f07_file_remains_byte_pinned_with_documented_27_key_drift` (hash pin only, no validation), `test_legacy_f07_builder_semantics_accept_non_uuid_buyer_at_v1` (builder-built 28-key F-07 validates at `eventVersion: 1` **with** `cust-007`), and `test_v1_sale_receipt_requires_approval_references_key` — which now pins the *strict* direction the device declares. Server and device agree on the v1 key set again.
2. **#2 archived/supplier buyers — FIXED.** `PartnerService::resolveScopedPartnerId` (`app/Modules/Partner/Application/Services/PartnerService.php:18-31`) uses `withTrashed()` and has no `type` filter; scoping is `tenant_id` + `company_id` + `whereKey`. Two new red-first-reported projection tests pin both cases *and* the loyalty consequence via a captured `SaleEarnContext` (`PosCoreReceiptProjectionTest.php:1370-1424`) — `assertSame($supplier->id, $this->capturedEarn?->partnerId)`. FK target is `partners` with `nullOnDelete` (`2026_03_09_100000_add_partner_id_to_pos_receipts.php:21-25`), so a soft-deleted row still satisfies it.
3. **#3 gate evidence — SATISFIED for the lane's remit.** `M4-round1.md` is committed (`802549304`); the screenshot exists and I read it — it shows `partner_id=__null__` for the anonymous receipt, `partner_id=01a05120-…` for the scoped one, the sealed (deliberately different) `customer_name`, and the quarantine reason. The stale YAML (`M4 status: in_progress / CHANGES-REQUIRED`) is **not** a lane defect: the amending brief line 28 assigns progress YAML and registers to the controller.
4. **#4 e2e negative assertion — FIXED.** `m4-sale-receipt-buyer.spec.ts:409-412` now polls `GET /fiscal/dead-lettered-projections/{id}` and asserts `integrity_exception_reason` `.toContain('payload_buyer_invalid')`, on top of the generic `canonical_parse_failure` class. No production endpoint was added — `quarantineReason` (`:330-346`) also rejects a wrong `source`.
5. **#5 D16 guard — EXTENDED.** `PosCoreReceiptProjectionD16Test.php:132-157` asserts exactly one `$this->partnerService->` occurrence, exactly one `resolveScopedPartnerId(` call, and that `$customerName`/`$customerIdentifier` remain direct `$buyer?->…` reads. That closes the seam the docblock rewrite opened.

---

### New findings, round 2

**1. P3 — CONFIRMED — the non-empty-name rule is NOT version-gated, so it tightens sealed v1–v4 too**
`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2352-2357` (mirrored `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2759-2765`)

`validateBuyer` is called with a non-null version for **every** SALE_RECEIPT (`:1413`), so `name` becomes required-non-empty at v1–v4 as well, where it was previously nullable. That is the same class of change the M4.0 STOP was raised over — but it is explicitly authorized by dispatch §3 M4 ("`name` required non-empty string when buyer is an object") and by amending line 21, and it is unreachable in practice: every pre-M4 builder hardcoded `buyer: null` (the diff at `SaleReceiptPayload.ts:155` / `SaleReceiptV5Payload.ts:133` is the first time a buyer can be non-null), and both golden fixtures carry names. Failure scenario if it ever bites: a legacy v1–v4 SALE_RECEIPT with `buyer.name = null` fails re-validation on the parse-failure correction path (`ParseFailureResolutionService.php:335`, which passes the event's own version — the version gate does not protect the name rule). Note for the parent; no change requested.

**2. P3 — CONFIRMED — `trim()` semantics diverge between server and device on the buyer name**
`FiscalPayloadConstraintValidator.php:2354` (`trim($name) === ''`) vs `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2760` (`buyerName.trim() === ''`)

PHP `trim` strips only `" \t\n\r\0\x0B"`; JS `String.prototype.trim` strips all Unicode whitespace. Failure scenario: a buyer name of `"\u00A0"` (nbsp only) is **rejected by the device authoring gate and accepted by the server**. Safe direction (server is the more permissive side, so nothing the device can seal is refused), no chain impact — recording it so a future "make the device match the server" change does not invert it.

**3. P3 — CONFIRMED — v4 refunds still seal `buyer: null`**
`apps/pos/src/lib/offline/refundReceiptService.ts` (no `buyer`/`customer` reference in 322 lines), reached from `refundCheckoutStore.ts:1860`

`RefundReceiptV4Payload` composes over `buildSaleReceiptV3Payload` → `buildSaleReceiptPayload`, so it inherits the new optional field but nothing passes a customer. Failure scenario: a refund against a sale that carried a partner projects `partner_id = null`, so the customer's receipt history and `/pos/analytics/customers` show the sale but not its reversal. Outside the brief's named call sites (`receiptService` / `paymentStore.createReceiptLocalFirst`) and hydrating the buyer from the original is a design decision the lane has no authority for — `owes_parent`, alongside the already-recorded `offlineCheckoutService.ts:124`.

**4. P3 — CONFIRMED — the committed F-07 `payload.json` is now blessed as a permanently-drifted artifact**
`apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:1380-1394`

The new test pins the file's hash and asserts `assertCount(27, $payload)` + `assertArrayNotHasKey('approval_references', …)` — i.e. it records that the on-disk golden fixture does **not** satisfy the current 28-key v1 schema its own generator (`GoldenFixtureBuilder::baseEnvelope()`) produces. This is the correct handling under the ruling (fixtures untouched, sealed bytes preserved: `96e325ee…`), and it is strictly better than round 1's silent asymmetry — but the drift is now green-tested rather than ticketed. Regenerating the file is a sealed-bytes owner gate; parent should ticket it rather than let the next reader assume the file is canonical.

**5. P3 — CONFIRMED — the browser/API gate never runs in CI and mutates the local demo tenant**
`apps/web/e2e/session-h/m4-sale-receipt-buyer.spec.ts:13-16`, `:362-398`

`test.skip(!!CI && !SESSION_H_API_BASE)` means the gate is local-only evidence; each run also creates one partner and three terminals in the demo tenant via the real API. Acceptable for a milestone gate whose evidence is the screenshot + the recorded 1/1 live pass, but it will not protect the invariant on `dev`. The durable protection is the PHPUnit + vitest matrix, which does cover all three legs.

**6. P3 — note — controller state is stale at handoff.** `docs/handoff/progress/session-h-phase1.progress.yaml:` M4 still reads `status: in_progress`, `commit: a59826588`, `last_verdict: CHANGES-REQUIRED`, `fix_rounds: 1`. Per amending line 28 that is the controller's to update; it must be advanced to `b4210a155` before merge, and the M4/M5 lane report under `docs/sessions/session-H-party-model-2026-08-29/` is still owed at end of lane (M5 not started, so not yet due).

---

### Bypasses attempted that FAILED (severity honesty)

- **Skip the v5 UUID gate via a caller that drops the version.** `TerminalRegistrySnapshotService.php:363` and `VirtualAdminFiscalEventService.php:413` both call `validatePerEventConstraints` without a version (default `1`), but neither authors SALE_RECEIPT — VirtualAdmin says so in code (`:405-407`) and passes `1` explicitly to the key-set check. `StrictCanonicalParser.php:234`, `BestEffortPayloadParser.php:133` and `ParseFailureResolutionService.php:335` all thread the event's own version. No bypass.
- **Seal a device-minted UUID into `customer_id`.** `resolveSaleReceiptBuyer` (`receiptService.ts:133-166`) takes `customer.id` only when `customer_sync_status === 'synced'`; `'synced'` is produced solely by the server-mirror mapper (`CustomerAttachPanel.tsx:65`), locally created rows are `'pending_create'` (`:176`), and `CustomerSyncStatus` is exactly that two-value union (`paymentStore.ts:198`) so no third status can fall through. Pending rows resolve only via the canonical `getCustomerAlias` (`pendingCustomerRepository.ts:187-206`, tenant+company scoped). The lowercase-only regex also drops an uppercase mirrored id (pinned by a test). No second resolver was created.
- **Break `pos_receipts.partner_id` FK.** Legacy non-UUID (`Str::isUuid` gate, `PosCoreReceiptProjection.php:1674-1682`), cross-company UUID, supplier-typed, archived, and missing candidates are each covered by a runtime test; only an in-scope existing row is written.
- **Break construction via the new constructor arg.** No `new PosCoreReceiptProjection(` exists anywhere in `apps/api`; the projector is container-resolved and `PartnerServiceInterface` is bound at `AppServiceProvider.php:105`. `PartnerType` is genuinely still used at `PartnerService.php:77-93`, so M4.12's restored import is correct, not dead.
- **Find a legacy re-validation path broken by the tightening.** `VerifyEventChainCommand.php:619` is `DEPOSIT_RECEIPT`-only; nothing re-runs SALE_RECEIPT constraints over history except correction, which uses the event's own version.

### Re-confirmed passes (not re-litigated)
No new payload key (`BUYER_KEYS` untouched on both sides); null-buyer bytes identical (`SaleReceiptV1V2ByteStability.test.ts:88-95`, omitted vs explicit-null asserted equal — verified green); the four required device tests exist and are non-vacuous (mirrored / alias-resolved / unresolved-pending→null / uppercase→null); all five required PHPUnit legs present including the positive scoped-UUID FK case at v5 with `CompanyContext::clear()` (`PosCoreReceiptProjectionTest.php:1500-1512`); no fixture, migration, schema, queue, device-schema or AccountCharge production change (diffstat is clean of all of them, so the "no POS device version bump" reading holds); constructor injection only, no `app()` in production; **Rule 19 lens N/A** — no money or quantity path is touched by this diff; the only shared-file edit is `export function canonicalEncode` in `money-campaign/statement-support.ts` (visibility only, no behavior).

**Blocking set: empty.** Findings 1–6 are P3 notes for the parent's `owes_parent` list and controller state; none of them change code required for M4.

VERDICT: ACCEPT
