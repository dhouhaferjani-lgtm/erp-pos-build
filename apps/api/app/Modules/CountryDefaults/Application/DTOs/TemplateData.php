<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\DTOs;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
final class TemplateData extends Data
{
    /**
     * @param  list<string>|null  $certified_country_codes
     * @param  DataCollection<int, TemplateAccountData>|array<int, TemplateAccountData>  $rows
     * @param  list<AccountType>  $account_types
     * @param  list<SystemAccountPurpose>  $system_account_purposes
     */
    public function __construct(
        public readonly string $id,
        public readonly TemplateDomain $domain,
        public readonly string $name,
        public readonly ?string $description,
        public readonly TemplateStatus $status,
        public readonly ?string $content_hash,
        public readonly ?string $standard_ref,
        #[TypeScriptType('string[]|null')]
        public readonly ?array $certified_country_codes,
        public readonly ?string $capability_registry_version,
        public readonly ?string $certified_by,
        public readonly ?string $published_at,
        public readonly ?string $cloned_from_id,
        #[DataCollectionOf(TemplateAccountData::class)]
        public readonly DataCollection|array $rows,
        #[TypeScriptType('\App\Modules\Accounting\Domain\Enums\AccountType[]')]
        public readonly array $account_types,
        #[TypeScriptType('\App\Modules\Accounting\Domain\Enums\SystemAccountPurpose[]')]
        public readonly array $system_account_purposes,
        public readonly ?string $created_by,
        public readonly ?string $created_at,
        public readonly ?string $updated_at,
    ) {}

    public static function fromModel(AdminTemplate $template): self
    {
        $summary = TemplateSummaryData::fromModel($template);

        return new self(
            id: $summary->id,
            domain: $summary->domain,
            name: $summary->name,
            description: $summary->description,
            status: $summary->status,
            content_hash: $summary->content_hash,
            standard_ref: $summary->standard_ref,
            certified_country_codes: $summary->certified_country_codes,
            capability_registry_version: $summary->capability_registry_version,
            certified_by: $summary->certified_by,
            published_at: $summary->published_at,
            cloned_from_id: $summary->cloned_from_id,
            rows: $template->relationLoaded('accounts')
                ? $template->accounts->map(static fn ($account): TemplateAccountData => TemplateAccountData::fromModel($account))->all()
                : [],
            account_types: AccountType::cases(),
            system_account_purposes: SystemAccountPurpose::cases(),
            created_by: $template->created_by,
            created_at: $template->created_at?->toAtomString(),
            updated_at: $template->updated_at?->toAtomString(),
        );
    }
}
