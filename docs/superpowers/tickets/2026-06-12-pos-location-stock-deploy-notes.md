# POS Location-Aware Stock — Deploy Notes (2026-06-12)

Feature branch: `feat/pos-location-aware-stock`
Spec: `docs/superpowers/specs/2026-06-11-pos-location-aware-stock-design.md`

---

## Pre-deploy checklist

### (a) Run the backfill command once after rolling migration

After `tenant:migrate-rolling` completes, run the backfill command once across all tenants:

```bash
php artisan pos:stock-policy-backfill
```

What it does: walks the central tenant directory and, inside each tenant's DB context, sets `companies.pos_stock_policy` to `off` for Menu-vertical tenants (F&B) and leaves all other tenants at the column default (`block`). Safe to re-run; `--dry-run` flag reports what would change without writing.

The column default of `block` means existing retail tenants need no backfill — they fail closed (stock-blocked) on unknown inventory. Only Menu tenants need `off` to skip the stock chrome entirely.

### (b) Operator-facing behavioral change: seller identity atomicity (§4.6)

**Context:** before this feature, a branch with `tax_id` set but an incomplete address (e.g. missing street/city) would author the branch tax number alongside the company address — a mixed seller identity. That is legally incoherent even when it passes structural validation.

**Change:** under the atomic seller resolver (`apps/pos/src/lib/fiscal/sellerIdentity.ts`), a branch with `tax_id` but an incomplete address now authors the **full company identity** on receipts and fiscal payloads. No mixing.

**Operator action required:** any branch that should author its own fiscal identity on `SALE_RECEIPT` / `ACCOUNT_PAYMENT` / `ACCOUNT_CHARGE` payloads must have a **complete location record** in the ERP:
- `locations.tax_id` — the establishment's tax number
- `locations.address_street`, `locations.address_city`, `locations.address_postal_code`, `locations.address_country` — all four fields non-empty

Branches that currently show a mixed identity on receipts (branch tax number + company address) will shift to pure company identity until their location records are completed.

No re-signing of historical events — this clause governs only newly authored canonical payloads (canonical bytes are immutable; §1.2 of the fiscal SoT).

### (c) Z report printed header now shows the resolved tax ID

The Z report's printed header now displays the seller identity resolved by the same atomic resolver (`resolveSellerIdentity`). For terminals at a fiscally complete branch, the header will show the **branch tax ID and address** rather than the company's. For all others it shows the company identity as before.

If a tenant's Z header was previously blank or empty (company has no `tax_id` set), the behavior is unchanged.

### (d) Terminal refresh to receive new fields

Cached terminal payloads from before this feature do not include `pos_stock_policy` or the location address fields (`address_street`, `address_city`, `address_postal_code`, `address_country`).

**How refresh happens:** `terminalStore.refreshTerminalRecord()` is called on every 60-second sync tick. It fetches `/pos/terminals/{id}` and persists the fresh `Terminal` payload (including the new fields) to `localStorage`. No manual action is required — the fields will be present after the first successful sync tick post-deploy.

**Operational step for immediate rollout:** if you need the fields before the next automatic tick, have the operator log out and re-claim the terminal. The claim flow calls `terminalStore.initialize()` which fetches the terminal fresh. Re-claim is also the correct procedure after admin changes to `pos_stock_policy` or location address on a live terminal.

### (e) Known residual: 90-day stuck-receipt cleanup can transiently resurrect availability (FU-4)

The availability selector subtracts unsynced offline-receipt lines directly (there is no separate deductions store). `offlineReceiptRepository.cleanupStuckReceipts()` deletes receipts that failed to sync for 90 days with retries exhausted; deleting one removes its deduction, so `effectiveAvailable` can briefly tick back up — showing stock that was sold locally but never confirmed to the server.

This is an **accepted residual**, not a launch blocker: it only affects a deep edge case (failed + retry-exhausted + 90 days old), and the server never ingested the sale either, so the local figure simply re-aligns to the server's. No operator action required. If a stock re-pull gate is ever added to `cleanupStuckReceipts`, it must pull THEN delete (atomic) so the window never opens. Documented at the function in `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts`.

---

## No destructive schema changes

This feature adds:
- One new column: `companies.pos_stock_policy` (enum, default `block`)
- One new read endpoint: `GET /pos/stock-levels`
- Client: SQLite `location_stock` table (migration v50, additive)

No existing columns altered, no event-version bump, no fiscal payload shape change, no data migration beyond the enum backfill above.
