# Dhouha (Baha) — Pilot Task: Visual Floor Planner for F&B Table Management

**Date:** 2026-06-15
**Author:** Adam
**Project:** IziPOS (ERP)
**Branch:** dev

---

## 1. Codebase Assessment — What Exists Today

### Backend (fully implemented)

The F&B table management backend is complete and battle-tested:

- **Domain models:** `Floor`, `Table` with full enum support (`TableStatus`: available/occupied/reserved/cleaning, `TableShape`: rectangle/circle/square)
- **Table model** already has position fields: `position_x`, `position_y`, `width`, `height` (decimal, nullable) — reserved for a visual floor planner but never used
- **Service layer:** `TableManagementService` handles CRUD, order assignment (`assignOrderToTable`), release, and status transitions — with tenant isolation and row-level locking
- **Controller + routes:** `TableController` at `routes_tables.php` — full REST: floors CRUD, tables CRUD, release, set-status
- **Kitchen Display System:** `KitchenDisplayController` at `routes_kitchen.php` — list active orders, update line status, bump, mark served
- **Order model** links to tables via `table_id`, tracks `sent_at`, `ready_at`, `served_at`, `closed_at` timestamps
- **Analytics:** `FnbMetricsData` already computes `avg_table_time_minutes`, peak hours, orders by consumption mode
- **Tests:** `TableManagementTest.php` and `KitchenDisplayTest.php` exist in `apps/api/tests/Feature/POS/`
- **Migrations:** `2026_03_13_100000_create_pos_floors_table.php`, `2026_03_13_100001_create_pos_tables_table.php`

### Frontend — Web Admin (partially implemented)

- **TableManagementPage** (`apps/web/src/features/pos/pages/TableManagementPage/TableManagementPage.tsx`): basic CRUD — list/add/delete floors and tables in a flat DataTable. No visual layout, no drag-and-drop, no live status view. This is purely an admin data-entry page.
- **TableSelector** (`apps/web/src/features/pos/components/TableSelector.tsx`): grid of table cards grouped by floor for selecting a table during order creation. Shows status badges but no spatial layout.
- **TableStatusBadge** (`apps/web/src/features/pos/atoms/TableStatusBadge.tsx`): color-coded badge component.
- **KitchenDisplayPage** (`apps/web/src/features/pos/pages/KitchenDisplayPage/KitchenDisplayPage.tsx`): real-time order card grid with WebSocket channel, line status updates, bump-to-ready.
- **FnbMetricsPanel** (`apps/web/src/features/pos/organisms/Analytics/FnbMetricsPanel.tsx`): charts for avg table time, peak hours, orders by mode.

### Frontend — Tauri POS App

- **TableSelector in POS app** (`apps/pos/src/components/atoms/TableSelector.tsx`): exists but basic
- **SQLite table repository** (`apps/pos/src/lib/db/repositories/tableRepository.ts`): offline-first cache for floors/tables
- **Table API** (`apps/pos/src/api/tableApi.ts`): fetches floors with offline fallback
- The POS app is **Tauri desktop only** — no mobile build configured (no `tauri.android.conf.json`)

### What does NOT exist

1. **No visual floor planner** — the `position_x/y`, `width/height`, `shape` fields on the Table model are populated but never rendered spatially
2. **No live floor status view** — no real-time dashboard showing which tables are occupied/available/reserved at a glance
3. **No reservation system** — `TableStatus::Reserved` exists as an enum value but there's no reservation model, no time slots, no booking
4. **No waiter assignment** — orders have `cashier_id` but no separate waiter/server concept
5. **No table-time tracking UI** — the backend computes `avg_table_time_minutes` but there's no per-table elapsed time display

---

## 2. Recommended Task: Interactive Floor Plan View + Live Table Status Dashboard

### Why this task (not option B or C)

- **Option B (Waiter mobile app):** Too large, requires mobile framework decision (React Native? Capacitor?), auth flows, offline sync — easily 4-6 weeks. Not a pilot task.
- **Option C (Android POS):** Pure Tauri-to-Android porting is infrastructure work with no product design. Doesn't test product thinking.
- **Option A (Floor planner):** The backend is 100% done. The domain model already has spatial fields. All she needs to build is the frontend. This is an ideal pilot — bounded scope, zero risk of breaking existing code, tests her ability to ship polished UI on top of a working API.

### What she builds

A **two-view floor plan feature** inside the web admin app (`apps/web/`):

#### View 1: Floor Plan Editor (admin settings)
- Visual canvas where admin drags tables onto a floor plan
- Each table rendered as its shape (rectangle/circle/square) with its number and seat count
- Drag to reposition, resize handles for dimensions
- Tables snap to grid (optional)
- Saves `position_x`, `position_y`, `width`, `height`, `shape` to existing API endpoints (`PATCH /pos/tables/{id}`)
- Floor selector tabs at top (uses existing `GET /pos/floors` data)

#### View 2: Live Floor Status Dashboard (operator view)
- Read-only floor plan showing all tables with real-time status colors:
  - Green = available, Red = occupied, Yellow = reserved, Blue = cleaning
- Occupied tables show: table number, order elapsed time (computed from `Order.opened_at`), number of items
- Click a table to see the linked order detail (order lines, total, time since open)
- Auto-refreshes via polling (every 15s) or WebSocket if available
- Floor selector tabs same as editor view

### Specific deliverables

1. **New page component:** `apps/web/src/features/pos/pages/FloorPlanPage/FloorPlanPage.tsx` — the live status dashboard
2. **New component:** `apps/web/src/features/pos/components/FloorPlanEditor.tsx` — the drag-and-drop editor (used inside existing TableManagementPage or its own route)
3. **New component:** `apps/web/src/features/pos/components/FloorPlanCanvas.tsx` — shared canvas renderer (used by both editor and live view)
4. **New component:** `apps/web/src/features/pos/components/TableNode.tsx` — individual table shape on the canvas
5. **New hook:** `apps/web/src/features/pos/hooks/useFloorPlan.ts` — manages canvas state, position updates
6. **Update existing:** `apps/web/src/features/pos/api/tableApi.ts` — add `updateTablePosition()` function (calls existing PATCH endpoint with position_x/y/width/height)
7. **Update existing:** `apps/web/src/features/pos/pages/TableManagementPage/TableManagementPage.tsx` — add link/tab to floor plan editor view
8. **Route registration** in the POS routes section
9. **Tests:** component tests for FloorPlanCanvas, TableNode, and the useFloorPlan hook
10. **i18n keys** in the existing pos translation namespace

### Modules she touches

| Module/Path | What she does | Risk |
|-------------|---------------|------|
| `apps/web/src/features/pos/` | All new frontend code lives here | LOW — new files only |
| `apps/web/src/features/pos/api/tableApi.ts` | Adds one function | LOW — additive |
| `apps/web/src/features/pos/pages/TableManagementPage/` | Adds link to editor | LOW — minor edit |
| Route config in web app | Adds 1-2 new routes | LOW |

### Modules she must NOT touch

| Module/Path | Reason |
|-------------|--------|
| `apps/api/` (entire backend) | Backend is done. She consumes existing API only. |
| `apps/pos/` (Tauri POS app) | Separate app, different architecture |
| `apps/web/src/features/pos/organisms/Analytics/` | Under active development, conflict risk |
| `apps/web/src/features/treasury/` | Active treasury phase work |
| `apps/web/src/features/expenses/` | Active expense depth work |
| `apps/web/src/features/placement/` | Just merged, fragile |
| Any backend Modules/* | Strictly frontend-only task |

---

## 3. Acceptance Criteria

### Must-have (MVP)
- [ ] Floor plan canvas renders tables as shapes at their saved positions
- [ ] Tables display their number, seat count, and status color
- [ ] Admin can drag tables to reposition them on the canvas
- [ ] Position changes persist via PATCH to `/pos/tables/{id}` (existing endpoint)
- [ ] Live view shows occupied tables with elapsed time since `opened_at`
- [ ] Clicking an occupied table shows the order summary (lines, total)
- [ ] Floor selector tabs switch between different floors
- [ ] Works correctly at 1280x800 minimum resolution
- [ ] All new components have at least one test
- [ ] All user-facing strings use i18n (no hardcoded text)
- [ ] Uses the existing design token system (`@/lib/designTokens`) — no custom colors

### Nice-to-have (stretch)
- [ ] Resize handles on tables in editor mode
- [ ] Snap-to-grid in editor mode
- [ ] Auto-refresh with polling interval selector (15s/30s/60s)
- [ ] Table count summary bar (X available, Y occupied, Z reserved)
- [ ] Subtle animation on status transitions

### Must NOT do
- No backend changes
- No new API endpoints
- No new database migrations
- No modifications to the Tauri POS app
- No reservation system (that's a separate feature)

---

## 4. Technical Guidance

### Canvas approach
Use HTML/CSS with `position: absolute` inside a relative container. The position_x/y and width/height fields map directly to CSS left/top/width/height. No need for `<canvas>` or SVG for this scope — DOM elements with drag events are simpler and more accessible.

For drag-and-drop, use the browser's native drag API or a lightweight library. The codebase does not currently use a DnD library — check `apps/web/package.json` before adding one. If adding a dependency, prefer `@dnd-kit/core` (already popular in React ecosystems) over heavier alternatives.

### Existing patterns to follow
- Look at how `KitchenDisplayPage` does real-time updates (`useKitchenChannel` hook)
- Follow the atomic design structure: atoms > molecules > organisms > pages
- Use `react-query` for data fetching (already used everywhere)
- Use the `cn()` utility for className merging
- Use the existing `FloorResource` and `TableResource` response shapes

### API endpoints she'll use
All exist and work:
```
GET    /api/v1/pos/floors          → FloorData[] (includes tables)
GET    /api/v1/pos/tables          → TableData[] (filterable by floor_id, status)
PATCH  /api/v1/pos/tables/{id}     → updates position_x, position_y, width, height, shape
POST   /api/v1/pos/tables/{id}/status → set status manually
```

For live view, the order data comes from:
```
GET    /api/v1/pos/kitchen/orders  → active orders with table info
```

### Key file references
- Domain model with all fields: `apps/api/app/Modules/POS/Domain/Table.php`
- Shape enum: `apps/api/app/Modules/POS/Domain/Enums/TableShape.php` (rectangle/circle/square)
- Status enum: `apps/api/app/Modules/POS/Domain/Enums/TableStatus.php` (available/occupied/reserved/cleaning)
- API types: `apps/web/src/features/pos/api/tableApi.ts`
- Design tokens: `apps/web/src/lib/designTokens.ts`
- Existing table hooks: `apps/web/src/features/pos/hooks/useTables.ts`
- Kitchen hooks (for pattern): `apps/web/src/features/pos/hooks/useKitchenOrders.ts`

---

## 5. What This Tests

| Capability | How |
|------------|-----|
| **Reading existing code** | She must understand the Table model, API shape, and design token system before writing anything |
| **Frontend architecture** | Must follow atomic design, react-query patterns, i18n — not freestyle |
| **UI/UX thinking** | Floor plan layout is a genuine UX challenge: how to make it intuitive, readable, fast |
| **API integration** | Consuming existing REST endpoints correctly, handling loading/error states |
| **Scope discipline** | Clear boundaries on what to touch and what not to. Will she stay in her lane? |
| **Testing** | Does she write tests or skip them? Quality of test cases |
| **Communication** | Does she ask questions when ambiguous, or make assumptions silently? |

---

## 6. Estimated Scope

| Phase | Duration | Deliverable |
|-------|----------|-------------|
| Orientation | 1 day | Read codebase, understand patterns, set up dev environment |
| Floor Plan Canvas + Table Node | 2-3 days | Static render of tables at positions, shape rendering |
| Drag-and-Drop Editor | 2 days | Repositioning, persistence via API |
| Live Status Dashboard | 2-3 days | Real-time status colors, elapsed time, order preview |
| Tests + Polish | 1-2 days | Component tests, edge cases, responsive check |
| **Total** | **8-11 working days** (~2 weeks) |

If she finishes in under 8 days with quality, she's strong. If she needs more than 12, it's a signal.

---

## 7. Onboarding Checklist (Day 1)

1. Clone the repo, switch to dev branch
2. Read `CLAUDE.md` at repo root for project conventions
3. Run the web app locally (`apps/web/`)
4. Navigate to POS > Table Management — see the current flat list
5. Navigate to POS > Kitchen Display — see the real-time cards
6. Read `apps/web/src/features/pos/api/tableApi.ts` to understand the API shape
7. Read `apps/api/app/Modules/POS/Domain/Table.php` to see the position fields
8. Create a feature branch: `feat/floor-plan-view`
9. Start with the static canvas renderer before adding interactivity
