# POS Terminal Flow: Receipt vs Order Mode

> **Status**: Planned (deferred to post-launch)
> **Decision date**: 2026-03-12

## Context

F&B clients have two operational profiles:

- **Light F&B** (coffee shops, takeaway): Direct checkout, no table management. Receipt-based flow identical to retail.
- **Full-service F&B** (restaurants, dine-in): Order lifecycle with table assignment, kitchen display, and service timing.

Both web and desktop currently use receipt-based flow only. The order system (orders, KDS, tables) is fully built on the backend and has frontend components ready, but is not wired into either checkout experience.

## Decision

Add a `flow` field to `pos_terminals` so each terminal can independently operate in receipt or order mode. This is terminal-level (not company-level) because a restaurant may have a counter terminal for takeaway (receipt) and table-service terminals (order).

## Terminal Flow Enum

```php
enum TerminalFlow: string {
    case Receipt = 'receipt';  // Direct cart → receipt → payment
    case Order = 'order';      // Cart → order → kitchen → served → close → receipt
}
```

Default: `receipt` (backward-compatible, no behavior change for existing terminals).

## Implementation Plan

### Backend (migration + enum + API)

1. Create `TerminalFlow` enum at `Domain/Enums/TerminalFlow.php`
2. Migration: add `flow` column to `pos_terminals` (string, default `'receipt'`)
3. Add to `Terminal.php` model (fillable, cast)
4. Expose in `TerminalResource` response
5. Add `flow` to terminal update validation (admin can change it)

### Web Integration

In `POSTransactions.tsx`, branch on `webTerminal.flow`:
- `receipt` → current behavior (unchanged)
- `order` → new checkout flow:
  1. On "checkout", create order via `createOrder()` (with optional `table_id`)
  2. Add cart items as order lines via `addOrderLine()`
  3. Optionally send to kitchen
  4. On payment, close order via `closeOrder()` (which creates the receipt)
  5. Process payment on the generated receipt

The `TableSelector`, `ActiveOrdersBoard`, `OrderPanel`, and `KitchenDisplayPage` components are already built and ready to compose.

### Desktop Integration

Same pattern in `HomePage.tsx`:
- Check `terminal.flow` from `terminalStore`
- Branch checkout logic identically to web
- Reuse same API endpoints and flow

### Admin UI

Add a "Terminal Mode" selector to terminal settings (web admin):
- Only visible when company vertical is F&B
- Dropdown: "Quick Service (Receipt)" / "Full Service (Orders & Tables)"
- Persists via `PATCH /pos/terminals/{id}` with `{ flow: 'order' }`

## Current State of Prerequisites

| Component | Status |
|-----------|--------|
| Order CRUD API | Done |
| Table management API | Done |
| KDS API + WebSocket events | Done |
| Frontend: TableSelector | Done |
| Frontend: KitchenDisplayPage | Done |
| Frontend: ActiveOrdersBoard | Done |
| Frontend: OrderPanel + Mark Served | Done |
| Frontend: KitchenOrderCard + Timer | Done |
| Table/Kitchen i18n (en/fr) | Done |
| `TerminalFlow` enum + migration | **Not started** |
| Web checkout flow branching | **Not started** |
| Desktop checkout flow branching | **Not started** |
| Admin UI for flow selector | **Not started** |

## Notes

- The `closeOrder()` endpoint already creates a receipt under the hood — no duplicate receipt logic needed
- Table assignment happens at order creation time (`table_id` param), table is released on close/cancel
- KDS page (`/pos/kitchen`) works independently of the checkout flow — it just shows orders with `sent_to_kitchen` or `ready` status
- Orders page (`/pos/orders`) with the kanban board also works independently
