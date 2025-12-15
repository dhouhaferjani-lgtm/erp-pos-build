# Claude Code - Phase 3 Execution Prompt

## Mission

Execute Phase 3 of AutoERP: Complete Common Platform Features. You will implement all features defined in `docs/tasks/PHASE-3.md` following strict TDD principles, atomic design patterns, and the existing project architecture.

---

## Project Context

### Tech Stack
- **Backend:** Laravel 12, PHP 8.3, PostgreSQL 16
- **Frontend:** React 18, TypeScript, Vite, TailwindCSS, shadcn/ui
- **Testing:** PHPUnit (backend), Vitest (frontend unit), Playwright (E2E/visual)
- **Architecture:** Multi-tenant (schema-based), Multi-company, Multi-location
- **Patterns:** CQRS, Event Sourcing, Hexagonal Architecture

### Directory Structure
```
apps/
├── api/                          # Laravel backend
│   ├── app/
│   │   ├── Modules/              # Domain modules
│   │   │   ├── [Module]/
│   │   │   │   ├── Domain/       # Entities, Value Objects
│   │   │   │   ├── Application/  # Services, Commands, Queries
│   │   │   │   ├── Infrastructure/ # Repositories, External
│   │   │   │   └── Presentation/ # Controllers, Resources, Requests
│   │   └── ...
│   └── tests/
│       ├── Unit/
│       └── Feature/
│
├── web/                          # React frontend
│   ├── src/
│   │   ├── components/
│   │   │   ├── atoms/            # Basic UI elements
│   │   │   ├── molecules/        # Composite components
│   │   │   ├── organisms/        # Complex components
│   │   │   └── templates/        # Page layouts
│   │   ├── features/             # Feature modules
│   │   │   └── [feature]/
│   │   │       ├── components/   # Feature-specific components
│   │   │       ├── pages/        # Feature pages
│   │   │       ├── hooks/        # Feature hooks
│   │   │       └── api.ts        # Feature API calls
│   │   ├── hooks/                # Shared hooks
│   │   ├── contexts/             # React contexts
│   │   ├── lib/                  # Utilities
│   │   └── types/                # TypeScript types
│   └── tests/
│       ├── unit/                 # Vitest unit tests
│       └── e2e/                  # Playwright E2E tests
```

---

## Pre-Flight Checklist

Before starting Phase 3, verify the environment is ready:

```bash
# 1. Ensure you're on main and up to date
cd /path/to/project
git checkout main
git pull origin main

# 2. Verify CI is passing
cd apps/api
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
php artisan test

cd ../web
pnpm lint
pnpm typecheck
pnpm test
pnpm build

# 3. Verify database is up
cd ../api
php artisan migrate:status

# 4. Create Phase 3 progress file if not exists
mkdir -p docs/tasks
cp docs/tasks/PHASE-3.md docs/tasks/PHASE-3.md 2>/dev/null || true
```

If any check fails, fix it before proceeding.

---

## Execution Rules

### Rule 1: Test-Driven Development (TDD)

For EVERY feature, follow this cycle:

```
1. RED    → Write failing test first
2. GREEN  → Write minimal code to pass
3. REFACTOR → Clean up, maintain patterns
4. VERIFY → Run all tests, ensure nothing broke
```

**Backend TDD Flow:**
```bash
# 1. Write test
# Create test file: tests/Feature/[Module]/[Feature]Test.php

# 2. Run test (should fail)
cd apps/api
php artisan test --filter=TestName

# 3. Implement feature

# 4. Run test (should pass)
php artisan test --filter=TestName

# 5. Run full suite to ensure no regression
php artisan test
./vendor/bin/phpstan analyse
```

**Frontend TDD Flow:**
```bash
# 1. Write test
# Create test file: tests/unit/[feature]/[component].test.tsx

# 2. Run test (should fail)
cd apps/web
pnpm test [testfile]

# 3. Implement component

# 4. Run test (should pass)
pnpm test [testfile]

# 5. Run full suite
pnpm test
pnpm typecheck
pnpm lint
```

### Rule 2: Atomic Design Compliance

**When creating frontend components:**

1. **Atoms** (`components/atoms/`): Basic UI elements
   - Button, Input, Label, Badge, Spinner, Select, etc.
   - No business logic
   - Highly reusable
   - Example: `atoms/Button.tsx`, `atoms/Input.tsx`

2. **Molecules** (`components/molecules/`): Composite components
   - Combine atoms
   - Single responsibility
   - Example: `molecules/FormField.tsx`, `molecules/SearchBox.tsx`

3. **Organisms** (`components/organisms/`): Complex components
   - Combine molecules and atoms
   - May have local state
   - Example: `organisms/DataTable.tsx`, `organisms/ReportFilters.tsx`

4. **Templates** (`components/templates/`): Page layouts
   - Define page structure
   - No business logic
   - Example: `templates/DashboardLayout.tsx`

5. **Pages** (`features/[feature]/pages/`): Actual pages
   - Use templates
   - Connect to API
   - Business logic lives here

**Component Creation Checklist:**
- [ ] Identify correct atomic level
- [ ] Check if similar component exists (reuse!)
- [ ] Create in correct directory
- [ ] Add TypeScript props interface
- [ ] Add unit test
- [ ] Export from index file

### Rule 3: Git Workflow

**Branch per section:**
```bash
# Starting a new section
git checkout main
git pull origin main
git checkout -b feature/phase-3.X-section-name
```

**Commit after each task unit:**
```bash
# After completing a task (e.g., 3.1.1)
git add -A
git commit -m "feat(finance): implement chart of accounts page

- Add ChartOfAccountsPage component
- Add AccountTreeView component
- Add API integration
- Add unit and E2E tests

Phase 3.1.1 complete"
```

**Push after each sub-section:**
```bash
# After completing 3.1.1, 3.1.2, etc.
git push origin feature/phase-3.X-section-name
```

**Merge when section complete:**
```bash
# After all of 3.1 is done
git checkout main
git pull origin main
git merge feature/phase-3.1-finance-reports
git push origin main
git tag phase-3.1-complete
git push origin phase-3.1-complete
```

### Rule 4: Visual Testing with Playwright

**When to use Playwright:**
- New pages
- Complex UI interactions
- Form flows
- Data tables
- PDF generation verification
- Multi-step workflows

**Playwright Test Structure:**
```typescript
// apps/web/tests/e2e/finance/chart-of-accounts.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Chart of Accounts', () => {
  test.beforeEach(async ({ page }) => {
    // Login and navigate
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard');
  });

  test('displays account tree', async ({ page }) => {
    await page.goto('/settings/chart-of-accounts');
    
    // Verify page loaded
    await expect(page.getByRole('heading', { name: /chart of accounts/i })).toBeVisible();
    
    // Verify tree structure
    await expect(page.getByText('Assets')).toBeVisible();
    await expect(page.getByText('Liabilities')).toBeVisible();
    
    // Expand a node
    await page.click('[data-testid="expand-assets"]');
    await expect(page.getByText('Current Assets')).toBeVisible();
  });

  test('can add new account', async ({ page }) => {
    await page.goto('/settings/chart-of-accounts');
    
    // Open modal
    await page.click('button:has-text("Add Account")');
    await expect(page.getByRole('dialog')).toBeVisible();
    
    // Fill form
    await page.fill('[name="code"]', '1100');
    await page.fill('[name="name"]', 'Petty Cash');
    await page.selectOption('[name="type"]', 'asset');
    
    // Submit
    await page.click('button:has-text("Save")');
    
    // Verify added
    await expect(page.getByText('Petty Cash')).toBeVisible();
  });
});
```

**Run Playwright tests:**
```bash
cd apps/web

# Run all E2E tests
pnpm exec playwright test

# Run specific test file
pnpm exec playwright test tests/e2e/finance/chart-of-accounts.spec.ts

# Run with UI mode (for debugging)
pnpm exec playwright test --ui

# Run with headed browser (visible)
pnpm exec playwright test --headed
```

**Visual regression (optional):**
```typescript
test('chart of accounts visual', async ({ page }) => {
  await page.goto('/settings/chart-of-accounts');
  await expect(page).toHaveScreenshot('chart-of-accounts.png');
});
```

### Rule 5: Quality Gates

**Before committing ANY code:**
```bash
# Backend
cd apps/api
./vendor/bin/pint              # Fix formatting
./vendor/bin/phpstan analyse   # Static analysis
php artisan test               # All tests pass

# Frontend
cd apps/web
pnpm lint --fix                # Fix linting
pnpm typecheck                 # TypeScript check
pnpm test                      # Unit tests
pnpm build                     # Build succeeds
```

**Before pushing:**
```bash
# Run E2E tests for affected features
cd apps/web
pnpm exec playwright test tests/e2e/[affected-feature]/
```

**If any check fails:** Fix before continuing. Do not accumulate tech debt.

---

## Section Execution Guide

### Starting Each Section

```bash
# 1. Create branch
git checkout main && git pull origin main
git checkout -b feature/phase-3.X-section-name

# 2. Read section requirements
cat docs/tasks/PHASE-3.md | grep -A 100 "## 3.X"

# 3. Plan implementation
# - List all tasks
# - Identify dependencies
# - Determine test strategy
```

### For Each Task (e.g., 3.1.1)

```
┌─────────────────────────────────────────────────────────────┐
│ TASK: 3.1.1 Chart of Accounts Page                         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ 1. ANALYZE                                                  │
│    - What does this feature need?                           │
│    - What API endpoints exist/needed?                       │
│    - What components needed?                                │
│    - What tests needed?                                     │
│                                                             │
│ 2. BACKEND (if needed)                                      │
│    a. Write API test (Feature test)                         │
│    b. Run test → FAIL                                       │
│    c. Create migration (if needed)                          │
│    d. Create/update Model                                   │
│    e. Create Service                                        │
│    f. Create Controller                                     │
│    g. Create Request validation                             │
│    h. Create Resource                                       │
│    i. Add route                                             │
│    j. Run test → PASS                                       │
│    k. Run full backend suite → ALL PASS                     │
│                                                             │
│ 3. FRONTEND                                                 │
│    a. Write component unit test                             │
│    b. Run test → FAIL                                       │
│    c. Create component (correct atomic level)               │
│    d. Run test → PASS                                       │
│    e. Write page unit test                                  │
│    f. Create page component                                 │
│    g. Add route                                             │
│    h. Add to navigation (if needed)                         │
│    i. Run frontend suite → ALL PASS                         │
│                                                             │
│ 4. E2E TEST                                                 │
│    a. Write Playwright test                                 │
│    b. Run E2E test → PASS                                   │
│                                                             │
│ 5. COMMIT                                                   │
│    git add -A                                               │
│    git commit -m "feat(finance): chart of accounts page"    │
│                                                             │
│ 6. UPDATE PROGRESS                                          │
│    Mark 3.1.1 as complete in PHASE-3-PROGRESS.md            │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Completing Each Section

```bash
# 1. Run full test suite
cd apps/api && php artisan test && ./vendor/bin/phpstan analyse
cd ../web && pnpm lint && pnpm typecheck && pnpm test && pnpm build

# 2. Run E2E tests for section
pnpm exec playwright test tests/e2e/[section]/

# 3. Push branch
git push origin feature/phase-3.X-section-name

# 4. Merge to main
git checkout main
git pull origin main
git merge feature/phase-3.X-section-name
git push origin main

# 5. Tag completion
git tag phase-3.X-complete
git push origin phase-3.X-complete

# 6. Update progress
# Edit docs/tasks/PHASE-3-PROGRESS.md
# Mark section as complete with date
```

---

## API Design Patterns

### Controller Structure
```php
<?php

namespace App\Modules\Finance\Presentation\Controllers;

use App\Modules\Finance\Application\Services\AccountService;
use App\Modules\Finance\Presentation\Requests\CreateAccountRequest;
use App\Modules\Finance\Presentation\Resources\AccountResource;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accountService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $accounts = $this->accountService->getAll(currentCompany());
        return AccountResource::collection($accounts);
    }

    public function store(CreateAccountRequest $request): AccountResource
    {
        $account = $this->accountService->create(
            currentCompany(),
            $request->validated()
        );
        return new AccountResource($account);
    }

    // ...
}
```

### Request Validation
```php
<?php

namespace App\Modules\Finance\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage_accounts');
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'parent_id' => ['nullable', 'uuid', 'exists:accounts,id'],
        ];
    }
}
```

### Service Layer
```php
<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Finance\Domain\Models\Account;
use App\Modules\Company\Domain\Models\Company;

class AccountService
{
    public function getAll(Company $company): Collection
    {
        return Account::where('company_id', $company->id)
            ->orderBy('code')
            ->get();
    }

    public function create(Company $company, array $data): Account
    {
        return Account::create([
            'company_id' => $company->id,
            ...$data
        ]);
    }
}
```

### API Resource
```php
<?php

namespace App\Modules\Finance\Presentation\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'parent_id' => $this->parent_id,
            'balance' => $this->balance,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
```

---

## Frontend Patterns

### Page Component
```tsx
// features/finance/pages/ChartOfAccountsPage.tsx
import { useState, useEffect } from 'react';
import { DashboardLayout } from '@/components/templates/DashboardLayout';
import { PageHeader } from '@/components/organisms/PageHeader';
import { AccountTreeView } from '../components/AccountTreeView';
import { AddAccountModal } from '../components/AddAccountModal';
import { Button } from '@/components/atoms/Button';
import { useAccounts } from '../hooks/useAccounts';

export function ChartOfAccountsPage() {
  const { accounts, isLoading, refetch } = useAccounts();
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);

  if (isLoading) {
    return <DashboardLayout><LoadingState /></DashboardLayout>;
  }

  return (
    <DashboardLayout>
      <PageHeader
        title="Chart of Accounts"
        actions={
          <Button onClick={() => setIsAddModalOpen(true)}>
            Add Account
          </Button>
        }
      />
      
      <AccountTreeView accounts={accounts} />
      
      <AddAccountModal
        open={isAddModalOpen}
        onClose={() => setIsAddModalOpen(false)}
        onSuccess={() => {
          refetch();
          setIsAddModalOpen(false);
        }}
      />
    </DashboardLayout>
  );
}
```

### API Hook
```tsx
// features/finance/hooks/useAccounts.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { getAccounts, createAccount } from '../api';

export function useAccounts() {
  const query = useQuery({
    queryKey: ['accounts'],
    queryFn: getAccounts,
  });

  return {
    accounts: query.data ?? [],
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useCreateAccount() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: createAccount,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounts'] });
    },
  });
}
```

### API Functions
```tsx
// features/finance/api.ts
import { api } from '@/lib/api';
import { Account, CreateAccountData } from './types';

export async function getAccounts(): Promise<Account[]> {
  const response = await api.get('/accounts');
  return response.data.data;
}

export async function createAccount(data: CreateAccountData): Promise<Account> {
  const response = await api.post('/accounts', data);
  return response.data.data;
}
```

---

## Testing Patterns

### Backend Feature Test
```php
<?php

namespace Tests\Feature\Finance;

use Tests\TestCase;
use App\Modules\Company\Domain\Models\Company;
use App\Modules\User\Domain\Models\User;

class AccountTest extends TestCase
{
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create();
        $this->user->companies()->attach($this->company, ['role' => 'owner']);
    }

    public function test_can_list_accounts(): void
    {
        Account::factory()->count(5)->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/v1/accounts');

        $response->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_can_create_account(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/v1/accounts', [
                'code' => '1100',
                'name' => 'Cash',
                'type' => 'asset',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', '1100')
            ->assertJsonPath('data.name', 'Cash');

        $this->assertDatabaseHas('accounts', [
            'company_id' => $this->company->id,
            'code' => '1100',
        ]);
    }

    public function test_cannot_access_other_company_accounts(): void
    {
        $otherCompany = Company::factory()->create();
        Account::factory()->create([
            'company_id' => $otherCompany->id,
            'code' => '1100',
        ]);

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/v1/accounts');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
```

### Frontend Unit Test
```tsx
// tests/unit/features/finance/AccountTreeView.test.tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AccountTreeView } from '@/features/finance/components/AccountTreeView';

const mockAccounts = [
  { id: '1', code: '1000', name: 'Assets', type: 'asset', parent_id: null },
  { id: '2', code: '1100', name: 'Cash', type: 'asset', parent_id: '1' },
];

describe('AccountTreeView', () => {
  it('renders account hierarchy', () => {
    render(<AccountTreeView accounts={mockAccounts} />);
    
    expect(screen.getByText('Assets')).toBeInTheDocument();
    expect(screen.getByText('1000')).toBeInTheDocument();
  });

  it('expands parent to show children', async () => {
    render(<AccountTreeView accounts={mockAccounts} />);
    
    // Cash should be hidden initially
    expect(screen.queryByText('Cash')).not.toBeVisible();
    
    // Click expand
    await userEvent.click(screen.getByTestId('expand-1'));
    
    // Cash should be visible
    expect(screen.getByText('Cash')).toBeVisible();
  });
});
```

### Playwright E2E Test
```typescript
// tests/e2e/finance/accounts.spec.ts
import { test, expect } from '@playwright/test';
import { login, setupTestCompany } from '../helpers';

test.describe('Chart of Accounts', () => {
  test.beforeEach(async ({ page }) => {
    await setupTestCompany();
    await login(page);
  });

  test('full account management flow', async ({ page }) => {
    // Navigate to chart of accounts
    await page.goto('/settings/chart-of-accounts');
    await expect(page.getByRole('heading', { name: /chart of accounts/i })).toBeVisible();

    // Add new account
    await page.click('button:has-text("Add Account")');
    await page.fill('[name="code"]', '1150');
    await page.fill('[name="name"]', 'Bank Account');
    await page.selectOption('[name="type"]', 'asset');
    await page.click('button:has-text("Save")');

    // Verify account appears
    await expect(page.getByText('Bank Account')).toBeVisible();
    await expect(page.getByText('1150')).toBeVisible();

    // Edit account
    await page.click('[data-testid="account-1150-menu"]');
    await page.click('text=Edit');
    await page.fill('[name="name"]', 'Primary Bank Account');
    await page.click('button:has-text("Save")');

    // Verify update
    await expect(page.getByText('Primary Bank Account')).toBeVisible();
  });
});
```

---

## Progress Tracking

After completing each task, update `docs/tasks/PHASE-3-PROGRESS.md`:

```markdown
# Phase 3 Progress

Started: 2024-XX-XX

## 3.1 Finance Reports
- [x] 3.1.1 Chart of Accounts ✅ 2024-XX-XX
- [x] 3.1.2 General Ledger ✅ 2024-XX-XX
- [ ] 3.1.3 Trial Balance
- [ ] 3.1.4 Profit & Loss
...
```

---

## Troubleshooting

### Test Failures
```bash
# Get detailed output
php artisan test --filter=TestName -vvv

# Debug frontend test
pnpm test -- --reporter=verbose

# Debug Playwright
pnpm exec playwright test --debug
```

### Database Issues
```bash
# Reset test database
php artisan migrate:fresh --env=testing

# Check migrations
php artisan migrate:status
```

### Type Errors
```bash
# Regenerate types
cd apps/web
pnpm typecheck

# Check specific file
pnpm exec tsc --noEmit src/path/to/file.ts
```

---

## START EXECUTION

Begin with:

```bash
# 1. Verify environment
cd apps/api && php artisan test && ./vendor/bin/phpstan analyse
cd ../web && pnpm lint && pnpm typecheck && pnpm test && pnpm build

# 2. Create progress file
cat > docs/tasks/PHASE-3-PROGRESS.md << 'EOF'
# Phase 3 Progress

Started: $(date +%Y-%m-%d)

## Status: IN PROGRESS

## Current Section: 3.1 Finance Reports

## Completed
(none yet)
EOF

# 3. Start Section 3.1
git checkout -b feature/phase-3.1-finance-reports

# 4. Begin with 3.1.1 Chart of Accounts
# Follow the task execution pattern above
```

**Execute each section in order: 3.1 → 3.2 → 3.3 → ... → 3.10**

Report any blockers or questions. Do not skip tests. Do not accumulate technical debt.
