<?php

declare(strict_types=1);

namespace App\Modules\Income\Presentation\Resources;

use App\Modules\Document\Domain\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for income documents — mirror of ExpenseResource.
 *
 * @property-read Document $resource
 */
class IncomeResource extends JsonResource
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
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),

            'metadata' => $this->when(
                $this->resource->relationLoaded('incomeMetadata'),
                fn () => $this->resource->incomeMetadata ? [
                    'source_name' => $this->resource->incomeMetadata->source_name,
                    'reference_number' => $this->resource->incomeMetadata->reference_number,
                    'payment_date' => $this->resource->incomeMetadata->payment_date?->toDateString(),
                    'is_received' => $this->resource->incomeMetadata->is_received,
                    'income_account_id' => $this->resource->incomeMetadata->income_account_id,
                    'payment_method_id' => $this->resource->incomeMetadata->payment_method_id,
                    'payment_repository_id' => $this->resource->incomeMetadata->payment_repository_id,

                    'income_account' => $this->when(
                        $this->resource->incomeMetadata->relationLoaded('incomeAccount'),
                        fn () => $this->resource->incomeMetadata->incomeAccount ? [
                            'id' => $this->resource->incomeMetadata->incomeAccount->id,
                            'code' => $this->resource->incomeMetadata->incomeAccount->code,
                            'name' => $this->resource->incomeMetadata->incomeAccount->name,
                        ] : null
                    ),
                    'payment_method' => $this->when(
                        $this->resource->incomeMetadata->relationLoaded('paymentMethod'),
                        fn () => $this->resource->incomeMetadata->paymentMethod ? [
                            'id' => $this->resource->incomeMetadata->paymentMethod->id,
                            'name' => $this->resource->incomeMetadata->paymentMethod->name,
                            'code' => $this->resource->incomeMetadata->paymentMethod->code,
                        ] : null
                    ),
                    'payment_repository' => $this->when(
                        $this->resource->incomeMetadata->relationLoaded('paymentRepository'),
                        fn () => $this->resource->incomeMetadata->paymentRepository ? [
                            'id' => $this->resource->incomeMetadata->paymentRepository->id,
                            'name' => $this->resource->incomeMetadata->paymentRepository->name,
                            'type' => $this->resource->incomeMetadata->paymentRepository->type,
                        ] : null
                    ),
                ] : null
            ),

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
