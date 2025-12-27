<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\DTOs;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use Illuminate\Support\Carbon;

/**
 * Data transfer object for expense operations.
 *
 * @property string|null $id
 * @property string $company_id
 * @property string|null $vendor_name
 * @property string|null $expense_category_id
 * @property string|null $payment_method_id
 * @property string|null $payment_repository_id
 * @property Carbon|null $payment_date
 * @property string|null $receipt_number
 * @property string $total
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property bool $is_paid
 * @property DocumentStatus $status
 * @property Carbon $document_date
 */
final readonly class ExpenseData
{
    public function __construct(
        public ?string $id,
        public string $company_id,
        public ?string $vendor_name,
        public ?string $expense_category_id,
        public ?string $payment_method_id,
        public ?string $payment_repository_id,
        public ?Carbon $payment_date,
        public ?string $receipt_number,
        public string $total,
        public ?string $notes,
        public ?string $internal_notes,
        public bool $is_paid,
        public DocumentStatus $status,
        public Carbon $document_date,
    ) {}

    /**
     * Create from array data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            company_id: $data['company_id'],
            vendor_name: $data['vendor_name'] ?? null,
            expense_category_id: $data['expense_category_id'] ?? null,
            payment_method_id: $data['payment_method_id'] ?? null,
            payment_repository_id: $data['payment_repository_id'] ?? null,
            payment_date: isset($data['payment_date'])
                ? Carbon::parse($data['payment_date'])
                : null,
            receipt_number: $data['receipt_number'] ?? null,
            total: $data['total'],
            notes: $data['notes'] ?? null,
            internal_notes: $data['internal_notes'] ?? null,
            is_paid: $data['is_paid'] ?? false,
            status: $data['status'] ?? DocumentStatus::Draft,
            document_date: isset($data['document_date'])
                ? Carbon::parse($data['document_date'])
                : now(),
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'vendor_name' => $this->vendor_name,
            'expense_category_id' => $this->expense_category_id,
            'payment_method_id' => $this->payment_method_id,
            'payment_repository_id' => $this->payment_repository_id,
            'payment_date' => $this->payment_date?->toDateString(),
            'receipt_number' => $this->receipt_number,
            'total' => $this->total,
            'notes' => $this->notes,
            'internal_notes' => $this->internal_notes,
            'is_paid' => $this->is_paid,
            'status' => $this->status,
            'document_date' => $this->document_date->toDateString(),
        ];
    }
}
