# Ticket: the C-2 statement fixture rings POS sales at whatever location sorts first — including the warehouse

**Filed by:** the L4 currency-emission lane, while repairing `MTP-MLC-06`'s premise after the post-merge tripwire run.
**Date:** 2026-08-06 · **Severity:** P3 — a TEST-FIXTURE defect on shared campaign infra. No product code is
implicated and no customer data is affected. It corrupts campaign premises and it compounds on every run.

## Symptom

`MTP-MLC-06` ("a non-POS warehouse scope yields zero POS revenue") failed on the post-merge run. The warehouse
`WH-01 / PharmaBio Entrepôt Central` — a `type: warehouse` location that by construction cannot ring a sale — now
holds **1 POS receipt, `100.000`, dated 2026-08-05**:

```
GET /reports/sales/by-location?from=2026-01-01&to=2026-12-31&location_ids[]=<WH-01>
{"data":[{"period":"2026-08-05","location_name":"PharmaBio Entrepôt Central",
          "gross_sales":"100.000","receipt_count":1}]}
```

**Not an L4 regression.** The L4 diff changed how report numbers are *rendered*; it touches no row-producing SQL and
cannot mint a receipt. Verified against the API directly before any spec was edited.

## Root cause — `authorTier4CardFiscalSale` clones the first active terminal's location

`apps/web/e2e/money-campaign/statement-support.ts:419-439`:

```ts
const terminals = await GET /pos/terminals
const template = terminals.find((t) => t.is_active)      // <-- first active, NO location predicate
...
await POST /pos/terminals { location_id: template!.location_id }   // <-- clones its location
// then ingests a real fiscal SALE_RECEIPT on the new terminal
```

The fixture needs *a dedicated terminal*; it does not care *where*. So it takes whichever terminal the list happens
to return first and inherits that location. Live evidence:

| Terminal | Location | Created |
|---|---|---|
| `C2-001d096a` | **WH-01 (warehouse)** | 2026-08-05 18:01:42 |
| `VADMIN` (Virtual Admin Terminal) | **WH-01 (warehouse)** | 2026-08-04 11:58:44 |
| `C2-7f6e8992`, `C2-afbffb6d`, `C2-e2457ec0`, `C2-f8d8b399` | STORE-TUN1 | 2026-08-03 |
| `POS01` ×4 | one per shop | 2026-08-01 (seeder) |

The receipt is `FE-C2-001d096a-2026-00000001`, `100.000`, posted `2026-08-05T18:01:12Z`, on `C2-001d096a` — the
terminal the fixture minted 30 s later at the warehouse.

**The long green was ORDER-LUCK.** Before the warehouse-resident `VADMIN` existed, `find(is_active)` returned a
STORE-TUN1 terminal, which is why the four 2026-08-03 clones all landed at a shop and why `MTP-MLC-06` had been
green for six waves. `VADMIN` appeared on 2026-08-04 and the very next C-2 run landed at the warehouse.

**It compounds.** `C2-001d096a` (warehouse) now sorts ahead of every shop terminal, so every subsequent C-2 run
clones the warehouse again. Left alone, the warehouse accrues one fixture receipt per run and any future "this
location has no POS money" premise is dead on arrival.

## Fix

In `authorTier4CardFiscalSale`, select the template terminal by LOCATION TYPE, not by list order — the fixture wants
a shop:

```ts
const shopLocations = new Set(
  (await listLocations(...)).filter((l) => l.type === 'shop').map((l) => l.id),
)
const template = terminals.find((t) => t.is_active && shopLocations.has(t.location_id))
expect(template, 'an active terminal at a SHOP exists — a fiscal sale must not be rung at a warehouse').toBeTruthy()
```

Anchoring on the seeded `POS01` code would also work and is more deterministic still. Either way the invariant to
assert is the one the domain already implies: **a fiscal `SALE_RECEIPT` is never rung at a non-POS location.**

## Cleanup owed (owner decision — do NOT do this silently)

The stray warehouse receipt is a **real fiscal record in the hash chain** (`FE-C2-001d096a-2026-00000001`). It is
campaign test money, exactly like W-6 D1b's stranded `19.000`, and it should get the same treatment: either an
explicit annotation in the evidence pack recording it as campaign contamination, or a decision from the accountant.
It must not be quietly deleted, and it must be settled **before** the fiscal evidence run rather than during it.

Also worth recording: `MTP-MLC-06`'s repair no longer depends on any of this (it now resolves its subject from live
data and skips with a reason if the tenant has no quiet location left), so this ticket is not blocking the campaign.

## Related

- `docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md` — D1b, the same "campaign test money is now
  immutable fiscal data" disposition question.
- `apps/web/e2e/money-campaign/w7-multilocation.spec.ts` — `MTP-MLC-06`'s in-code note points here; the file header's
  receipt census no longer states a fixed count or set of locations.
