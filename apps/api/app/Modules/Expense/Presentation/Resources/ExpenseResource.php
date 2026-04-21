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
        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type,
            'status' => $this->resource->status,
            'document_number' => $this->resource->document_number,
            'document_date' => $this->resource->document_date->toDateString(),
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
                    'expense_category_id' => $this->resource->expenseMetadata->expense_category_id,
                    'payment_method_id' => $this->resource->expenseMetadata->payment_method_id,
                    'payment_repository_id' => $this->resource->expenseMetadata->payment_repository_id,

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

            // Document attachments
            'attachments' => $this->when(
                $this->resource->relationLoaded('attachments'),
                // @phpstan-ignore-next-line
                fn () => $this->resource->attachments->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mime_type,
                    'size' => $attachment->size,
                    'url' => $attachment->url,
                ])
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
