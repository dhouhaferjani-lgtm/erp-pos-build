# Wave C — POS Refill Quantity Suggestion Design

- **Date:** 2026-07-19
- **Status:** Owner-approved through `docs/handoff/CODEX-replenishment-followups-2026-07-12.md`
- **Base:** local `dev` at `28c422cc8`
- **Scope:** POS replenishment pull feed, device cache, and refill-sheet default only

## Outcome

When a POS device pulls replenishment rows, every open row carries a nullable
`suggested_qty` numeric string. The refill sheet uses that value as its initial
quantity when the cached row matches the selected product and variant. The
field remains optional and fully editable; submission behavior is otherwise
unchanged.

## Server calculation

Inventory owns the stock read and quantity arithmetic. Replenishment's
`feedForLocation()` enriches its capped result set through an Inventory public
application service, preserving the repository's module-boundary rule without
importing the Inventory model.

For the requesting location and exact `(product_id, variant_id)` grain:

1. `available = quantity - reserved`, at quantity scale 4.
2. If `max_quantity` exists, suggest `max_quantity - available`.
3. Otherwise, if `min_quantity` exists, suggest `min_quantity - available`.
4. Otherwise suggest `1.0000`.
5. If the selected difference is zero or negative, floor it to `1.0000`.

Only `pending` and `in_progress` rows receive a suggestion. Recently closed
rows remain in the feedback feed with `suggested_qty: null`.

The response resource always emits the snake_case key. This keeps the
hand-authored POS wire contract exact and gives push responses a stable null
value even though the suggestion is meaningful only on the pull feed.

## Device persistence and validation

SQLite migration v61 adds nullable `TEXT` column `suggested_qty` to
`open_replenishment_cache`. A migration replay test starts from v60, applies
v61, verifies the column, preserves an existing row, and proves idempotent
re-application.

`ServerReplenishmentRow`, the pull envelope guard, the repository input, and
the cache row type all require `suggested_qty: string | null`. The runtime
guard rejects missing or non-string/non-null values so wire drift cannot be
silently cached.

## Sheet behavior

On open, the sheet loads the matching open cache row. If `suggested_qty` is
present, it initializes the quantity input with that value. If absent, the
input remains blank. A user edit wins for the rest of that open sheet; the
cache lookup must not overwrite an edit made while the async read is in
flight. Existing validation, optionality, enqueue, audit, and toast behavior
remain unchanged.

## Error handling and non-goals

- A missing stock row is valid and produces `1.0000`.
- Variant rows never fall back to product-grain stock.
- No permission, seeder, fiscal, web-dialog, backend schema, or replenishment
  capture behavior changes are included.
- Deployment requires the standard `php artisan tenants:migrate` release step
  from the handoff plus a POS device update so SQLite v61 runs. Wave C adds no
  tenant schema or permission changes, so no seeder or permission-cache reset
  is required.
