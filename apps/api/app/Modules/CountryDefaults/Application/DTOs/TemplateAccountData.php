<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\DTOs;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TemplateAccountData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly AccountType $type,
        public readonly ?string $parent_code,
        public readonly ?SystemAccountPurpose $system_purpose,
        public readonly bool $is_system,
        public readonly int $sort_order,
        public readonly bool $is_protected,
        public readonly ?string $protection_source,
    ) {}

    public static function fromModel(AdminTemplateAccount $account): self
    {
        $protectionSource = self::protectionSource($account->code);

        return new self(
            id: $account->id,
            code: $account->code,
            name: $account->name,
            type: $account->type,
            parent_code: $account->parent_code,
            system_purpose: $account->system_purpose,
            is_system: $account->is_system,
            sort_order: $account->sort_order,
            is_protected: $protectionSource !== null,
            protection_source: $protectionSource,
        );
    }

    private static function protectionSource(string $code): ?string
    {
        foreach (['TN', 'FR', '*'] as $scope) {
            foreach (ProtectedAccountCodeRegistry::forCountry($scope) as $protected) {
                if ($protected['code'] === $code) {
                    return $protected['protection_source'];
                }
            }
        }

        return null;
    }
}
