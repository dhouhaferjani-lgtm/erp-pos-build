<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Identity\Domain\User;

class ExpenseCategoryPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('expense-categories.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ExpenseCategory $category): bool
    {
        // Check tenant isolation
        if ($category->company_id !== $user->company_id) {
            return false;
        }

        return $user->can('expense-categories.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('expense-categories.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ExpenseCategory $category): bool
    {
        // Check tenant isolation
        if ($category->company_id !== $user->company_id) {
            return false;
        }

        return $user->can('expense-categories.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ExpenseCategory $category): bool
    {
        // Check tenant isolation
        if ($category->company_id !== $user->company_id) {
            return false;
        }

        return $user->can('expense-categories.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ExpenseCategory $category): bool
    {
        return false; // Not implemented
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ExpenseCategory $category): bool
    {
        return false; // Not allowed
    }
}
