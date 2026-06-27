# Visual verification — per-unit quantity step (live app)

**Verdict: PASS** — ran the real app (my worktree FE + worktree backend) against real tenant data and observed the quantity stepper honour each product's unit precision.

## Method

The shared dev DB (`autoerp:5433`) was being actively wiped by a parallel PG test suite, and the seeded pharmabio/cafe-tunis tenant lives only in the internal Docker `erp-dev` stack (db-per-tenant, no host-published ports, old code). To run **my** changes against that data without disrupting the running stack:

- Port-forwarded the Docker Postgres to the host with a socat sidecar on the `erp-dev_default` network (`127.0.0.1:5434 → erp-dev-postgres-1:5432`).
- Ran the worktree backend in db-per-tenant mode against it (`DB_DATABASE=pharmabio_tn_central`, `TENANCY_DB_PER_TENANT=true`), and the worktree Vite dev server (`:5173`, proxy → `:8010`).
- Logged in as `owner@cafe-tunis.tn` (the actual seeded tenant in that DB; `owner@pharmabio.tn` had been reseeded as cafe-tunis).
- Two cafe-tunis products (which had no unit) were temporarily assigned units to exercise the feature, then reverted to NULL.

Environment gotchas hit and solved (all infra, not code): Redis off → array/file/sync drivers; SPA session auth doesn't carry the tenant claim in db-per-tenant → removed `SANCTUM_STATEFUL_DOMAINS` so the FE uses the Bearer token (which does); `php artisan serve` leaked a broken-pipe `Notice` into JSON responses → ran the built-in server with a custom no-logging router.

## Steps (against the running app)

1. ✅ Logged in as `owner@cafe-tunis.tn` → dashboard + invoice editor loaded; company "Cafe Tunis" resolved (db-per-tenant tenant switch working).
2. ✅ New invoice → searched "Flour" → added **All-Purpose Flour (5kg)** (assigned to `pc`, decimal_places 0) → qty input rendered `aria-label="Qty"`, **`step="1"`**. (Pre-fix this was `0.0001`.)
3. ✅ Searched "Almond" → added **Almond Milk (1L)** (assigned to `l`, decimal_places 3) → qty input **`step="0.001"`**.
4. 🔍 Searched "Coffee" → added **Bag of Coffee Beans 250g** (NO unit assigned) → qty input **`step="0.0001"`** — the fallback path holds when a product has no unit.

Screenshot: `2026-06-27-qty-step-visual-verification.png` (invoice with the Flour + Almond Milk lines, real cafe-tunis tenant, user Ahmed Ben Ali).

## Findings

- The backend API also verified directly: `GET /products?search=Flour` → `quantity_decimals: 0`; `?search=Almond` → `quantity_decimals: 3` (clean 200s).
- The other number input on each line (`step="0.001"`, no aria-label) is the TND **unit-price MoneyInput** at currency scale 3 — expected, not the quantity.
- Not exercised live (covered by automated tests instead): the saved-document reload path, stock-adjustment/expiry/stock-transfer screens, and the post/convert response paths — these need their own seeded data and were verified via the BE contract tests + Codex review fixes.
