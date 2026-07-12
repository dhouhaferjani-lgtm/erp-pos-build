<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\DTOs;

use App\Modules\Partner\Domain\PartnerBankAccount;
use App\Shared\Banking\Contracts\BankAccountValidatorInterface;
use App\Shared\Banking\Domain\ValueObjects\IbanValidationResult;
use App\Shared\Banking\Domain\ValueObjects\RibValidationResult;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PartnerBankAccountData extends Data
{
    public function __construct(
        public string $id,
        public ?string $label,
        public ?string $bank_id,
        public ?string $bank_name,
        public ?string $rib,
        public ?string $iban,
        public ?string $bic,
        public string $currency,
        public bool $is_primary,
        public ?string $created_by,
        public RibValidationResult $rib_validation,
        public IbanValidationResult $iban_validation,
        public bool $bic_valid,
    ) {}

    public static function fromModel(
        PartnerBankAccount $account,
        BankAccountValidatorInterface $validator,
        string $country,
    ): self {
        return new self(
            id: $account->id,
            label: $account->label,
            bank_id: $account->bank_id,
            bank_name: $account->bank_name,
            rib: $account->rib,
            iban: $account->iban,
            bic: $account->bic,
            currency: $account->currency,
            is_primary: $account->is_primary,
            created_by: $account->created_by,
            rib_validation: $validator->validateRib($account->rib ?? '', $country),
            iban_validation: $validator->validateIban($account->iban ?? ''),
            bic_valid: $account->bic === null || $account->bic === ''
                ? false
                : $validator->validateBic($account->bic),
        );
    }
}
