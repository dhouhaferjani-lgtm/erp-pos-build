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
