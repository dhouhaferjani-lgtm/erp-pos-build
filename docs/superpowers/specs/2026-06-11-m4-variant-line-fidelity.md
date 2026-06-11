# M4 — Variant line fidelity in the signed SALE_RECEIPT (spec)

> Launch audit (2026-06-09) item **M4**, MEDIUM. This changes the SIGNED canonical bytes of the
> SALE_RECEIPT fiscal event, so it is a versioned-event change (Events are Immutable Forever →
> `SaleReceiptV2`) requiring owner sign-off on the byte layout + cross-language golden-hash
> verification. Spec'd here; NOT implemented blind.

## The gap

For a variant sale, the signed SALE_RECEIPT line carries the **parent product** identity at the
**variant price** (`apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:315-321`):

```ts
name: item.product.name,     // PARENT name
product_id: item.product.id, // PARENT id
sku: item.product.sku,       // PARENT sku
unit_price: bcformat(item.unit_price, scale), // VARIANT price
```

There is no `variant_id` / `variant_sku` / `variant_name` in the canonical bytes — the variant
identity is out-of-band. So the **printed ticket** (which shows the variant, e.g. "T-Shirt — Red /
L") does not match the **signed record** (which says "T-Shirt" at the variant price). Under NF525
the signed record is the legal artefact; a sealed line that does not identify the exact article
sold is a line-fidelity defect.

## Two options (from the audit)

1. **Document a recovery procedure** (no byte change) — keep `variant_id` out-of-band but persist a
   durable, auditable mapping (sale line ↔ variant_id ↔ variant sku/name at time of sale) so the
   exact article can be reconstructed for any sealed line. Cheapest; leaves the signed bytes
   parent-only, which an auditor may still challenge.
2. **`SaleReceiptV2`** — fold the variant identity into the canonical bytes. Recommended for true
   NF525 fidelity.

## `SaleReceiptV2` design (option 2)

Add to each line item, only when the sold item is a variant (null otherwise so V2 is a strict
superset and parent-only lines hash identically to their V1 intent):

```
variant_id:   string | null   // the concrete variant sold
variant_sku:  string | null   // variant SKU (what the ticket prints)
variant_name: string | null   // variant display name / attribute summary
```

Keep `name`/`sku`/`product_id` as the parent (don't repurpose them — that would break existing
projections). `unit_price` stays the variant price (already correct).

### Required steps (all must land together)

1. New canonical builder `buildSaleReceiptV2Payload` + `event_version = 2` (do NOT mutate the V1
   builder — V1 events already exist conceptually; create the versioned replacement per the
   immutability rule).
2. Bump the device `signature_version` / payload registry for SALE_RECEIPT to author V2 on v3
   terminals; keep V1 parsing for any historical event.
3. Mirror the canonical byte layout on the **server** (`FiscalEventPayloadRegistry` /
   `CanonicalPayloadReader` SALE_RECEIPT) byte-for-byte.
4. Regenerate the **v3 golden-hash fixtures** (`apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/`)
   and pass the cross-language fixture-sync CI gate (device JS hash == server PHP hash).
5. Projection: store the variant fields so the ticket and the signed record provably agree.

### Verification

- Cross-language golden-hash parity (the dedicated gate).
- A variant sale: assert the signed canonical bytes contain `variant_sku` and that it equals the
  printed ticket's variant SKU.
- A parent (non-variant) sale: assert V2 bytes are stable (variant_* = null) and the hash is
  deterministic.

## Why not shipped in this pass

Changing the signed SALE_RECEIPT bytes is the highest-consequence fiscal change in the audit:
clean slate means no chain to migrate, but it still demands device⇄server byte parity, a new
golden hash, and owner sign-off on the canonical layout. It should be implemented as its own
reviewed PR (Codex fiscal review + the cross-language gate), not folded into the launch-blocker
sweep. Until then, **option 1's mapping should exist** as the interim audit-recovery path.
