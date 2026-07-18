<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Resources;

use App\Modules\Document\Domain\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for expense documents.
 *
 * @property-read Document $resource
 */
class ExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $partner = $this->resource->relationLoaded('partner')
            ? $this->resource->partner
            : null;

        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type,
            'status' => $this->resource->status,
            'document_number' => $this->resource->document_number,
            'document_date' => $this->resource->document_date->toDateString(),
            'partner_id' => $partner?->id,
            'partner' => $partner ? [
                'id' => $partner->id,
                'name' => $partner->name,
            ] : null,
            'subtotal' => $this->resource->subtotal,
            'tax_amount' => $this->resource->tax_amount,
            'total' => $this->resource->total,
            'currency' => $this->resource->currency,
            'notes' => $this->resource->notes,
            'internal_notes' => $this->resource->internal_notes,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),

            // Expense metadata
            'metadata' => $this->when(
                $this->resource->relationLoaded('expenseMetadata'),
                fn () => $this->resource->expenseMetadata ? [
                    'vendor_name' => $this->resource->expenseMetadata->vendor_name,
                    'receipt_number' => $this->resource->expenseMetadata->receipt_number,
                    'payment_date' => $this->resource->expenseMetadata->payment_date?->toDateString(),
                    'is_paid' => $this->resource->expenseMetadata->is_paid,
                    'paid_at' => $this->resource->expenseMetadata->paid_at?->toISOString(),
                    'expense_category_id' => $this->resource->expenseMetadata->expense_category_id,
                    'payment_method_id' => $this->resource->expenseMetadata->payment_method_id,
                    'payment_repository_id' => $this->resource->expenseMetadata->payment_repository_id,
                    'recurrence_template_id' => $this->resource->expenseMetadata->recurrence_template_id,
                    'expense_kind' => $this->resource->expenseMetadata->expense_kind->value,
                    'vat_rate' => $this->resource->expenseMetadata->vat_rate,
                    'vat_deductible_percent' => $this->resource->expenseMetadata->vat_deductible_percent,

                    // Nested relationships
                    'category' => $this->when(
                        $this->resource->expenseMetadata->relationLoaded('category'),
                        fn () => $this->resource->expenseMetadata->category ? [
                            'id' => $this->resource->expenseMetadata->category->id,
                            'name' => $this->resource->expenseMetadata->category->name,
                            'parent_id' => $this->resource->expenseMetadata->category->parent_id,
                        ] : null
                    ),
                    'payment_method' => $this->when(
                        $this->resource->expenseMetadata->relationLoaded('paymentMethod'),
                        fn () => $this->resource->expenseMetadata->paymentMethod ? [
                            'id' => $this->resource->expenseMetadata->paymentMethod->id,
                            'name' => $this->resource->expenseMetadata->paymentMethod->name,
                            'code' => $this->resource->expenseMetadata->paymentMethod->code,
                        ] : null
                    ),
                    'payment_repository' => $this->when(
                        $this->resource->expenseMetadata->relationLoaded('paymentRepository'),
                        fn () => $this->resource->expenseMetadata->paymentRepository ? [
                            'id' => $this->resource->expenseMetadata->paymentRepository->id,
                            'name' => $this->resource->expenseMetadata->paymentRepository->name,
                            'type' => $this->resource->expenseMetadata->paymentRepository->type,
                        ] : null
                    ),
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
            'application' => $this->resource->payload['linked_cost_application'] ?? null,
        ];
    }
}
