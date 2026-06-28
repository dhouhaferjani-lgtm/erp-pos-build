# Handover — Mobile Expense Logging (`erp-mobile`)

> **Date:** 2026-06-28 · **Status:** backend is mobile-ready; mobile feature not yet built.
> **Branch where the backend landed:** `feat/expense-flow-cutoff` (worktree `apps/erp.expense-cutoff`).
> **Spec/plan:** `docs/superpowers/specs/2026-06-27-expense-flow-demo-cutoff-design.md`, `docs/superpowers/plans/2026-06-27-expense-flow-demo-cutoff-plan.md`.

The expense flow was made to work end-to-end on web **and** deliberately built mobile-ready, so a parallel session can add "log an expense" to `erp-mobile` (Expo + expo-router + React Query + sanctum Bearer, offline-first, camera — same stack as the existing inventory **counting** feature) with **zero backend changes**. This document is the contract.

## Auth & base

- Same as the counting feature: `Bearer <sanctum token>` against `EXPO_PUBLIC_API_URL` (`/api/v1`).
- Tenant/company context is resolved server-side from the token + membership (no client company id needed beyond what counting already sends).
- The user must hold `expenses.*` permissions (roles: `admin, manager, cashier, operator, accountant`; `viewer` is read-only). Receipt upload additionally needs `documents.view`/`documents.update` (already granted to those roles).

## Endpoints

### Create an expense (idempotent)
`POST /api/v1/expenses` — gated `can:expenses.create`.

Body (money as **strings**, never floats):
```json
{
  "total": "12.500",
  "vendor_name": "Pharmacie Centrale",
  "expense_category_id": "<uuid|null>",
  "payment_method_id": "<uuid|null>",
  "payment_repository_id": "<uuid|null>",
  "payment_date": "2026-06-28",
  "is_paid": true,
  "receipt_number": "optional string",
  "notes": "optional",
  "idempotency_key": "<uuid>"
}
```
- **`idempotency_key` (uuid) is the offline-retry contract.** Generate ONE uuid per create attempt and reuse it across retries of the same logical expense (mirror the counting feature's draft pattern — e.g. store it on the draft row). Replaying the same key returns the **same** expense (HTTP 201 with the original `data.id`), creating no duplicate. Different key → new expense.
- `total` must match `^\d+(\.\d{1,3})?$` (scale-3). Send strings; never `parseFloat`.
- `is_paid: true` + a `payment_repository_id` is what makes posting later decrement that cash register's balance.
- Returns `{ data: <ExpenseResource> }` (201).

### List / show / update / delete
- `GET /api/v1/expenses` (`can:expenses.view`, paginated, filters: `status`, `category_id`, `date_from/to`, `search`).
- `GET /api/v1/expenses/{id}` (`expenses.view`) — returns the expense + `metadata` (category/method/repository) + `company`. **Attachments are NOT in this payload** — fetch them separately (below).
- `PUT|PATCH /api/v1/expenses/{id}` (`expenses.update`) — draft only.
- `DELETE /api/v1/expenses/{id}` (`expenses.delete`) — draft only.

### Post an expense (finalize → GL + cash)
`POST /api/v1/expenses/{id}/post` — gated `can:expenses.post`.
- Transitions Draft → Posted, assigns `EXP-YYYY-NNNNNN`, posts a **balanced journal entry** (Dr expense account from the category's GL account, or GeneralExpense fallback / Cr cash-or-bank by repository type), and — when `is_paid` + repository set — **decrements `payment_repositories.balance`** in the same transaction.
- Re-posting returns **422** (idempotent at the status level; the cash decrement fires exactly once).

### Categories (for the picker)
- `GET /api/v1/expense-categories` (`can:expense-categories.view`) — each carries an optional `account_id` (its GL account). TN parapharmacy categories are seeded (Loyer, Entretien, Assurances, Transport, Télécom, Divers).

### Receipt attachment (camera path)
Reuse the **shared document attachment** endpoint (an expense *is* a Document):
- Upload: `POST /api/v1/documents/{expenseId}/attachments` (gated `can:documents.update`), `multipart/form-data`:
  - `file`: the captured image/PDF (camera output works directly).
  - `role`: **`SOURCE_DOCUMENT`** (pass it explicitly; the controller otherwise defaults to `Datasheet`).
  - Stored on the S3/MinIO disk. Returns 201.
- List: `GET /api/v1/documents/{expenseId}/attachments` (gated `can:documents.view`).

## Suggested mobile flow

1. Capture receipt with `expo-camera` → hold the image locally (offline draft).
2. Fill amount (string), pick a category, choose cash/bank repository, `is_paid`.
3. On submit (online or when connectivity returns): `POST /expenses` with a draft-stable `idempotency_key`; then `POST /documents/{id}/attachments` with the image and `role=SOURCE_DOCUMENT`.
4. Optionally `POST /expenses/{id}/post` to finalize (or leave as draft for back-office posting).
5. Mirror the counting feature's `draftSyncService` / `useBackgroundSync` for the offline queue; the idempotency key makes the create safe to retry.

## What is NOT in scope (deferred to the full treasury/GL branch)

Input-VAT split on expenses (booked TTC for now), unpaid/AP lifecycle (`is_paid=false` still books cash), the full money-movement spine (Payment-row unification), and refunds GL. The mobile feature should not assume these exist.

## Known follow-ups (non-blocking, tracked in the SDD ledger)

- Idempotency dedup lookup is not company-scoped (nil risk under db-per-tenant + unguessable uuid).
- Concurrent same-key create returns 500 rather than a graceful 200 (the unique index prevents duplicates; offline retries are sequential, so unaffected).
- `documents.partner_id` was made nullable to allow partnerless expenses (shared `documents` table change — partner-requiring document types still enforce a partner at the app layer).
