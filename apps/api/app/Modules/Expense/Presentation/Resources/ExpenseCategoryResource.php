<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for expense categories.
 *
 * @property-read \App\Modules\Expense\Domain\ExpenseCategory $resource
 */
class ExpenseCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'parent_id' => $this->resource->parent_id,
            'account_id' => $this->resource->account_id,
            'sort_order' => $this->resource->sort_order,
            'is_active' => $this->resource->is_active,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),

            // Parent category
            'parent' => $this->when(
                $this->resource->relationLoaded('parent'),
                fn () => $this->resource->parent ? [
                    'id' => $this->resource->parent->id,
                    'name' => $this->resource->parent->name,
                ] : null
            ),

            // Child categories
            'children' => $this->when(
                $this->resource->relationLoaded('children'),
                fn () => $this->resource->children->map(fn ($child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'is_active' => $child->is_active,
                ])
            ),

            // Linked account
            'account' => $this->when(
                $this->resource->relationLoaded('account'),
                fn () => $this->resource->account ? [
                    'id' => $this->resource->account->id,
                    'code' => $this->resource->account->code,
                    'name' => $this->resource->account->name,
                ] : null
            ),

            // Company
            'company' => $this->when(
                $this->resource->relationLoaded('company'),
                fn () => [
                    'id' => $this->resource->company->id,
                    'name' => $this->resource->company->name,
                ]
            ),
        ];
    }
}
