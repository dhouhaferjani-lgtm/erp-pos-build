<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TransactionData extends Data
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $id,
        public string $enrollment_id,
        public TransactionType $transaction_type,
        public string $amount,
        public string $balance_before,
        public string $balance_after,
        public ?string $order_id,
        public ?string $order_line_id,
        public ?string $reward_id,
        public ?string $earning_rule_id,
        public ?string $description,
        public ?array $metadata,
        public ?string $created_by,
        public string $created_at,
        public ?string $expires_at,
    ) {}

    public static function fromModel(Transaction $transaction): self
    {
        return new self(
            id: $transaction->id,
            enrollment_id: $transaction->enrollment_id,
            transaction_type: $transaction->transaction_type,
            amount: (string) $transaction->amount,
            balance_before: (string) $transaction->balance_before,
            balance_after: (string) $transaction->balance_after,
            order_id: $transaction->order_id,
            order_line_id: $transaction->order_line_id,
            reward_id: $transaction->reward_id,
            earning_rule_id: $transaction->earning_rule_id,
            description: $transaction->description,
            metadata: $transaction->metadata,
            created_by: $transaction->created_by,
            created_at: $transaction->created_at->toIso8601String(),
            expires_at: $transaction->expires_at?->toIso8601String(),
        );
    }
}
