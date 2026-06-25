# SaleReceiptV3 — Conditional fiscal stamp duty in the immutable receipt chain

**Status:** Design locked (spec **v2**, post-Codex-review). Build DEFERRED.
**Date:** 2026-06-24 · **Spec revision:** v2
**Review addressed:** `docs/superpowers/reviews/2026-06-24-pos-sale-receipt-v3-stamp-duty-codex-review.md` (NEEDS-REVISION → revised; 1 BLOCKER + 6 HIGH + 5 MEDIUM, all folded in — see §14 changelog).
**Predecessor work (on `dev`):** tax-config correctness — receipt timbre gated off by default, effective-dating, manage UI, dead stamp-duty path removed.

---

## 0. TL;DR for the implementer

POS sale receipts must optionally carry a **fixed per-ticket fiscal stamp duty** (Tunisia: 0.100 TND), sealed into the immutable, device-authored, JCS + SHA-256 hash-chained `SALE_RECEIPT` fiscal event. This needs a new **`SaleReceiptV3`** payload version.

**Locked decisions:**
- The stamp is an **always-present `stamp_duty_amount` decimal** in V3, value **formatted at the receipt's `currency_scale`** (`"0.000"` for TND scale-3, `"0.00"` for scale-2, etc.) — NOT a hardcoded `"0.000"`, NOT an optional/omitted key.
- **All payload parse/validate/read/hash entry points become version-aware** *before* any strict key-set check. This is the load-bearing change (the Codex BLOCKER). V1/V2 never have the key and stay valid forever.
- The stamp sits **outside** VAT extraction, the VAT breakdown, and the discount base. New sealed identity: `subtotal + vat_total + stamp_duty_amount == total + transaction_discount_amount`.
- Stamp collected from the customer is a **government liability**, not revenue → a new `StampDutyPayable` GL account + a split POS-payment journal.

**Do NOT build** until a real *grande-surface* tenant needs it. Build it as the *only* fiscal-event-shape change in flight (parallelizes with non-fiscal work; serialize against any other canonical-payload / event-version change). **Do not seal the first production V3 receipt** until a real-terminal device→server round-trip + hash-verify smoke is green.

---

## 1. Why this exists (legal + product)

- Tunisia's **droit de timbre on tickets de caisse = 0.100 TND per ticket**, levied ONLY by *grandes surfaces commerciales*, multi-department stores under the DGE/DME directorate, and foreign-brand franchisees (CDET Art. 117-10° / 135 bis, LF 2022). **Per-document, flat, total-independent**, paid by the customer per ticket, **additional to** the 1.000 TND invoice timbre.
- An ordinary retailer / parapharmacy is in **none** of those categories and must NOT levy it — already handled: the `STAMP_FISCAL_RECEIPT` `tax_configurations` row is seeded `is_active = false` (`apps/api/database/seeders/TunisiaTaxConfigurationSeeder.php:98`), activatable per-tenant via the tax-management UI (`taxation.tax_configurations.manage`).
- This spec covers the remaining piece: **when a qualifying tenant activates it, the stamp is sealed on the POS receipt total** and flows correctly through hash-chain, projections, Z-report, GL, NF525 export, the printed ticket, and the offline device store.

**Out of scope:** LF 2026 tiered *invoice* stamps (1.5/2 TND, > 1500 m²) — an invoice-side, document-engine concern; effective-dating already supports it.

---

## 2. The architectural reality (verified 2026-06-24 on `dev`)

The POS sale is **device-authoritative**: the Tauri device computes the total (`apps/pos/src/stores/cartStore.ts:601`), builds the canonical payload (`apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:106`), JCS-encodes it (`apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts:34`) and SHA-256 hash-chains it on-device. The server stores bytes verbatim and only validates internal consistency + verifies the hash (`apps/api/.../HashChainIntegrityProvider.php:16`); it does NOT recompute. **Events are immutable forever** (CLAUDE.md rule 8).

Verified facts:
- Registry: `SALE_RECEIPT => [SaleReceiptPayload::class, 2]`, accepted `[1, 2]` (`FiscalEventPayloadRegistry.php:53`, `:105`).
- Aggregate invariant: `subtotal + vat_total == total + transaction_discount_amount` (`FiscalPayloadConstraintValidator.php:779`, `:866`; device `SaleReceiptPayload.ts:134`, `:193`).
- Key-drift gate exists, asserts a 28-key SALE_RECEIPT set (`apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts:7`, `:12`).
- **CRITICAL (Codex F-01):** the strict key-set is keyed per event TYPE, not version (`FiscalPayloadConstraintValidator.php:225`); `validatePayloadKeySet()` ignores `event_version` (`:316`); missing AND extra keys are both fatal (`:336`). `StrictCanonicalParser` extracts `event_version` but runs the key-set check **before** version dispatch (`StrictCanonicalParser.php:204`, `:226`, `:231`). So naively adding the key to the global set rejects all V1/V2; gating it rejects V3. **This must be made version-aware first (§4).**

---

## 3. The locked field shape

### 3.1 `stamp_duty_amount` — always present in V3, scale-aware zero

```
stamp_duty_amount: string   // currency-scale decimal; ALWAYS present in V3; "0.000"/"0.00"/… per currency_scale
```

**Rationale (unchanged, validated by review):** under JCS an omitted member and a present zero hash differently, so the choice is real. In our fixed-key-set + key-drift architecture, an always-present scalar keeps one invariant shape per version, extends the sealed arithmetic cleanly, and makes "no stamp due" an explicit auditable assertion — vs. Italy/FatturaPA's omit-when-not-due (which suits XSD optionality, not our JSON+JCS+key-drift model).

**Codex F-08 fix — scale-aware zero:** the validator allows currency scales `{0, 2, 3}` and validates money strings at the receipt's scale (`FiscalPayloadConstraintValidator.php:699`, `:754`). The zero sentinel MUST be formatted at `currency_scale` (use the existing currency-scale boundary formatter; never a literal `"0.000"`). The device and server must agree on the formatted string or the hash diverges.

### 3.2 What is NOT sealed

Only the **amount** is sealed (it affects total + chain). The human label ("Timbre fiscal"), legal reference, and originating tax-config code are rendered from config at print/report time, NOT sealed — as VAT seals `net/vat/rate` but not the tax's display name. (A future audit requirement for the config code in the chain would be a *further* version; do not pre-add.)

### 3.3 Aggregate invariant (Codex F-09 — now explicit and load-bearing)

```
V1/V2:  subtotal + vat_total                      == total + transaction_discount_amount
V3:     subtotal + vat_total + stamp_duty_amount  == total + transaction_discount_amount
```

The stamp is a **document-total addition the customer pays** — NOT VAT, NOT in the `vat_breakdown`, NOT in the discount base:
- Device VAT extraction stays on the gross line totals only (`cartStore.ts:144`); the stamp is added AFTER VAT extraction and AFTER discount.
- `vat_breakdown` sums unchanged: `Σ net == subtotal`, `Σ vat == vat_total`.
- Discount cap stays against the cart subtotal, excluding stamp (`cartStore.ts:624`).
- Device total computation order: `total = (subtotal_gross − transaction_discount) + stamp_duty_amount`. Specify and test this exact order so device and server agree to the last unit.

---

## 4. THE load-bearing change: version-aware payload handling (resolves Codex F-01, F-02)

Today key-set validation is type-scoped and runs before version dispatch. **Every entry point that resolves a key-set or hydrates a SALE_RECEIPT payload must become version-aware, and the version must be resolved before the strict key-set check.** Build this BEFORE adding the field anywhere.

Required changes (all verified as currently version-unaware):
1. **`FiscalPayloadConstraintValidator`** — replace the per-type `PAYLOAD_KEYS[SALE_RECEIPT]` with a `(type, version) → key-set` resolver. V1/V2 → current 28 keys; V3 → 28 + `stamp_duty_amount`. `validatePayloadKeySet()` must take `event_version`. The aggregate-invariant check (`:779`, `:866`) must branch by version (§3.3).
2. **`StrictCanonicalParser`** (`:204`, `:226`, `:231`) — pass the already-extracted `event_version` into the key-set validation; run version resolution before the strict check.
3. **`CanonicalPayloadReader::forSaleReceipt()`** (`CanonicalPayloadReader.php:78`) — version-dispatch hydration. V1/V2 hydrate the no-stamp shape; V3 hydrates with stamp. The historical-read path must NEVER require the V3 key. (Either a dedicated `SaleReceiptV3Payload` DTO, or a reader that injects the scale-zero default only for v3 — but never demands it of v1/v2.)
4. **`BestEffortPayloadParser`** (`:77`) and **`ParseFailureResolutionService`** (`:312`) — both use type-keyed key sets; make them version-aware too, or they break historical parsing/recovery.
5. **`FiscalPayloadKeyDrift` gate** (`FiscalPayloadKeyDrift.test.ts:7`, `:12`) — update to assert the **per-version** key-sets (V1/V2 = 28, V3 = 29) on both device and PHP, in lockstep.

> Recommended: a single shared `(type, version) → keys` source of truth that both `FiscalPayloadConstraintValidator` and `StrictCanonicalParser` (and the device key-set) read from, so they cannot drift. The registry already tracks accepted versions (`FiscalEventPayloadRegistry.php:105`); bump SALE_RECEIPT write version to 3, accepted `[1, 2, 3]`.

---

## 5. Full end-to-end touchpoint checklist (expanded for Codex F-03, F-05, F-06, F-07)

Order roughly device → seal → validate/parse → project → report/export → render. Each is a TDD unit. **Bold = added in v2 after the review.**

**Device (apps/pos, TS) — lockstep with PHP:**
1. `src/stores/cartStore.ts:601,624,642` — total calc + stamp term per §3.3.
2. `src/lib/fiscal/payloads/SaleReceiptPayload.ts:106` → V3 builder; the device key list in `src/lib/fiscal/FiscalEventEngine.ts:1032`.
3. `src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts` — per-version key-sets (§4.5).
4. **`src/lib/db/migrations.ts:99` + `src/lib/db/repositories/offlineReceiptRepository.ts:29` (Codex F-05)** — add `stamp_duty_amount` to the offline SQLite receipt schema + repo, so offline reprints/local mirrors/sync state carry it.
5. **Config sync (Codex F-03):** `src/lib/sync/syncService.ts` + `TerminalStateResponse` (`:117`) currently carry NO tax-config. Add a sync channel that pushes the active receipt-stamp config (amount + applicability + effective dates) to the device, plus a device-side tax-config repository. Device computes scale-zero when no active config. **This is net-new infrastructure, not a tweak.**

**Server (apps/api, PHP):**
6. `app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:45` (+ new `SaleReceiptV3Payload`) — V3 shape.
7. `FiscalEventPayloadRegistry.php:53` — write version → 3; accepted `[1,2,3]`.
8. `FiscalPayloadConstraintValidator.php` + `StrictCanonicalParser.php` + `BestEffortPayloadParser.php` + `ParseFailureResolutionService.php` + `CanonicalPayloadReader.php` — version-aware (§4).
9. `OutboxIngestor.php:165,379` — rehash/reparse path already verifies canonical bytes; ensure it routes V3 through the version-aware validator + the server-side applicability cross-check (§9 trust model).
10. `PosCoreReceiptProjection.php:185` — persist `stamp_duty_amount` to `pos_receipts` (new column, §6).
11. `ZReportProjection.php:120` + `GrandtotalService.php:117` — stamp as its own total bucket (§7-Z).
12. **NF525 export (Codex F-06):** `Nf525ReceiptData.php:38` DTO + `Nf525XmlBuilder.php:114` — add a stamp field; `Nf525DataProvider.php:1303,620` re-verifies canonical bytes + maps authoritative totals → must understand V3.
13. **GL (Codex F-04):** `SystemAccountPurpose.php:24` (+ TN chart of accounts) — add `StampDutyPayable`; `TreasuryReceiptBridge.php:414` → `GeneralLedgerService.php:1417` — split the POS-payment journal (§7-GL).
14. **Render:** `resources/views/pos/receipt.blade.php:419` (PDF) + `apps/pos/src-tauri/src/printing/receipt_template.rs:28` (thermal) — print the timbre line.
15. **Web-admin (Codex F-07):** `apps/web/src/features/pos/components/ShiftReceiptsList.tsx:8,57` — surface the stamp column/field.

---

## 6. Schema / migration (resolves Codex F-12)

- `pos_receipts`: add `stamp_duty_amount decimal(N,3) NOT NULL DEFAULT '0.000'` (tenant migration; N matches currency-total columns).
- **Immutability trigger:** the `pos_receipts` append-only trigger explicitly whitelists `fiscal_hash, receipt_number, total, subtotal, tax_amount`, sequence, timestamp during void updates (`2026_01_08_190637_create_pos_receipts_table.php:157`); a later migration notes new fields fall outside the whitelist (`2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:41`). The V3 migration MUST add a PG trigger update that protects `stamp_duty_amount` as a sealed total field. **Provide the trigger SQL in the migration — do not merely assert protection.**
- Device SQLite migration for the offline schema (§5.4).

---

## 7. Reporting, GL, and the Z-report (resolves Codex F-04, F-07)

### GL (F-04)
A stamp collected from the customer is **owed to the State**, not revenue. Today POS payments credit the full amount to revenue (`GeneralLedgerService.php:1417`) and there is no stamp liability purpose (`SystemAccountPurpose.php:24`). V3 journal:

```
Dr  Cash / tender            total (incl. stamp)
   Cr  Sales (net)           subtotal
   Cr  VAT payable           vat_total
   Cr  Stamp-duty payable    stamp_duty_amount   ← NEW SystemAccountPurpose::StampDutyPayable
```

Add the account purpose, seed the TN account, and split the entry in `TreasuryReceiptBridge`/`GeneralLedgerService`. **Needs accountant sign-off** on the account + posting.

### Z-report (F-07) — decision required
X/Z canonical payloads (`XReportPayload.php:9`, `ZReportPayload.php:9`) and device authoring totals (`zSessionAuthoring.ts:383,480`) have no stamp bucket, so sealed session reports can't reconcile stamp collections for the tax authority. Two options:
- **(A) Read-time aggregation** — Z projection sums `pos_receipts.stamp_duty_amount` for the session; no Z canonical change. Simpler, but the stamp total is NOT sealed in the Z.
- **(B) Sealed Z stamp bucket** — add a stamp total to the Z canonical → a **second versioned event (ZReportV-next)** with its own version-aware handling.

**Recommendation:** (B) for fiscal defensibility (the Z is the reconciliation document submitted to the authority), accepting a second version bump — but confirm with the fiscal/legal owner whether read-time aggregation is acceptable. Whichever is chosen, do it as a deliberate, separate versioned change (same discipline as §4).

---

## 8. Refund / return / void treatment (resolves Codex F-10) — needs legal confirmation

`invoice_type_code` already supports `REFUND`/`VOID` (`FiscalEventEngine.ts:406`); projection maps them with original-receipt references (`PosCoreReceiptProjection.php:202`); receipts have an immutability-safe void path (`...create_pos_receipts_table.php:67,152`).

**Default rules to confirm with the accountant/legal:**
- **Returns/refunds:** the timbre was incurred when the *original* ticket was issued; a return generally does **NOT** refund the stamp. A refund/return receipt therefore carries `stamp_duty_amount = 0` (scale-zero) unless the return itself is a separately-stamped fiscal document (likely not). GL: do NOT debit `StampDutyPayable` on refund.
- **Voids (pre-finalization):** no stamp.
- The V3 design must state this explicitly for the projection, GL, NF525 export, and the printed return ticket. Flag as a legal-confirmation item.

---

## 9. Multi-currency + device trust model (resolves Codex F-08 partial, F-11)

- **Multi-currency:** stamp config is TN/TND-specific today (`TunisiaTaxConfigurationSeeder.php:98`). V3 must (a) format the zero/amount at the receipt's `currency_scale` (§3.1), and (b) define behavior for non-TND companies — the receipt-stamp config simply stays inactive for them (no stamp), so non-TND receipts seal scale-zero. No cross-currency conversion of the stamp.
- **Trust model (F-11):** the server verifies the hash but does NOT recompute applicability (`OutboxIngestor.php:165`). A stale/tampered device config could seal an internally-consistent but legally-wrong stamp. Add a **server-side cross-check on ingest**: validate the sealed `stamp_duty_amount` against the tenant's receipt-stamp config **effective at the receipt's business date** (respecting effective-dating — the device may legitimately be on slightly stale config offline). On mismatch: **flag/quarantine for review** rather than hard-reject (preserve offline-first; a hard reject would lose a legitimately-sealed receipt). Specify the cross-check + the quarantine path.

---

## 10. Idempotency / replay (resolves Codex F-10-adjacent)

Offline receipt creation returns the persisted row on idempotency-key retry without recomputing totals (`receiptService.ts:236`). The spec must state explicitly: **the stamp config snapshot + amount are captured at first seal and NEVER recomputed on retry/replay** — the sealed bytes are authoritative; retries return the same sealed receipt.

---

## 11. Test plan (TDD) + first-seal discipline

**Version-aware foundation first (the BLOCKER):** tests that V1 and V2 canonical bytes still validate, parse, read (`CanonicalPayloadReader`), best-effort-parse, and recover **unchanged** after the version-aware refactor — before the field is added anywhere.

**Then per-touchpoint:**
- Device: stamp computed when config active, scale-zero when not; total identity (§3.3) holds; payload builder emits the key; key-drift test asserts per-version sets.
- Server: a V3 payload validates (version-aware key-set + `+stamp` identity); V2/V1 still validate; a V3 missing the key and a V1/V2 *with* the key both quarantine.
- Canonical round-trip: device bytes → `HashChainIntegrityProvider::computeHash` matches; `StrictCanonicalParser` accepts; OutboxIngestor ingests.
- Server applicability cross-check (§9) flags a mismatched stamp without losing the receipt.
- Projection persists stamp; Z aggregates/seal per §7 choice; GL posts the `StampDutyPayable` split; NF525 export carries it; offline SQLite round-trips; web-admin shows it; refund/void per §8.
- Scale matrix: scale-3 (TND) and scale-2 currency both seal a correct zero.

**Pre-first-seal (mandatory):** on a real terminal, seal a V3 receipt, sync, verify the hash chain end-to-end. Gate before ANY production seal.

---

## 12. Rollout / coexistence

- **Default OFF** (shipped). A qualifying tenant activates the `FISCAL_RECEIPT` stamp config in the tax UI; the device picks it up via the new config sync (§5.5).
- **Coexistence forever:** V1/V2 never had the field; all readers/exports treat a missing `stamp_duty_amount` on older versions as scale-zero **at read time**, and never rewrite sealed V1/V2 events. The version-aware layer (§4) guarantees old events are never required to carry the key.
- **First-seal** only after §11's real-terminal smoke.

---

## 13. Sequencing & risk (unchanged from v1)

Immutability is the dominant risk, gated by the first sealed V3 receipt, not by when code is written. No customers yet = lowest-risk window to lock the shape; build dormant behind the default-off gate. Parallel-safe vs all non-fiscal work; **serialize against any other canonical-payload / event-version change** (immutability + device↔PHP key-drift gate). Cross-stack lockstep (TS + Rust + PHP) → one dedicated focused session.

---

## 14. Changelog — v1 → v2 (Codex review coverage)

| Finding | Severity | Resolution in v2 |
|---|---|---|
| F-01 | BLOCKER | §4 — version-aware key-set + invariant, resolved *before* the strict key-set check, across validator + parser; §2 documents the current per-type behavior. |
| F-02 | HIGH | §4.3 — `CanonicalPayloadReader` version-dispatch; V1/V2 never require the key; dedicated `SaleReceiptV3Payload` recommended. |
| F-03 | HIGH | §5.5 — new tax-config sync channel + device-side tax-config repo (was claimed but unimplemented). |
| F-04 | HIGH | §7-GL — new `StampDutyPayable` purpose + split POS-payment journal; accountant sign-off. |
| F-05 | HIGH | §5.4, §6 — offline SQLite schema + repo carry the stamp. |
| F-06 | HIGH | §5.12 — NF525 DTO/XML + `Nf525DataProvider` V3-aware. |
| F-07 | HIGH | §7-Z — X/Z stamp bucket; (A) read-time vs (B) sealed ZReportV-next decision, recommend (B). |
| F-08 | MEDIUM | §3.1, §9 — scale-aware zero formatted at `currency_scale`, not literal `"0.000"`. |
| F-09 | MEDIUM | §3.3 — explicit `subtotal + vat_total + stamp == total + discount`; stamp outside VAT/discount base; device total order specified. |
| F-10 | MEDIUM | §8 — refund/return/void stamp rules (default: not reversed) + GL/export/print treatment; legal confirmation flagged. |
| F-11 | MEDIUM | §9 — server-side applicability cross-check at ingest (effective-date aware), flag/quarantine not hard-reject. |
| F-12 | MEDIUM | §6 — explicit immutability-trigger migration SQL protecting `stamp_duty_amount`. |

---

## 15. Open questions for the build session

1. GL `StampDutyPayable` account number + posting (accountant).
2. Z-report: read-time aggregation (A) vs sealed `ZReportV-next` bucket (B) — fiscal/legal owner.
3. Refund/return/void stamp treatment — legal confirmation (§8).
4. Device config-sync channel design (reuse catalog sync vs new endpoint) + the effective-date-aware server cross-check (§9).
5. `SaleReceiptV3Payload` as a new DTO vs version-aware hydration of the existing one (§4.3).

---

## 16. Pointers (verified files, `dev`)

Registry `FiscalEventPayloadRegistry.php:53,99,105` · DTO `SaleReceiptPayload.php:45` · Validator `FiscalPayloadConstraintValidator.php:225,316,336,699,754,779,866` · Parser `StrictCanonicalParser.php:204,226,231` · Reader `CanonicalPayloadReader.php:78` · Best-effort `BestEffortPayloadParser.php:77` · ParseFailure `ParseFailureResolutionService.php:312` · Ingestor `OutboxIngestor.php:165,379` · Encoder `FiscalEventCanonicalEncoder.ts:34` · Hash `HashChainIntegrityProvider.php:16` · Device builder/keys `SaleReceiptPayload.ts:106,121,134,193`, `FiscalEventEngine.ts:400,406,1032` · Cart `cartStore.ts:144,601,624,642` · Key-drift `FiscalPayloadKeyDrift.test.ts:7,12` · Offline DB `db/migrations.ts:99`, `offlineReceiptRepository.ts:29`, `receiptService.ts:236` · Sync `syncService.ts:1,117` · Projection `PosCoreReceiptProjection.php:185,202` · Z `ZReportProjection.php:120`, `GrandtotalService.php:117`, `zSessionAuthoring.ts:383,480` · NF525 `Nf525ReceiptData.php:38`, `Nf525XmlBuilder.php:114`, `Nf525DataProvider.php:620,1303` · GL `GeneralLedgerService.php:1366,1417`, `TreasuryReceiptBridge.php:414`, `SystemAccountPurpose.php:24` · Render `receipt.blade.php:419`, `receipt_template.rs:28` · Web-admin `ShiftReceiptsList.tsx:8,57` · Trigger `2026_01_08_190637_create_pos_receipts_table.php:157`, `2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:41` · Gating `TunisiaTaxConfigurationSeeder.php:98`.
