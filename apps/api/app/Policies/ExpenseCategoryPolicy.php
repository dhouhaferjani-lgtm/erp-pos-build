<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Identity\Domain\User;

class ExpenseCategoryPolicy
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('expense-categories.view');
    }

    public function view(User $user, ExpenseCategory $category): bool
    {
        // Check company isolation via CompanyContext (not $user->company_id —
        // that column does not exist on the users table).
        return $category->company_id === $this->companyContext->getCompanyId()
            && $user->can('expense-categories.view');
    }

    public function create(User $user): bool
    {
        return $user->can('expense-categories.create');
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        return $category->company_id === $this->companyContext->getCompanyId()
            && $user->can('expense-categories.update');
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return $category->company_id === $this->companyContext->getCompanyId()
            && $user->can('expense-categories.delete');
    }
}
