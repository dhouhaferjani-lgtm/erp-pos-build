# Expense Flow — Demo Cutoff (Spec #1 of the Treasury end-to-end effort)

> **Date:** 2026-06-27 · **Status:** design, pending user review → writing-plans
> **Scope tier:** demo CUTOFF (ships to `dev` for the parapharmacy demo). The full money-movement spine, VAT, AP, refunds GL, bank-rec, and cash-position reporting are **deferred to the post-launch branch and folded into the accounting-GL roadmap** (separate spec).
> **Source audit:** `docs/superpowers/audits/2026-06-27-treasury-payments-audit/` (Part B / Section 11).
> **Adversarial review:** `docs/superpowers/reviews/2026-06-27-expense-flow-demo-cutoff-codex-review.md` (Codex, 2026-06-27) — findings verified and incorporated below (Rev 2).

> **Rev 2 changelog (post Codex review):** (a) policy *registration* added to the API provider, not just file relocation; (b) attachments now **reuse the existing `/documents/{id}/attachments` endpoint + `DocumentAttachments` component** (already in the detail page) via the shared `MediaServiceInterface` — no duplicate expense endpoint, no `Document::attachments()` relation; (c) FE fix expanded to repair the **role-only `usePermissions()` map** + Command Palette + Quick Create; (d) idempotency requires a **new `idempotency_key` column + unique index** (not "no new tables"); (e) re-post is **422-guarded**, not a "no-op"; (f) added `ExpenseCard` `parseFloat` cleanup; (g) preserve `EnforceTokenTenantClaim`; set `SourceDocument` role explicitly; (h) `treasury-reviewer` agent + mobile handover are **to-create** deliverables; (i) backend Expense DTOs do **not** exist — cutoff reuses existing manual TS interfaces (backend-DTO alignment deferred).

---

## 1. Problem

A multi-shop Tunisian parapharmacy owner must be able to **log an expense → label/categorize it → attach a receipt → post it to the GL**, with treasury cash-on-hand moving. Today **none of this works end-to-end** (verified firsthand):

- **Authz is dead.** `ExpenseController` authorizes via `Gate::authorize('view'|'update'|'delete'|'post', $expense)` against `App\Policies\DocumentPolicy`. The class that autoloads (PSR-4 `App\` → `apps/api/app/`) is an **all-deny stub** (`apps/api/app/Policies/DocumentPolicy.php`, every method `return false`). The **correct, complete policy exists but is misplaced** at the repo-root `apps/erp/app/Policies/DocumentPolicy.php` — **outside the api PSR-4 root, so it never loads.** Result: expense view/update/delete/**post** → **403 for every user, including `admin`**. (Other Document types dodge this because their controllers authorize via route `can:` middleware and never call the policy; only `ExpenseController` calls the policy.)
- **Expense detail 500s.** `ExpenseController::show()` eager-loads `'attachments'`, but `Document` has no `attachments()` relation (only `expenseMetadata()`).
- **Create/list are unguarded.** `index()`/`store()` call no authz and the routes carry no `can:` middleware → any authenticated user can create expenses; the seeded `expenses.*` permissions are dead.
- **Posting moves no cash.** Even if posting weren't blocked, `GeneralLedgerService::createFromExpense()` posts a balanced JE but never decrements the `payment_repositories.balance` → GL cash account and treasury balance drift.
- **Category→GL mapping is unreachable in the UI.** `expense_categories.account_id` (GL link) exists in the model but the category form exposes only name/description/parent/active, and **no categories are seeded.**
- **FE gates on the wrong permissions.** Canonical web (`apps/web/src/routes/index.tsx:1433-1492`) guards expense routes with `moduleKey="treasury"` / `permission="treasury.create"` / `"treasury.edit"` — none of which match the BE's `expenses.*`.

## 2. Goal & success criteria

A user with `expenses.*` permissions can, on demo data:

1. Create an expense (category, vendor, repository, amount as string, optional receipt).
2. Pick a category that carries a GL account; pick cash or bank repository.
3. Upload a receipt image/PDF and **see it on the expense detail**.
4. **Post** the expense → a **balanced journal entry** (Dr expense / Cr cash-or-bank) **and** the chosen `payment_repository.balance` **decreases** by the total.
5. A user **without** `expenses.*` is correctly blocked (403 on API, hidden in UI).
6. A later **mobile** "log expense" feature can consume the same API with **zero backend changes** (idempotent create + image-attach port). A one-page handover documents it.

**Depth (locked):** minimal-correct cash-out — paid-immediately only, amount booked **TTC** to the charge account (no input-VAT split), no unpaid/AP lifecycle.

## 3. Decisions (locked with owner)

| # | Decision |
|---|---|
| D1 | First spec = this expense slice; full spine later folds into the **accounting-GL roadmap** branch. |
| D2 | Include the **`payment_repository.balance` decrement** on post (the one bit of the spine the demo visibly needs). |
| D3 | **Fix the policy properly** — relocate the real `DocumentPolicy` into the api PSR-4 root; delete the all-deny stub and the orphan. |
| D4 | **Mobile-ready now:** idempotent create + attachments via the MediaAsset Document image port. |
| D5 | Depth = minimal-correct cash-out (no VAT split, no AP). |

## 4. Design

### 4.1 Authorization — relocate the real policy + close the index/store gap

- **Move** `apps/erp/app/Policies/DocumentPolicy.php` (the complete type-dispatch policy, with company-scope + per-type `expenses.*` checks for view/create/update/delete/post) **into** `apps/api/app/Policies/DocumentPolicy.php`, **overwriting the all-deny stub**; **delete** the repo-root orphan.
- **Register the policy explicitly in the API provider (Codex P0-1).** The only `Gate::policy(...)` registration today lives in the **orphan** `apps/erp/app/Providers/AppServiceProvider.php` (outside the api PSR-4 root); the API provider actually booted via `apps/api/bootstrap/providers.php` (`apps/api/app/Providers/AppServiceProvider.php`) registers **no** policies. So relocating the file is **not sufficient** — add `Gate::policy(Document::class, DocumentPolicy::class)` and `Gate::policy(ExpenseCategory::class, ExpenseCategoryPolicy::class)` to the API provider's `boot()`, and a test asserting `Gate::getPolicyFor(Document::class)` resolves the real policy. (Do not rely on auto-discovery for these custom-namespace models.)
  - This is **safe**: every other Document path authorizes via route middleware and never calls the policy, so activating real logic can only *grant where permitted*, never break an existing allow. It also fixes the same latent landmine for any future `Gate::authorize` on a Document.
- **`ExpenseController`:** keep the existing `Gate::authorize(...)` on `show/update/destroy/post` (now functional; the policy's `company_id` check supplies per-record company scoping). **Add authz to the collection endpoints**, which the policy's coarse `viewAny/create` don't cover specifically enough: gate `index` with `can:expenses.view` and `store` with `can:expenses.create` **route middleware** (matches the procurement + Document-module convention).
- **Categories:** `ExpenseCategoryController` has the same all-deny problem (`ExpenseCategoryPolicy` denies all). Fix properly with a **real `ExpenseCategoryPolicy`** (company-scope + `expense-categories.*`) relocated/registered the same way, OR — simpler and equivalent here since categories have no per-type nuance — `can:expense-categories.*` route middleware + drop the controller `Gate` calls. **Chosen: real `ExpenseCategoryPolicy`** for consistency with the Document pattern and to preserve company-scoping. (Plan checks for an existing orphan first.)
- **`ExpenseRequest::authorize()`** stays `true` (route/policy handle authz); validation unchanged.

### 4.2 Attachments — reuse the existing document-attachment path (Codex P0-2, P1)

The detail page **already** renders `DocumentAttachments` (`apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx:277-283`), which uploads/lists/deletes via `/documents/{id}/attachments` (`apps/web/src/features/documents/hooks/useAttachments.ts`). So we **reuse** that, not build a parallel expense endpoint:

- **Fix the 500:** remove the `'attachments'` eager-load in `ExpenseController::show()` **and** the Eloquent-relation `attachments` block in `ExpenseResource` (`:78-89`). `Document` has no `attachments()` relation and we will **not** add one (module-boundary rule 6). The expense payload no longer carries attachments inline — the FE fetches them separately via the existing `useAttachments` hook against `/documents/{id}/attachments`.
- **Permission reconciliation (the real decision):** the document-attachment routes are gated `documents.view` / `documents.update` (`apps/api/app/Modules/Media/routes.php:24-40`), but expense roles hold `expenses.*`. Since an Expense **is** a Document, the cleanest fix is to **grant `documents.view` + `documents.update` to the expense-capable roles** (cashier/accountant/manager/treasury) in `RolesAndPermissionsSeeder`, and add those keys to the FE permission map (§4.6). Do **not** create a second `/expenses/{id}/attachments` endpoint with divergent permissions.
- **If** any backend attach is needed (e.g. server-authored), consume the **shared** `App\Shared\Contracts\MediaServiceInterface` (`attachUpload/listForOwner/download/detach/purgeOwner`) by constructor injection — never the Media internals (`MediaUploadService`/`MediaAttachmentService`/`MediaAttachmentRepositoryInterface`).
- **Receipt role:** the document-attachment controller defaults a missing role to `MediaRole::Datasheet` (`DocumentAttachmentController.php:81-95`). For expense receipts, the FE must **pass `SourceDocument` explicitly** (the upload request already allows it). Owner/asset type: `MediaOwnerType::Document` / `MediaAssetType::Document`, S3 disk (already the default in `MediaUploadService`).
- This **is** the mobile camera-receipt path — `erp-mobile` posts a captured image to the same `/documents/{id}/attachments` endpoint with `role=SourceDocument`.

### 4.3 Post moves the treasury balance (minimal spine seam)

- In `ExpenseService::post`, **inside the same DB transaction** as `createFromExpense()`: when `is_paid` is true and `payment_repository_id` is set, **decrement** `payment_repositories.balance` by the expense `total`.
- **Precision (rule 19):** bcmath only; resolve scale via the injected `CurrencyScaleResolverInterface` passing the **document currency** (`getScale($document->currency)`) — never a no-arg `getScale()`. No float.
- **Double-post safety:** post is **422-guarded** — `ExpenseController::post()` returns 422 and `ExpenseService::post()` throws unless status is Draft (`ExpenseService.php:103-107`). So the decrement happens **exactly once** (a second post is rejected, not replayed). Deleting a **posted** expense is disallowed (only drafts deletable, `destroy():188-194`) → no balance-restore path needed in the cutoff. (Idempotency for *offline retries* applies to **create**, not post — see §4.5.)
- **Seam note (explicit):** this is the *minimal* coherence (GL + balance). The full unification (a first-class `Payment`/treasury-movement row, refunds/payouts symmetry, reversal) is **deferred to the GL-roadmap branch**. We do **not** half-build the `Payment`-row model here.

### 4.4 Category → GL mapping

- **FE:** add a **GL-account picker** (chart-of-accounts, class-6 expense accounts) to the category create/edit form (`ExpenseCategoryPage`). The model field (`expense_categories.account_id`) and DTO already exist; only the UI is missing.
- **Seed:** a small TN parapharmacy expense-category set mapped to PCG-TN **class-6** accounts (e.g. rent, utilities/energy, salaries, consumables, transport). Exact account codes confirmed in the plan against `TunisiaChartOfAccountsSeeder`. The existing fallback (`SystemAccountPurpose::GeneralExpense` → TN `65`) already covers uncategorized, so seeding adds granularity, not a blocker.

### 4.5 Mobile-readiness (cheap affordances now)

- **Idempotent create (Codex P1 — needs real storage):** Expense has **no** idempotency field today, so "no new tables" was wrong. Add an **`idempotency_key` column + a tenant-unique index** following the **stock-transfers precedent** (`2026_05_28_120000_create_stock_transfers_table.php:53-67`). Placement: on **`expense_metadata`** (keeps the `documents` table untouched). `POST /expenses` accepts a client-supplied UUID (`Idempotency-Key` header or body field, validated in `ExpenseRequest`); `ExpenseService::create()` looks it up first and returns the existing expense on replay, else inserts with the key. This is a **new migration + request field + service branch + TS field** — enumerated in the plan.
- **Image attachments:** covered by §4.2 (existing `/documents/{id}/attachments`, `role=SourceDocument`, S3) — a camera photo uploads the same way.
- **Deliverable (to create):** a one-page handover (`docs/handoff/HANDOVER-mobile-expense-logging.md`) listing endpoints, the idempotency contract, the attachment endpoint/role, and permission keys, so a parallel session builds the `erp-mobile` expense feature with no backend changes.

### 4.6 Frontend (Codex P1/P2 — broader than first drafted)

- **Fix `usePermissions()` first (load-bearing).** The hook is **role-only**: it reads `user.roles` and a hardcoded `PERMISSIONS` map, **ignores `user.permissions`**, and returns `false` for any key absent from the map (`apps/web/src/hooks/usePermissions.ts:229-239`). The map has `treasury.*` but **no `expenses.*`** keys — so guarding on `expenses.view` would deny everyone. **Add** `expenses.view/create/update/delete` and `expense-categories.view/create/update/delete` (plus `documents.view/update` from §4.2) to the map, mapped to the same roles the seeder grants them (admin/treasury/accountant/manager/cashier per `RolesAndPermissionsSeeder.php:416-623`). (Rewriting the hook to consume `user.permissions` is the better long-term fix but is out of cutoff scope — deferred.)
- **Route guards** (`apps/web/src/routes/index.tsx:1433-1492`): replace `moduleKey="treasury"` / `permission="treasury.create"` / `"treasury.edit"` with `permission="expenses.view"` / `"expenses.create"` / `"expenses.update"`; category route → `expense-categories.view`.
- **Other stale entry points (Codex P2):** Command Palette (`useCommandPalette.ts:78-95`) and Quick Create (`QuickCreateButton.tsx:57-60`) gate expenses on `moduleKey:'treasury'` / `treasury.create` → update to `expenses.*`.
- **Detail page** (`ExpenseDetailPage.tsx`): already renders `DocumentAttachments` — no new UI; just ensure `show()` no longer 500s so the page loads, and the upload control passes `role=SourceDocument`.
- **Form** (`ExpenseFormPage` / `ExpenseFormFields`): send the idempotency key on create; category select already exists (`ExpenseCategorySelect`).
- **Precision cleanup (Codex P1):** `ExpenseCard.tsx:71-80` uses `parseFloat` on money → migrate to `formatCurrency` (rule 18/19, code we're touching). Create form already uses `MoneyInput` (good).
- **i18n:** all new strings via `t()` (namespace `expenses` exists in `apps/web/src/locales/*/expenses.json`).
- **Types:** reuse the existing manual TS interfaces in `apps/web/src/features/expenses/types/index.ts` (which already include `account_id`); add only the `idempotency_key` field. **No** backend DTO / `typescript:transform` work in the cutoff (backend Expense has no DTOs today; aligning to generated types is deferred — Codex P2).

## 5. Components & boundaries

| Unit | Responsibility | Touches |
|---|---|---|
| `DocumentPolicy` (relocated) | Per-type, company-scoped Document authz | `apps/api/app/Policies/` (+ delete stub & orphan) |
| `ExpenseCategoryPolicy` (real) | Company-scoped category authz | Expense module / Policies |
| `ExpenseController` + `routes.php` | REST surface; `can:` middleware on collection ops; policy on per-record; new attachment upload route; idempotent create | Expense/Presentation |
| `ExpenseService::post` | GL post (existing) **+ balance decrement** (new), one txn, bcmath | Expense/Application |
| Media port (reuse) | Attach/list via `MediaAttachmentService` + `MediaAttachmentRepositoryInterface` | consumed, not modified |
| `ExpenseResource` | Shape metadata + attachments (from contract, not a relation) | Expense/Presentation |
| Web expense feature | Correct permission guards; attachment UI; category GL picker; idempotency key | `apps/web/src/features/expenses` + routes |
| Seeders | TN parapharmacy expense categories → class-6 GL | database/seeders |

**One new column** (`expense_metadata.idempotency_key` + tenant-unique index), **no new tables**. No `Document` model relation changes. Media consumed via the **shared `MediaServiceInterface`**; GL via its public service. Also touched: API `AppServiceProvider` (policy registration), `RolesAndPermissionsSeeder` (grant `documents.view/update` to expense roles), `usePermissions` map, Command Palette / Quick Create, `ExpenseCard` (parseFloat cleanup).

## 6. Testing (TDD — write tests first)

**Backend** (`RefreshDatabase` + real models + `RolesAndPermissionsSeeder`, run **by path**, never the full suite):
- Authz matrix: user **with** vs **without** `expenses.*` → correct codes on index/store/show/update/destroy/post; cross-company access denied; `admin` can post (regression for the 403-for-everyone bug).
- `show()` returns 200 with attachments (regression for the 500).
- Post: creates a **balanced** JE (Dr expense / Cr cash-or-bank) **and** decrements the exact `payment_repository.balance` by the total; amount is string/bcmath; re-post is a no-op (idempotent).
- Attachment upload → retrieval via the Media contract; stored on the S3 disk owner-typed `Document`/`SourceDocument`.
- Idempotent create: same key returns the same expense id; different key creates a new one.
- Category with `account_id` posts to that account; without → `GeneralExpense` fallback.

**Frontend** (Vitest; component tests may mock hooks, api tests don't fake payloads):
- Route/button gating shows for `expenses.*` holders, hidden otherwise.
- Category form GL-account picker renders + submits `account_id`.
- Detail page renders attachments + upload control.

## 7. Out of scope (→ full branch / GL roadmap)

Input-VAT split (TVA déductible); unpaid/AP lifecycle (`is_paid=false` → payable); the full money-movement spine (`Payment` rows, refunds/payouts GL symmetry, reversals); cash-position dashboard; bank-rec statement import; payment-management UX; the `account_id`/`gl_account_id` repository unification. Each is tracked in the audit's DEFERRED backlog.

## 8. Process

- **Branch:** `git worktree` off `dev` (per dev-sync discipline); TDD; merge to **local** `dev` for the demo (owner promotes to `origin/dev`).
- **Review loop:** the proven Codex-impl → adversarial-review-to-file → verify loop, gated by the new **`treasury-reviewer` agent** (`.claude/agents/treasury-reviewer.md`) — curated context: this audit, the GL-roadmap **device=SoT / GL=projection** framing, the GR-IR / PCG-TN account matrix, precision rules 19/20, POS cross-layer rule 20, the balance-sign convention. The reviewer **cites file:line and verifies against code**, and **gates** the merge; **merge stays human** (no auto-merge).

## 9. Risks & caveats

- **Policy registration (highest risk).** Relocating the policy file is not enough — it must be **registered** in the API `AppServiceProvider` (today only the orphan provider registers it). A test must assert `Gate::getPolicyFor(Document::class)` resolves the real policy, or expenses stay 403. Activating it also changes behavior for any `Gate::authorize` on a Document; mitigation: survey such callers (audit found only Expense) + regression-test other Document flows (which use middleware) are unaffected.
- **`usePermissions()` is role-only (second-highest risk).** The FE guard swap fails unless the hardcoded map gains the new keys; verify with a component test that an expense-role user sees the routes and a non-expense user does not.
- **Attachment permission alignment:** granting `documents.view/update` to expense roles widens those roles' access to *all* document attachments — acceptable (an expense is a document) but call it out; confirm no sensitive document type is exposed unintentionally.
- **Balance decrement without the full spine** means expenses and POS payouts/refunds still aren't unified — acceptable for the demo, explicitly deferred; the seam is documented so the spine doesn't double-build it.
- The **canonical FE** was confirmed (not a worktree); plan targets `apps/web/...`.

## 10. Acceptance (demo gate)

Demo passes when, on seeded parapharmacy data, an authorized user logs an expense with a category + receipt photo and posts it, the owner sees the receipt on the detail page, the journal entry balances, and the cash register's balance drops by the amount — and an unauthorized user cannot. Mobile handover doc committed.
