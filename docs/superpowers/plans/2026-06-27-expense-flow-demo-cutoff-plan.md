# Expense Flow Demo Cutoff — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the expense flow work end-to-end — a parapharmacy clerk can log → categorize (with GL mapping) → attach a receipt → post an expense, posting a balanced journal entry **and** decrementing the treasury cash balance — built mobile-ready.

**Architecture:** Backend = Laravel modular (hexagonal). The fixes are: repair the misplaced/unregistered `DocumentPolicy`, gate the unguarded collection endpoints, stop the `show()` 500, add a boundary-respecting Treasury outflow port that the expense post calls to move cash, add idempotent create, align attachment permissions, and seed categories. Frontend = React/Vite: fix the role-only `usePermissions()` map, the route/palette/quick-create guards, add the category GL picker, and wire idempotency + the receipt role.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PostgreSQL (db-per-tenant), Spatie permissions, PHPUnit; React 19 / TS strict / TanStack Query / Vitest.

## Global Constraints

- **Precision (rule 19):** money is `numeric-string` + bcmath only. Any new money arithmetic resolves scale via injected `App\Shared\Contracts\CurrencyScaleResolverInterface::getScale($currency)` — **never** the no-arg `getScale()` (it throws outside request context). No `(float)`/`parseFloat`/`Number()` on money.
- **Constructor injection only** (`private readonly`); never `app()`.
- **Module boundaries (rule 6):** cross-module only via `App\Shared\Contracts\*`, events, or a module's public service. Never import another module's model directly. (Expense must **not** import `Treasury\Domain\PaymentRepository` or Media internals.)
- **Strict typing:** no `mixed` / no `any`. Enums for status/type.
- **i18n (rule 11):** all new FE strings via `t()`.
- **Tests:** TDD. Backend tests run **by path** (`php artisan test <path>`), **never** the full suite. Use `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`.
- **Branch discipline:** work in a `git worktree` off `dev`; commit per task; merge to **local** `dev` for the demo (owner promotes to `origin/dev`).
- **Spec:** `docs/superpowers/specs/2026-06-27-expense-flow-demo-cutoff-design.md` (Rev 2). **Review:** `docs/superpowers/reviews/2026-06-27-expense-flow-demo-cutoff-codex-review.md`.

---

## Pre-execution setup (no test cycle)

**S0 — Create the worktree** via `superpowers:using-git-worktrees` off `dev` (e.g. `../erp.expense-cutoff` on branch `feat/expense-flow-cutoff`).

**S1 — Create the `treasury-reviewer` agent** at `.claude/agents/treasury-reviewer.md`. It is the adversarial review gate for every task below (cite file:line, verify against code, **gate** — never auto-merge). Seed its system prompt with: the treasury audit (`docs/superpowers/audits/2026-06-27-treasury-payments-audit/`), the GL-roadmap **device=SoT / GL=projection** framing (`project_accounting_gl_roadmap`), the GR-IR / PCG-TN account matrix, precision rules 19/20, POS cross-layer rule 20, the balance-sign convention, and this spec's seams. Frontmatter: `name: treasury-reviewer`, `description: Adversarial reviewer for treasury/payments/expense/GL changes`, `tools: Read, Grep, Glob, Bash`.

---

## Task 1: Register & relocate the real `DocumentPolicy` / `ExpenseCategoryPolicy`

Root cause: the api PSR-4 `App\` → `apps/api/app/`, so `App\Policies\DocumentPolicy` loads the **all-deny stub**. The complete policy and its `Gate::policy(...)` registration both live in the **orphan** repo-root provider/policies, outside the api root. Fix = copy the real policies into the api root and register them in the **api** `AppServiceProvider`.

**Files:**
- Modify: `apps/api/app/Policies/DocumentPolicy.php` (overwrite stub with the real type-dispatch policy)
- Create: `apps/api/app/Policies/ExpenseCategoryPolicy.php` (real company-scoped policy)
- Modify: `apps/api/app/Providers/AppServiceProvider.php` (register the two policies in `boot()`)
- Test: `apps/api/tests/Feature/Expense/ExpensePolicyTest.php`
- Reference (source of the real logic): `apps/erp/app/Policies/DocumentPolicy.php`, `apps/erp/app/Providers/AppServiceProvider.php:55-63` (`registerPolicies()`)

**Interfaces:**
- Produces: working `Gate::authorize('view'|'update'|'delete'|'post', $expenseDocument)` semantics keyed on `expenses.*` permissions + `company_id` scope; consumed by `ExpenseController` (already calls these).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Policies\DocumentPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class ExpensePolicyTest extends TestCase
{
    public function test_gate_resolves_the_real_document_policy(): void
    {
        $this->assertInstanceOf(DocumentPolicy::class, Gate::getPolicyFor(Document::class));
    }

    public function test_user_with_expenses_view_can_view_expense_document(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.view']);
        $expense = Document::factory()->create([
            'type' => DocumentType::Expense,
            'company_id' => $company->id,
            'tenant_id' => $user->tenant_id,
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('view', $expense));
    }

    public function test_user_without_expenses_view_cannot_view_expense_document(): void
    {
        [$user, $company] = $this->makeUserWithPermissions([]); // no expense perms
        $expense = Document::factory()->create([
            'type' => DocumentType::Expense,
            'company_id' => $company->id,
            'tenant_id' => $user->tenant_id,
        ]);

        $this->assertFalse(Gate::forUser($user)->allows('view', $expense));
    }
}
```

> Use the existing test helper/trait for building a permissioned user + company in this suite. If none exists, build the user via the `RolesAndPermissionsSeeder` roles (e.g. assign the `accountant` role which carries `expenses.*`) rather than ad-hoc permissions. Implement `makeUserWithPermissions()` as a small private helper in the test using `User::factory()` + Spatie `givePermissionTo()`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpensePolicyTest.php`
Expected: FAIL — `Gate::getPolicyFor` returns the all-deny stub (or null), `allows('view')` is false even for the permitted user.

- [ ] **Step 3: Overwrite the stub policy with the real one**

Replace the entire body of `apps/api/app/Policies/DocumentPolicy.php` with the complete type-dispatch policy from `apps/erp/app/Policies/DocumentPolicy.php` (the version with `viewAny/view/create/update/delete/post`, each `match ($document->type)` including `DocumentType::Expense => $user->can('expenses.*')`, and the `$document->company_id !== $user->company_id` guard). Keep `namespace App\Policies;` and `declare(strict_types=1);`.

- [ ] **Step 4: Create the real `ExpenseCategoryPolicy`**

```php
<?php
declare(strict_types=1);

namespace App\Policies;

use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Identity\Domain\User;

class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('expense-categories.view');
    }

    public function view(User $user, ExpenseCategory $category): bool
    {
        return $category->company_id === $user->company_id && $user->can('expense-categories.view');
    }

    public function create(User $user): bool
    {
        return $user->can('expense-categories.create');
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        return $category->company_id === $user->company_id && $user->can('expense-categories.update');
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return $category->company_id === $user->company_id && $user->can('expense-categories.delete');
    }
}
```

- [ ] **Step 5: Register both policies in the API provider's `boot()`**

In `apps/api/app/Providers/AppServiceProvider.php`, add the imports and a `registerPolicies()` call from `boot()`:

```php
use App\Modules\Document\Domain\Document;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Policies\DocumentPolicy;
use App\Policies\ExpenseCategoryPolicy;
use Illuminate\Support\Facades\Gate;
```

In `boot()` add `$this->registerPolicies();` and define:

```php
private function registerPolicies(): void
{
    Gate::policy(Document::class, DocumentPolicy::class);
    Gate::policy(ExpenseCategory::class, ExpenseCategoryPolicy::class);
}
```

- [ ] **Step 6: Delete the orphans**

Remove `apps/erp/app/Policies/DocumentPolicy.php`, `apps/erp/app/Policies/ExpenseCategoryPolicy.php`, and the now-duplicate `registerPolicies()` block in `apps/erp/app/Providers/AppServiceProvider.php` (delete the repo-root `app/` policy artifacts so the smoking-gun can't mislead again). Verify nothing in `apps/api/` imports from the repo-root path: `grep -rn "erp/app/Policies" apps/api || true`.

- [ ] **Step 7: Run tests to verify they pass**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpensePolicyTest.php`
Expected: PASS (all three).

- [ ] **Step 8: Regression — other Document flows unaffected**

Run the existing invoice/quote authz tests by path (they authorize via route middleware, so activating the policy must not change them):
Run: `cd apps/api && php artisan test tests/Feature/Document`
Expected: PASS (no regressions).

- [ ] **Step 9: Commit**

```bash
git add apps/api/app/Policies/DocumentPolicy.php apps/api/app/Policies/ExpenseCategoryPolicy.php apps/api/app/Providers/AppServiceProvider.php apps/api/tests/Feature/Expense/ExpensePolicyTest.php
git rm app/Policies/DocumentPolicy.php app/Policies/ExpenseCategoryPolicy.php
git add app/Providers/AppServiceProvider.php
git commit -m "fix(expense): register & relocate real Document/ExpenseCategory policies into API root"
```

---

## Task 2: Gate the unguarded collection endpoints

`index()`/`store()` (and the category collection routes) carry no authz. Add `can:` route middleware while preserving the full existing middleware stack.

**Files:**
- Modify: `apps/api/app/Modules/Expense/routes.php`
- Test: `apps/api/tests/Feature/Expense/ExpenseAuthorizationTest.php`

**Interfaces:**
- Consumes: the seeded `expenses.*` / `expense-categories.*` permissions.

- [ ] **Step 1: Write the failing test**

```php
public function test_list_expenses_requires_expenses_view(): void
{
    [$user] = $this->makeUserWithPermissions([]); // no perms
    $this->actingAs($user)
        ->getJson('/api/v1/expenses')
        ->assertForbidden();
}

public function test_create_expense_requires_expenses_create(): void
{
    [$user] = $this->makeUserWithPermissions(['expenses.view']); // view but not create
    $this->actingAs($user)
        ->postJson('/api/v1/expenses', ['total' => '10.000'])
        ->assertForbidden();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpenseAuthorizationTest.php`
Expected: FAIL — endpoints currently return 200/201/422, not 403.

- [ ] **Step 3: Add `can:` middleware to the routes (preserve the stack)**

Replace the route group body in `apps/api/app/Modules/Expense/routes.php` (keep the `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` group):

```php
// Expense documents
Route::get('expenses', [ExpenseController::class, 'index'])->middleware('can:expenses.view');
Route::post('expenses', [ExpenseController::class, 'store'])->middleware('can:expenses.create');
Route::get('expenses/{id}', [ExpenseController::class, 'show'])->name('expenses.show');
Route::match(['put', 'patch'], 'expenses/{id}', [ExpenseController::class, 'update'])->name('expenses.update');
Route::delete('expenses/{id}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
Route::post('expenses/{id}/post', [ExpenseController::class, 'post'])->name('expenses.post');

// Expense categories
Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->middleware('can:expense-categories.view');
Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->middleware('can:expense-categories.create');
Route::get('expense-categories/{id}', [ExpenseCategoryController::class, 'show'])->name('expense-categories.show');
Route::match(['put', 'patch'], 'expense-categories/{id}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
Route::delete('expense-categories/{id}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');
```

> `show/update/destroy/post` keep their controller `Gate::authorize(...)` (now functional via Task 1), which also enforces per-record company scope. Collection ops get explicit `can:` middleware. Category show/update/destroy rely on the new `ExpenseCategoryPolicy` via the controller's existing `Gate::authorize` calls.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpenseAuthorizationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Expense/routes.php apps/api/tests/Feature/Expense/ExpenseAuthorizationTest.php
git commit -m "fix(expense): gate collection endpoints with can:expenses.* middleware"
```

---

## Task 3: Stop the `show()` 500 (drop the phantom `attachments` relation)

`show()` eager-loads `'attachments'` and `ExpenseResource` maps it, but `Document` has no such relation → `RelationNotFoundException` 500. Attachments are served separately by the existing `/documents/{id}/attachments` path (Task 7 + FE), so remove the inline load.

**Files:**
- Modify: `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:128-134` (remove `'attachments'` from the `with([...])`)
- Modify: `apps/api/app/Modules/Expense/Presentation/Resources/ExpenseResource.php:78-89` (remove the `attachments` block)
- Test: `apps/api/tests/Feature/Expense/ExpenseShowTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_show_returns_200_for_authorized_user(): void
{
    [$user, $company] = $this->makeUserWithPermissions(['expenses.view']);
    $expense = $this->makeExpense($company, $user); // helper: Document(type=Expense)+ExpenseMetadata

    $this->actingAs($user)
        ->getJson("/api/v1/expenses/{$expense->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $expense->id);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpenseShowTest.php`
Expected: FAIL with 500 (`RelationNotFoundException: attachments`).

- [ ] **Step 3: Remove the `attachments` eager-load**

In `ExpenseController::show()`, change the `with([...])` to:

```php
->with([
    'expenseMetadata.category',
    'expenseMetadata.paymentMethod',
    'expenseMetadata.paymentRepository',
    'company',
])
```

- [ ] **Step 4: Remove the `attachments` block from the resource**

Delete the `'attachments' => ...` mapping (lines ~78-89) in `apps/api/app/Modules/Expense/Presentation/Resources/ExpenseResource.php`. The FE fetches attachments via `useAttachments` against `/documents/{id}/attachments`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpenseShowTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php apps/api/app/Modules/Expense/Presentation/Resources/ExpenseResource.php apps/api/tests/Feature/Expense/ExpenseShowTest.php
git commit -m "fix(expense): drop phantom attachments relation that 500'd expense show"
```

---

## Task 4: Treasury outflow port (boundary-respecting cash decrement)

Expense must reduce a `payment_repositories.balance` without importing the Treasury model. Add a shared contract + a Treasury service implementation, bound in the Treasury provider.

**Files:**
- Create: `apps/api/app/Shared/Contracts/Treasury/RepositoryOutflowInterface.php`
- Create: `apps/api/app/Modules/Treasury/Application/Services/RepositoryOutflowService.php`
- Modify: `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php` (bind interface → service)
- Test: `apps/api/tests/Feature/Treasury/RepositoryOutflowServiceTest.php`

**Interfaces:**
- Produces: `RepositoryOutflowInterface::applyOutflow(string $repositoryId, string $tenantId, string $companyId, string $amount, string $currency): void` — loads the repo scoped to tenant+company, decrements `balance` by `$amount` (bcmath at `getScale($currency)`), saves, fires `RepositoryBalanceChanged` after commit.

- [ ] **Step 1: Write the failing test**

```php
public function test_apply_outflow_decrements_repository_balance(): void
{
    [$user, $company] = $this->makeUserWithPermissions([]);
    $repo = PaymentRepository::factory()->create([
        'tenant_id' => $user->tenant_id,
        'company_id' => $company->id,
        'balance' => '100.000',
        'type' => RepositoryType::CashRegister,
    ]);

    app(RepositoryOutflowInterface::class)->applyOutflow(
        $repo->id, $user->tenant_id, $company->id, '30.000', 'TND'
    );

    $this->assertSame('70.000', $repo->fresh()->balance);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Treasury/RepositoryOutflowServiceTest.php`
Expected: FAIL — interface unbound / class missing.

- [ ] **Step 3: Define the contract**

```php
<?php
declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

interface RepositoryOutflowInterface
{
    /**
     * Decrement a payment repository's balance (cash/bank out).
     *
     * @param  numeric-string  $amount
     */
    public function applyOutflow(
        string $repositoryId,
        string $tenantId,
        string $companyId,
        string $amount,
        string $currency,
    ): void;
}
```

- [ ] **Step 4: Implement the service**

```php
<?php
declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\Events\RepositoryBalanceChanged;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\RepositoryOutflowInterface;
use Illuminate\Support\Facades\DB;

final class RepositoryOutflowService implements RepositoryOutflowInterface
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function applyOutflow(
        string $repositoryId,
        string $tenantId,
        string $companyId,
        string $amount,
        string $currency,
    ): void {
        $scale = $this->scaleResolver->getScale($currency);

        DB::transaction(function () use ($repositoryId, $tenantId, $companyId, $amount, $currency, $scale): void {
            /** @var PaymentRepository $repo */
            $repo = PaymentRepository::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereKey($repositoryId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var numeric-string $current */
            $current = $repo->balance ?? '0';
            $previous = $current;
            $repo->balance = bcsub($current, $amount, $scale);
            $repo->save();

            $new = $repo->balance;
            DB::afterCommit(function () use ($repo, $tenantId, $companyId, $previous, $new, $amount, $currency): void {
                event(new RepositoryBalanceChanged(
                    repositoryId: $repo->id,
                    tenantId: $tenantId,
                    companyId: $companyId,
                    previousBalance: $previous,
                    newBalance: $new,
                    changeAmount: $amount,
                    currency: $currency,
                    changedAt: now()->toIso8601String(),
                ));
            });
        });
    }
}
```

> Confirm the `RepositoryBalanceChanged` constructor signature against `apps/api/app/Modules/Treasury/Domain/Events/RepositoryBalanceChanged.php` and adjust argument names if they differ.

- [ ] **Step 5: Bind the interface in the Treasury provider**

In `TreasuryServiceProvider::register()`:

```php
$this->app->bind(
    \App\Shared\Contracts\Treasury\RepositoryOutflowInterface::class,
    \App\Modules\Treasury\Application\Services\RepositoryOutflowService::class,
);
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd apps/api && php artisan test tests/Feature/Treasury/RepositoryOutflowServiceTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Shared/Contracts/Treasury/RepositoryOutflowInterface.php apps/api/app/Modules/Treasury/Application/Services/RepositoryOutflowService.php apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php apps/api/tests/Feature/Treasury/RepositoryOutflowServiceTest.php
git commit -m "feat(treasury): add RepositoryOutflow port for boundary-safe cash decrement"
```

---

## Task 5: Decrement the treasury balance when an expense is posted

`ExpenseService::post` posts the GL but moves no cash. Inject the outflow port and decrement within the post transaction when the expense is paid and has a repository.

**Files:**
- Modify: `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php`
- Test: `apps/api/tests/Feature/Expense/ExpensePostTest.php`

**Interfaces:**
- Consumes: `RepositoryOutflowInterface::applyOutflow(...)` (Task 4).

- [ ] **Step 1: Write the failing test**

```php
public function test_posting_paid_expense_decrements_repository_balance(): void
{
    [$user, $company] = $this->makeUserWithPermissions(['expenses.post']);
    $repo = PaymentRepository::factory()->create([
        'tenant_id' => $user->tenant_id, 'company_id' => $company->id,
        'balance' => '500.000', 'type' => RepositoryType::CashRegister,
    ]);
    $expense = $this->makeExpense($company, $user, [
        'total' => '40.000', 'is_paid' => true, 'payment_repository_id' => $repo->id,
    ]);

    app(ExpenseService::class)->post($expense, $user);

    $this->assertSame('460.000', $repo->fresh()->balance);
    // GL still balanced:
    $this->assertDatabaseHas('journal_entries', ['source_type' => 'expense', 'source_id' => $expense->id]);
}

public function test_reposting_returns_422_and_does_not_double_decrement(): void
{
    [$user, $company] = $this->makeUserWithPermissions(['expenses.post']);
    $repo = PaymentRepository::factory()->create([
        'tenant_id' => $user->tenant_id, 'company_id' => $company->id,
        'balance' => '500.000', 'type' => RepositoryType::CashRegister,
    ]);
    $expense = $this->makeExpense($company, $user, [
        'total' => '40.000', 'is_paid' => true, 'payment_repository_id' => $repo->id,
    ]);

    app(ExpenseService::class)->post($expense, $user);
    $this->actingAs($user)->postJson("/api/v1/expenses/{$expense->id}/post")->assertStatus(422);

    $this->assertSame('460.000', $repo->fresh()->balance); // unchanged by the second post
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpensePostTest.php`
Expected: FAIL — balance stays `500.000` (no decrement).

- [ ] **Step 3: Inject the port and decrement in `post()`**

Add to the constructor:

```php
public function __construct(
    private readonly GeneralLedgerService $glService,
    private readonly \App\Shared\Contracts\Treasury\RepositoryOutflowInterface $repositoryOutflow,
) {}
```

In `post()`, after `$this->glService->createFromExpense($expense, $user);` and still inside the `DB::transaction`:

```php
$metadata = $expense->expenseMetadata;
if ($metadata?->is_paid && $metadata->payment_repository_id !== null) {
    $this->repositoryOutflow->applyOutflow(
        repositoryId: $metadata->payment_repository_id,
        tenantId: $expense->tenant_id,
        companyId: $expense->company_id,
        amount: (string) $expense->total,
        currency: (string) $expense->currency,
    );
}
```

> The 422 double-post guard already exists (`status !== Draft` throws). No new guard needed; the test asserts it holds.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpensePostTest.php`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Expense/Application/Services/ExpenseService.php apps/api/tests/Feature/Expense/ExpensePostTest.php
git commit -m "feat(expense): decrement treasury balance on post via RepositoryOutflow port"
```

---

## Task 6: Idempotent expense create

Add an `idempotency_key` to `expense_metadata` (db-per-tenant → unique within the tenant DB), accept it in the request, and dedupe in the service.

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_06_27_120000_add_idempotency_key_to_expense_metadata.php`
- Modify: `apps/api/app/Modules/Expense/Domain/ExpenseMetadata.php` (fillable + property)
- Modify: `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php` (validation)
- Modify: `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php` (dedup branch)
- Test: `apps/api/tests/Feature/Expense/ExpenseIdempotencyTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_same_idempotency_key_returns_same_expense(): void
{
    [$user, $company] = $this->makeUserWithPermissions(['expenses.create']);
    $key = (string) \Illuminate\Support\Str::uuid();
    $payload = ['total' => '12.500', 'is_paid' => true, 'idempotency_key' => $key];

    $first = $this->actingAs($user)->postJson('/api/v1/expenses', $payload)->assertCreated()->json('data.id');
    $second = $this->actingAs($user)->postJson('/api/v1/expenses', $payload)->assertCreated()->json('data.id');

    $this->assertSame($first, $second);
    $this->assertSame(1, \App\Modules\Document\Domain\Document::query()
        ->where('type', \App\Modules\Document\Domain\Enums\DocumentType::Expense)->count());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpenseIdempotencyTest.php`
Expected: FAIL — two distinct expenses created.

- [ ] **Step 3: Migration**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table) {
            $table->string('idempotency_key', 128)->nullable()->after('vendor_name');
            // db-per-tenant: uniqueness within the tenant DB is tenant-scoped.
            $table->unique('idempotency_key', 'expense_metadata_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table) {
            $table->dropUnique('expense_metadata_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
```

- [ ] **Step 4: Add to `ExpenseMetadata` fillable + docblock property**

Add `'idempotency_key'` to the `$fillable` array and `@property string|null $idempotency_key` to the class docblock in `apps/api/app/Modules/Expense/Domain/ExpenseMetadata.php`.

- [ ] **Step 5: Accept the key in the request**

In `ExpenseRequest::rules()` add:

```php
'idempotency_key' => ['nullable', 'string', 'uuid'],
```

- [ ] **Step 6: Dedup in the service**

At the top of `ExpenseService::create()`, before the transaction:

```php
$idempotencyKey = $data['idempotency_key'] ?? null;
if ($idempotencyKey !== null) {
    $existing = ExpenseMetadata::query()->where('idempotency_key', $idempotencyKey)->first();
    if ($existing !== null) {
        /** @var Document $doc */
        $doc = Document::query()->whereKey($existing->document_id)->firstOrFail();
        return $doc->load('expenseMetadata');
    }
}
```

And include `'idempotency_key' => $idempotencyKey,` in the `ExpenseMetadata::create([...])` array.

- [ ] **Step 7: Run tests to verify they pass**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpenseIdempotencyTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add apps/api/database/migrations/tenant/2026_06_27_120000_add_idempotency_key_to_expense_metadata.php apps/api/app/Modules/Expense/Domain/ExpenseMetadata.php apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php apps/api/app/Modules/Expense/Application/Services/ExpenseService.php apps/api/tests/Feature/Expense/ExpenseIdempotencyTest.php
git commit -m "feat(expense): idempotent create via expense_metadata.idempotency_key"
```

---

## Task 7: Grant `documents.view`/`documents.update` to expense-capable roles (attachments)

The receipt UI uses `/documents/{id}/attachments` (gated `documents.view`/`documents.update`). Expense roles need those permissions to upload/list receipts. An Expense *is* a Document, so this is the correct grant.

**Files:**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (add `documents.view`/`documents.update` to the roles that already receive `expenses.*`: accountant, manager, treasury, cashier as applicable)
- Test: `apps/api/tests/Feature/Expense/ExpenseAttachmentPermissionTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_expense_role_can_upload_receipt_to_document(): void
{
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    [$user, $company] = $this->makeUserWithRole('accountant'); // carries expenses.*
    $expense = $this->makeExpense($company, $user);

    $this->actingAs($user)
        ->postJson("/api/v1/documents/{$expense->id}/attachments", [
            'file' => \Illuminate\Http\Testing\File::create('receipt.pdf', 10),
            'role' => 'SOURCE_DOCUMENT',
        ])
        ->assertSuccessful();
}
```

> Confirm the exact upload field name + role token against `UploadDocumentMediaRequest` and `DocumentAttachmentController`; adjust the payload to match. Use `Storage::fake('s3')` in the test.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpenseAttachmentPermissionTest.php`
Expected: FAIL with 403 (accountant lacks `documents.update`).

- [ ] **Step 3: Add the grants in the seeder**

For each role that receives `expenses.*` (find the `givePermissionTo([... 'expenses.view', ...])` blocks at `RolesAndPermissionsSeeder.php:416-623`), add `'documents.view'` and `'documents.update'` to that role's permission list. Ensure those permission names exist in the permission-creation list (they are referenced by Document routes, so they should already be seeded — verify with a `grep`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpenseAttachmentPermissionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/seeders/RolesAndPermissionsSeeder.php apps/api/tests/Feature/Expense/ExpenseAttachmentPermissionTest.php
git commit -m "feat(expense): grant documents.view/update to expense roles for receipt attachments"
```

---

## Task 8: Seed TN parapharmacy expense categories with GL accounts

Categories table ships empty. Seed a small set, each linked to an existing PCG-TN class-6 account, so posting produces per-category granularity (fallback already lands on GeneralExpense/65).

**Files:**
- Create: `apps/api/database/seeders/ExpenseCategorySeeder.php`
- Modify: the parapharmacy seeders (`TunisianParapharmacySeeder.php` / `ParapharmacySeeder.php`) to `$this->call(ExpenseCategorySeeder::class, ...)` per company
- Test: `apps/api/tests/Feature/Expense/ExpenseCategorySeederTest.php`

- [ ] **Step 1: Confirm the available class-6 account codes**

Run: `grep -nE "'6[0-9]{2,3}'|GeneralExpense" apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php`
Note the codes that actually exist (e.g. rent, utilities, supplies). Build the `name => code` map in Step 3 from codes that are present; for any category without a dedicated account, resolve to the `GeneralExpense` purpose.

- [ ] **Step 2: Write the failing test**

```php
public function test_seeded_categories_have_valid_gl_accounts(): void
{
    [$user, $company] = $this->makeUserWithPermissions([]);
    $this->seed(\Database\Seeders\TunisiaChartOfAccountsSeeder::class); // or however COA is seeded for the company
    app(\Database\Seeders\ExpenseCategorySeeder::class)->seedForCompany($company);

    $categories = \App\Modules\Expense\Domain\ExpenseCategory::where('company_id', $company->id)->get();
    $this->assertGreaterThanOrEqual(4, $categories->count());
    foreach ($categories as $cat) {
        $this->assertNotNull($cat->account_id);
        $this->assertDatabaseHas('accounts', ['id' => $cat->account_id, 'company_id' => $company->id]);
    }
}
```

- [ ] **Step 3: Implement the seeder**

```php
<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Expense\Domain\ExpenseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ExpenseCategorySeeder extends Seeder
{
    /** name => PCG-TN class-6 account code (confirmed present in TunisiaChartOfAccountsSeeder) */
    private const CATEGORY_ACCOUNTS = [
        'Loyer' => '613',
        'Eau & Électricité' => '606',
        'Fournitures' => '6061',
        'Transport' => '624',
        'Frais divers' => null, // → GeneralExpense fallback
    ];

    public function seedForCompany(Company $company): void
    {
        $fallback = Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::GeneralExpense->value)
            ->first();

        $sort = 0;
        foreach (self::CATEGORY_ACCOUNTS as $name => $code) {
            $account = $code !== null
                ? Account::query()->where('company_id', $company->id)->where('code', $code)->first()
                : null;
            $account ??= $fallback;
            if ($account === null) {
                continue; // no COA → skip (test seeds COA first)
            }

            ExpenseCategory::query()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $company->tenant_id,
                    'account_id' => $account->id,
                    'is_active' => true,
                    'sort_order' => $sort++,
                ],
            );
        }
    }

    public function run(): void
    {
        // No-op for the global runner; call seedForCompany() from the tenant seeders.
    }
}
```

> Adjust the codes in `CATEGORY_ACCOUNTS` to ones confirmed in Step 1. Confirm `Account` exposes `system_purpose` (string column) — seen in `findByPurpose`.

- [ ] **Step 4: Call it from the parapharmacy seeders**

In `TunisianParapharmacySeeder` / `ParapharmacySeeder`, after the chart-of-accounts + `PaymentRepositorySeeder` calls, add `app(ExpenseCategorySeeder::class)->seedForCompany($company);`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd apps/api && php artisan test tests/Feature/Expense/ExpenseCategorySeederTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/api/database/seeders/ExpenseCategorySeeder.php apps/api/database/seeders/TunisianParapharmacySeeder.php apps/api/database/seeders/ParapharmacySeeder.php apps/api/tests/Feature/Expense/ExpenseCategorySeederTest.php
git commit -m "feat(expense): seed TN parapharmacy expense categories with GL accounts"
```

---

## Task 9: Frontend — fix `usePermissions()` map + all expense guards

The role-only `usePermissions()` map lacks `expenses.*`/`expense-categories.*`/`documents.*`, so swapping guards would deny everyone. Add the keys, then fix the route/palette/quick-create guards.

**Files:**
- Modify: `apps/web/src/hooks/usePermissions.ts` (add keys to the `PERMISSIONS` map)
- Modify: `apps/web/src/routes/index.tsx:1433-1494` (expense route guards)
- Modify: `apps/web/src/components/organisms/CommandPalette/useCommandPalette.ts:78-95`
- Modify: `apps/web/src/components/organisms/TopBar/QuickCreateButton.tsx:57-60`
- Test: `apps/web/src/hooks/usePermissions.test.ts` (or extend existing)

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect } from 'vitest'
// Render usePermissions with a user holding the 'accountant' role and assert:
it('grants expenses.create to accountant role', () => {
  // mount usePermissions with auth store user = { roles: ['accountant'] }
  // expect hasPermission('expenses.create') === true
})
it('denies expenses.create to a viewer role', () => {
  // user = { roles: ['viewer'] } → expect hasPermission('expenses.create') === false
})
```

> Follow the existing test pattern for this hook (mock `useAuthStore`). Map the role lists to match the BE seeder grants.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm test src/hooks/usePermissions.test.ts`
Expected: FAIL — `expenses.create` not in the map → returns false for accountant.

- [ ] **Step 3: Add the keys to the `PERMISSIONS` map**

In `apps/web/src/hooks/usePermissions.ts`, alongside the existing `treasury.*` entries, add (role lists mirroring the BE seeder):

```ts
'expenses.view': ['admin', 'treasury', 'accountant', 'manager', 'cashier'],
'expenses.create': ['admin', 'treasury', 'accountant', 'manager', 'cashier'],
'expenses.update': ['admin', 'treasury', 'accountant', 'manager'],
'expenses.delete': ['admin', 'treasury', 'accountant', 'manager'],
'expenses.post': ['admin', 'treasury', 'accountant', 'manager'],
'expense-categories.view': ['admin', 'treasury', 'accountant', 'manager'],
'expense-categories.create': ['admin', 'treasury', 'accountant', 'manager'],
'expense-categories.update': ['admin', 'treasury', 'accountant', 'manager'],
'expense-categories.delete': ['admin', 'treasury', 'accountant', 'manager'],
'documents.view': ['admin', 'treasury', 'accountant', 'manager', 'cashier'],
'documents.update': ['admin', 'treasury', 'accountant', 'manager'],
```

Add the corresponding keys to the `Permission` union type if it is a literal union.

- [ ] **Step 4: Fix the route guards**

In `apps/web/src/routes/index.tsx`, expense routes: `index` → `permission="expenses.view"`; `new` → `permission="expenses.create"`; `categories` → `permission="expense-categories.view"`; `:id`/`:id/view` → `permission="expenses.view"`; `:id/edit` → `permission="expenses.update"`. Replace all `moduleKey="treasury"` / `permission="treasury.create"` / `"treasury.edit"` on these routes.

- [ ] **Step 5: Fix Command Palette + Quick Create**

`useCommandPalette.ts:78-95`: change expenses entry to `permission: 'expenses.view'` and create-expense to `permission: 'expenses.create'`. `QuickCreateButton.tsx:57-60`: change expense create to `permission: 'expenses.create'`.

- [ ] **Step 6: Run tests + typecheck**

Run: `cd apps/web && pnpm test src/hooks/usePermissions.test.ts && pnpm typecheck`
Expected: PASS + clean.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/hooks/usePermissions.ts apps/web/src/routes/index.tsx apps/web/src/components/organisms/CommandPalette/useCommandPalette.ts apps/web/src/components/organisms/TopBar/QuickCreateButton.tsx apps/web/src/hooks/usePermissions.test.ts
git commit -m "fix(web): expense permission keys + correct route/palette/quick-create guards"
```

---

## Task 10: Frontend — category GL-account picker

Add the GL-account select to the category form so categories get a GL mapping.

**Files:**
- Modify: `apps/web/src/features/expenses/pages/ExpenseCategoryPage.tsx`
- Possibly add: an accounts hook/select if none exists (reuse the chart-of-accounts query used elsewhere; find it via `grep -rn "accounts" apps/web/src/features/accounting`)
- Test: `apps/web/src/features/expenses/pages/ExpenseCategoryPage.test.tsx` (extend)

- [ ] **Step 1: Write the failing test**

```tsx
it('submits account_id when a GL account is chosen', async () => {
  // render ExpenseCategoryPage, fill name, select a GL account, submit
  // assert the create mutation payload includes account_id
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm test src/features/expenses/pages/ExpenseCategoryPage.test.tsx`
Expected: FAIL — no account picker in the form.

- [ ] **Step 3: Add the GL-account field**

In `ExpenseCategoryPage.tsx`: add `account_id` to the form state init (`:27-32`), the reset/edit-load (`:34-50`), and render a select (filtered to expense/class-6 accounts) in the form body (`:220-280`), labelled via `t()`. Include `account_id` in the submit payload (the DTO already has it).

- [ ] **Step 4: Run tests + typecheck**

Run: `cd apps/web && pnpm test src/features/expenses/pages/ExpenseCategoryPage.test.tsx && pnpm typecheck`
Expected: PASS + clean.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/expenses/pages/ExpenseCategoryPage.tsx apps/web/src/features/expenses/pages/ExpenseCategoryPage.test.tsx
git commit -m "feat(web): add GL-account picker to expense category form"
```

---

## Task 11: Frontend — idempotency key, SourceDocument role, parseFloat cleanup

Three small, related FE touch-ups in the expense feature.

**Files:**
- Modify: `apps/web/src/features/expenses/api/expenseApi.ts` (send idempotency key on create)
- Modify: `apps/web/src/features/expenses/types/index.ts` (add `idempotency_key` to the create DTO)
- Modify: `apps/web/src/features/expenses/pages/ExpenseFormPage.tsx` (generate a UUID per create attempt)
- Modify: `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx` (pass `role="SOURCE_DOCUMENT"` to `DocumentAttachments` if it accepts a role prop; else set default at the upload call)
- Modify: `apps/web/src/features/expenses/components/organisms/ExpenseCard.tsx:71-80` (replace `parseFloat` with `formatCurrency`)
- Test: extend `apps/web/src/features/expenses/pages/ExpenseFormPage.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
it('includes an idempotency_key in the create payload', async () => {
  // render ExpenseFormPage, fill + submit, assert mutation payload has a uuid idempotency_key
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm test src/features/expenses/pages/ExpenseFormPage.test.tsx`
Expected: FAIL — no key sent.

- [ ] **Step 3: Add `idempotency_key` to the create DTO + API call**

In `types/index.ts` add `idempotency_key?: string` to the create DTO. In `ExpenseFormPage.tsx`, generate `crypto.randomUUID()` once per create attempt (stable across retries of the same submit) and include it in the payload. `expenseApi.ts` posts the payload unchanged — no change needed beyond the type.

- [ ] **Step 4: SourceDocument role**

Ensure receipt uploads use `role="SOURCE_DOCUMENT"`. Inspect `DocumentAttachments` / `useAttachments`; if a `role`/`defaultRole` prop exists, pass it from `ExpenseDetailPage`; otherwise set the role in the upload payload for the expense usage.

- [ ] **Step 5: parseFloat cleanup**

In `ExpenseCard.tsx:71-80`, replace `parseFloat(...)`-based money rendering with `formatCurrency(value, currency)` (string in, no float). Import `formatCurrency` from the shared lib used elsewhere in the feature.

- [ ] **Step 6: Run tests + lint + typecheck**

Run: `cd apps/web && pnpm test src/features/expenses && pnpm lint && pnpm typecheck`
Expected: PASS + clean (no `no-parsefloat-on-money` violations).

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/expenses
git commit -m "feat(web): expense idempotency key, SourceDocument receipt role, ExpenseCard precision cleanup"
```

---

## Task 12: Mobile handover doc

Document the now-stable contract so a parallel session builds the `erp-mobile` expense feature with no backend changes.

**Files:**
- Create: `docs/handoff/HANDOVER-mobile-expense-logging.md`

- [ ] **Step 1: Write the handover**

Cover: endpoints (`POST/GET /api/v1/expenses`, `GET/POST /api/v1/expense-categories`, `POST /api/v1/documents/{id}/attachments` with `role=SOURCE_DOCUMENT`), auth (sanctum Bearer, same as counting), the **idempotency contract** (`idempotency_key` UUID on create → replay returns same expense), the offline-draft analogy to the counting feature, the permission keys (`expenses.*`, `documents.view/update`), the money-as-string contract, and the category→GL mapping. Reference the spec + plan.

- [ ] **Step 2: Commit**

```bash
git add docs/handoff/HANDOVER-mobile-expense-logging.md
git commit -m "docs(expense): mobile expense-logging handover"
```

---

## Self-Review (completed by author)

- **Spec coverage:** §4.1 → Tasks 1–2; §4.2 → Tasks 3, 7, 11; §4.3 → Tasks 4–5; §4.4 → Tasks 8, 10; §4.5 → Tasks 6, 11, 12; §4.6 → Tasks 9–11. Out-of-scope items intentionally absent.
- **Type consistency:** `RepositoryOutflowInterface::applyOutflow(repositoryId, tenantId, companyId, amount, currency)` defined in Task 4, consumed identically in Task 5. `idempotency_key` consistent across migration/model/request/service/DTO (Tasks 6, 11).
- **Verify-before-code flags (carry into execution):** confirm `RepositoryBalanceChanged` constructor arg names (Task 4); the document-attachment upload field name + role token (Task 7); the existing `usePermissions` test pattern + `Permission` union (Task 9); class-6 account codes present in the COA seeder (Task 8); whether `DocumentAttachments` accepts a role prop (Task 11).
- **Risk order:** Tasks 1 and 9 are the highest-risk (policy registration; role-only permission hook) — review them hardest.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-27-expense-flow-demo-cutoff-plan.md`.** Recommended execution: **subagent-driven** (fresh subagent per task; the `treasury-reviewer` agent + a Codex pass gate each task before commit; merge to local `dev` after the full set is green).
