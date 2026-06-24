# SaleReceiptV3 — Conditional fiscal stamp duty in the immutable receipt chain

**Status:** Design locked, build DEFERRED. Implement in a dedicated session.
**Date:** 2026-06-24
**Author:** design session (tax-config correctness cycle)
**Predecessor work (already on `dev`, commit `3a3195030`):** tax-config correctness — receipt timbre gated off by default, effective-dating, manage UI, dead stamp-duty path removed.

---

## 0. TL;DR for the implementer

We need POS sale receipts to optionally carry a **fixed per-ticket fiscal stamp duty** (Tunisia: 0.100 TND), sealed into the immutable, device-authored, hash-chained `SALE_RECEIPT` fiscal event. This requires a new **`SaleReceiptV3`** payload version.

**Decision (locked):** the stamp is an **always-present decimal `stamp_duty_amount`, default `"0.000"`**, within V3 — NOT an optional/omitted field.

**Do NOT build until** a real *grande-surface* tenant needs it. **When you do build it**, do it as the *only* fiscal-event-shape change in flight (it parallelizes with non-fiscal work but must be serialized against any other canonical-payload / event-version / receipt-total change). **Do not seal the first production V3 receipt** until a real-terminal device→server round-trip + hash-verify smoke is green.

---

## 1. Why this exists (legal + product)

- Tunisia's **droit de timbre on tickets de caisse = 0.100 TND per ticket**, levied ONLY by *grandes surfaces commerciales*, multi-department stores under the DGE/DME directorate, and foreign-brand franchisees (CDET Art. 117-10° / 135 bis, LF 2022). It is **per-document, flat, total-independent**, and **additional to** the 1.000 TND invoice timbre. It is paid by the customer per ticket. (See the deep-research result in the tax-config cycle; DGI Note Commune 15/2022.)
- An ordinary retailer / parapharmacy is in **none** of those categories and must NOT levy it — already handled: the `STAMP_FISCAL_RECEIPT` `tax_configurations` row is seeded `is_active = false` and is activatable per-tenant via the tax-management UI (`taxation.tax_configurations.manage`).
- This spec covers the remaining piece: **when a qualifying tenant activates it, the stamp must appear on the sealed POS receipt total** (and flow into Z-report, GL, and the printed ticket).

**2026 note (out of scope here):** LF 2026 adds tiered *invoice* stamps (1.5/2 TND) for grandes surfaces > 1500 m². That's an invoice-side, document-engine concern (effective-dating already supports it) — NOT this receipt-chain change.

---

## 2. The architectural reality (why this is a versioned change, not a column)

The POS sale is **device-authoritative**:

- The Tauri device computes the total (`apps/pos/src/stores/cartStore.ts`), builds the canonical `SALE_RECEIPT` payload (`apps/pos/src/lib/fiscal/payloads/SaleReceiptV2Payload.ts`), JCS-encodes it and **SHA-256 hash-chains it on-device** (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts`).
- The server stores the bytes verbatim and only validates **internal consistency** of the device's own numbers — it does NOT recompute (`apps/api/.../FiscalPayloadConstraintValidator.php`). The hash is verified, not recomputed.
- **Events are immutable forever** (CLAUDE.md rule 8). A wrong sealed shape can only be replaced by a *new* version, never edited.

Therefore the stamp must be **computed on-device before sealing**, and the canonical payload must gain the field via a **new version**. The server's strict key-set + aggregate invariants + the device↔PHP key-drift gate must all move in lockstep.

### 2.1 Current canonical state (verified 2026-06-24, on `dev`)

- Registry: `FiscalEventPayloadRegistry` — `SALE_RECEIPT => [SaleReceiptPayload::class, 2]`; accepted versions `[1, 2]`. Current **write version = 2**.
- `SaleReceiptPayload` top-level money fields: `subtotal`, `vat_total`, `total`, `transaction_discount_amount` (+ `transaction_discount_reason`), `vat_breakdown`, `seller`, `lines`, `payment_lines`, `voucher_redemptions`, `currency`, … **No stamp field.** Only percentage VAT is modelled.
- **Aggregate invariant (§6.D in the validator):** `subtotal + vat_total == total + transaction_discount_amount` at currency scale; plus `Σ vat_breakdown.net == subtotal`, `Σ vat_breakdown.vat == vat_total`.
- **CRITICAL — the key-set and constraints are keyed per event TYPE, not per version.** `FiscalPayloadConstraintValidator::PAYLOAD_KEYS` (≈ line 225) is one const; `CONSTRAINT_MAP` maps `SALE_RECEIPT → PAYLOAD_KEYS`. `StrictCanonicalParser` shares the same key list. The V1→V2 bump only added *nested* line fields (`variant_id`/`variant_name`), so the **top-level** key-set never had to become version-aware. **V3 adds a top-level key, so it does.**

---

## 3. The locked decision: field shape

### 3.1 Always-present `stamp_duty_amount` (default `"0.000"`), NOT optional/omitted

Add a single top-level scalar to the V3 payload:

```
stamp_duty_amount: string   // currency-scale decimal, e.g. "0.000" or "0.100"; ALWAYS present in V3
```

**Rationale (the question we set out to answer):**

- The certification research (FatturaPA / `imposta di bollo`) shows the dominant *certified* pattern is **present-only-when-applicable** (the `DatiBollo` block is `minOccurs="0"`, omitted when not due — there is no zero counterpart). But that suits **XML/XSD**, where optionality is first-class and XML-c14n handles absence.
- **Our architecture is different:** a fixed JSON key-set + JCS canonicalization + an exact-equality strict-key validator + a device↔PHP **key-drift gate**. The research confirmed the decisive fact: **under JCS an omitted member and a present `"0.000"` member produce different canonical bytes → different hashes.** So absence-vs-zero is a real, irreversible choice, not cosmetic.
- An **optional key** means **two canonical shapes** per version that the device and server must keep in lockstep, plus a "required-vs-optional key" branch in the strict validator and the key-drift gate — more divergence surface and a harder certification story.
- An **always-present scalar** keeps **one invariant record shape per version**, lets the sealed arithmetic identity extend cleanly, and makes "no stamp due" an **explicit, auditable assertion** rather than something inferred from a missing key. This matches how the payload already seals `transaction_discount_amount` as `"0.000"` when there's no discount.

**Verdict:** within V3, `stamp_duty_amount` is mandatory and always present; `"0.000"` encodes "no stamp due". V1/V2 never had the key and stay valid forever (handled by version-aware validation, §4).

### 3.2 What is NOT sealed (kept minimal)

Only the **amount** is a fiscal fact affecting the total + chain, so only the amount is sealed. The human label ("Timbre fiscal"), legal reference, and the originating tax-config code are **descriptive provenance** — rendered at print/report time from config, NOT sealed — exactly as VAT seals `net/vat/rate` but not the tax's display name. (If a future audit requirement demands the config code in the chain, that is a *further* version; do not pre-add it now.)

### 3.3 The sealed arithmetic invariant for V3

```
V1/V2:  subtotal + vat_total                      == total + transaction_discount_amount
V3:     subtotal + vat_total + stamp_duty_amount  == total + transaction_discount_amount
```

The stamp is a **document-total addition the customer pays** — not VAT, not a discount, not a line item. `total` includes it. The `vat_breakdown` sums are unchanged (`Σ net == subtotal`, `Σ vat == vat_total`); stamp sits outside the VAT breakdown.

---

## 4. The core structural change: make SALE_RECEIPT validation version-aware

This is the heart of the build and the main risk. Today one key-set + one invariant serve all SALE_RECEIPT versions. V3 needs:

- **Version-aware key-set:** `SALE_RECEIPT` v1/v2 → current keys; v3 → current keys **+ `stamp_duty_amount`**. Implement a `(type, version) → key-set` lookup for SALE_RECEIPT in `FiscalPayloadConstraintValidator` and `StrictCanonicalParser` (they share the list — keep them sharing a single source so they can't drift).
- **Version-aware aggregate invariant:** v1/v2 use the current identity; v3 uses the `+ stamp_duty_amount` identity (§3.3). Find `validateSaleReceiptAggregateConsistency` + the total cross-check and branch on `event_version`.
- **Reject mixed states:** a v3 payload missing `stamp_duty_amount` → quarantine; a v1/v2 payload *containing* `stamp_duty_amount` → quarantine (extra key). The exact-key checks already give you this once the per-version key-set is wired.

> Implementation note: prefer a dedicated `SaleReceiptV3Payload` DTO + its own `PAYLOAD_KEYS` over overloading `SaleReceiptPayload`, so each version's shape is self-documenting and the registry write-version bump (`SALE_RECEIPT => [SaleReceiptV3Payload::class, 3]`, accepted `[1,2,3]`) is explicit. Decide during build; either is fine if the per-version key-set + invariant are correct.

---

## 5. Full end-to-end touchpoint checklist

Order roughly device → seal → server-validate → project → report → render. Each is a TDD unit.

**Device (apps/pos, TypeScript) — must move in lockstep with PHP:**
1. `src/stores/cartStore.ts` — compute `stampDuty = receiptStampActive ? config.fixed_amount : "0.000"` and fold it into `total`. Extend the on-device aggregate identity to `subtotal + vat_total + stamp == total + discount`.
2. `src/lib/fiscal/payloads/SaleReceiptV2Payload.ts` → add a V3 builder; update `SALE_RECEIPT_PAYLOAD_KEYS` and `SaleReceiptPayloadInput` (also referenced in `src/lib/fiscal/FiscalEventEngine.ts`).
3. `src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts` — the gate that pins device key-set == PHP key-set. Update **in the same change** as the PHP key-set, or CI goes red (by design).
4. **Where the device learns the amount + applicability:** the tenant's `tax_configurations` `FISCAL_RECEIPT` stamp row (`is_active`, `fixed_amount`) must reach the device via the existing config/catalog sync. Device computes `"0.000"` when no active receipt-stamp config. (Confirm the sync path during build — likely the same channel that pushes VAT/products. This keeps the gate single-sourced: activating the config in the web UI is what turns it on for the device.)

**Server (apps/api, PHP):**
5. `app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php` (+ a new `SaleReceiptV3Payload` if chosen) — the V3 shape + `toArray`/`fromArray`.
6. `app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php` — `SALE_RECEIPT` write version → 3; accepted `[1,2,3]`.
7. `app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php` — version-aware key-set + aggregate invariant (§4).
8. `app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php` — allow `stamp_duty_amount` for v3 (shared key list).
9. `app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` — persist the stamp into `pos_receipts` (new column, §6).
10. `app/Modules/POS/Application/Projections/ZReportProjection.php` + `GrandtotalService.php` — aggregate stamp into Z-report totals (new total bucket; do NOT fold into VAT).
11. **GL:** `app/Modules/Accounting/Domain/DTOs/CreatePOSChargeJournalEntryCommand.php` (+ the posting service) — post the collected stamp as a **stamp-duty-payable liability** (Cr), not revenue. Needs a GL account (§7).
12. **Render:** `app/Modules/POS/Application/Services/ReceiptPdfService.php` + the thermal template (`apps/pos/src-tauri/src/printing/receipt_template.rs` / `escpos.rs`) — print a "Timbre fiscal" line. Label/amount from config + sealed amount.

---

## 6. Schema / migration

- `pos_receipts`: add `stamp_duty_amount decimal(N,3) NOT NULL DEFAULT '0.000'` (tenant migration). N matches the existing currency-total columns.
- **Append-only trigger:** the existing `pos_receipts` immutability trigger protects "totals". `stamp_duty_amount` is part of the sealed total, so it MUST be in the immutable-fields set after seal — verify the trigger's enumerated/“totals” list covers it (see migrations `..._create_pos_receipts_table` and `..._update_pos_receipts_immutability_trigger_for_pending_seal`); extend if the list is explicit.
- No new VAT-detail row — stamp is not VAT.

---

## 7. GL treatment (needs owner/accountant sign-off)

A stamp collected from the customer is **money owed to the State**, not revenue. The POS sale journal becomes:

```
Dr  Cash / tender            total (incl. stamp)
   Cr  Sales (net)           subtotal
   Cr  VAT payable           vat_total
   Cr  Stamp-duty payable    stamp_duty_amount   ← NEW liability account
```

**Open:** the GL account for "timbre / stamp-duty payable" must be added to the chart of accounts (TN) and resolved in the posting command. Flag for the accountant.

---

## 8. Test plan (TDD, then a real-terminal smoke before first seal)

**Unit / integration (can run with no hardware):**
- Device: cartStore computes stamp when config active, `"0.000"` when not; total identity holds; payload builder emits the key; key-drift test updated and green.
- Server: a v3 payload validates (version-aware key-set + `+stamp` identity); a v2 payload still validates (back-compat); a v3 missing the key and a v2 with the key both quarantine.
- **Canonical round-trip:** device-produced bytes → `HashChainIntegrityProvider::computeHash` matches; `StrictCanonicalParser` accepts.
- Projection persists `stamp_duty_amount`; Z-report aggregates it as its own bucket; GL posts the liability.
- Render: receipt PDF + thermal show the timbre line.

**Pre-first-seal (mandatory):** on a real terminal, seal a v3 receipt, sync to server, verify the hash chain verifies end-to-end. **This is the gate before any production seal.**

---

## 9. Rollout / enablement & coexistence

- **Default OFF** (already shipped via `is_active=false`). A qualifying tenant is enabled by activating the `FISCAL_RECEIPT` stamp config in the tax-management UI; the device picks it up via config sync.
- **First-seal discipline:** only after §8's real-terminal smoke is green.
- **Coexistence forever:** v1/v2 receipts never had the field. All readers (Z-report, NF525 export, render) must treat a missing `stamp_duty_amount` on older versions as `0.000` **at read time**, and must **never rewrite** sealed v1/v2 events. The version-aware validator guarantees you can't accidentally require the key on old events.

---

## 10. Open questions to resolve in the build session

1. **GL account** for stamp-duty payable (accountant) — §7.
2. **Device config sync path** — confirm the `tax_configurations` `FISCAL_RECEIPT` row (is_active + fixed_amount) reaches the device through the existing catalog/config channel, or whether a dedicated terminal-config field is needed — §5.4.
3. **DTO strategy** — new `SaleReceiptV3Payload` class vs. extending `SaleReceiptPayload` — §4 note.
4. **Append-only trigger** — confirm whether the immutable-field list is explicit and needs `stamp_duty_amount` added — §6.
5. **`X_REPORT` / `Z_REPORT`** — Z aggregates stamp; confirm whether the Z canonical (`ZReportPayload`, currently `seller: null`) needs a stamp-total bucket sealed, or whether aggregation at projection time is sufficient. (Likely projection-time only; Z seller stays null.)

---

## 11. Sequencing & risk (decided in the design discussion)

- **The dominant risk is immutability** — a wrong sealed shape is permanent (would need a V4). That risk is gated by the **first sealed V3 receipt**, not by when the code is written.
- **Lowest-risk window:** there are **no customers yet** — the cheapest time to lock the shape. Build it dormant behind the default-off gate; the code can ship to `dev` without sealing anything.
- **Parallelism:** V3 is surgically scoped to the fiscal-event subsystem. It **runs in parallel with all non-fiscal work** (web admin, catalog, treasury, reporting, taxation document-side). The **one hard rule: do NOT run a second canonical-payload / event-version / receipt-total change concurrently** — the immutability rule + the device↔PHP key-drift gate make two concurrent shape changes collide and risk a silent device/server desync. Serialize fiscal-shape work; parallelize the rest.
- **Cross-stack lockstep:** TS (device) + Rust (thermal) + PHP (server) must move together. Best done in **one dedicated, focused session** — context-switching mid-change is exactly how the device/server key-sets drift.
- **First-seal only** after the real-terminal round-trip smoke (§8), ideally still before the first qualifying tenant's go-live so no production receipt is ever sealed against an unvalidated shape.

---

## 12. Pointers (verified files, `dev` @ `3a3195030`)

- Registry: `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php` (`SALE_RECEIPT => [..., 2]`, accepted `[1,2]`).
- Canonical DTO: `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php`.
- Validator: `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php` (`PAYLOAD_KEYS` ≈ L225; `CONSTRAINT_MAP` L289+; aggregate identity §6.D in the class docblock L44).
- Parser: `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php`.
- Device builder/engine: `apps/pos/src/lib/fiscal/payloads/SaleReceiptV2Payload.ts`, `apps/pos/src/lib/fiscal/FiscalEventEngine.ts`.
- Key-drift gate: `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts`.
- Device totals: `apps/pos/src/stores/cartStore.ts`.
- Projections: `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`, `.../ZReportProjection.php`; `GrandtotalService.php`.
- Render: `apps/api/app/Modules/POS/Application/Services/ReceiptPdfService.php`; `apps/pos/src-tauri/src/printing/receipt_template.rs`.
- Gating already shipped: `apps/api/database/seeders/TunisiaTaxConfigurationSeeder.php` (`STAMP_FISCAL_RECEIPT`, `is_active=false`).
