<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs;

use App\Modules\Accounting\Domain\Account;
use Spatie\LaravelData\Data;

final class AccountData extends Data
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public ?string $parent_id,
        public string $code,
        public string $name,
        public string $type,
        public ?string $description,
        public bool $is_active,
        public bool $is_system,
        public string $balance,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Account $account, int $scale = 3): self
    {
        return new self(
            id: $account->id,
            tenant_id: $account->tenant_id,
            parent_id: $account->parent_id,
            code: $account->code,
            name: $account->name,
            type: $account->type->value,
            description: $account->description,
            is_active: $account->is_active,
            is_system: $account->is_system,
            balance: number_format((float) $account->balance, $scale, '.', ''),
            created_at: $account->created_at?->toIso8601String() ?? '',
            updated_at: $account->updated_at?->toIso8601String() ?? '',
        );
    }
}
