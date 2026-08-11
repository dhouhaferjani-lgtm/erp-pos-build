<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Company;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type MutableFiscalIdentityField 'legal_name'|'tax_id'|'registration_number'|'vat_number'
 * @phpstan-type ImmutableFiscalIdentityField 'country_code'|'currency'
 * @phpstan-type FiscalIdentityField MutableFiscalIdentityField|ImmutableFiscalIdentityField
 */
final class CompanyFiscalIdentityService
{
    /**
     * @param  array<MutableFiscalIdentityField, string|null>  $candidateAttributes
     * @return array<MutableFiscalIdentityField, array{old: string|null, new: string|null}>
     */
    public function changedFields(Company $company, array $candidateAttributes): array
    {
        $changes = [];

        foreach ($candidateAttributes as $attribute => $newValue) {
            $oldValue = $this->currentValue($company, $attribute);

            if ($oldValue === $newValue) {
                continue;
            }

            $changes[$attribute] = [
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<ImmutableFiscalIdentityField, string|null>  $candidateAttributes
     * @param  array<ImmutableFiscalIdentityField, string>  $validationKeys
     */
    public function assertImmutableFieldsUnchanged(
        Company $company,
        array $candidateAttributes,
        array $validationKeys = [],
    ): void {
        $errors = [];

        foreach ($candidateAttributes as $attribute => $newValue) {
            if ($this->currentValue($company, $attribute) === $newValue) {
                continue;
            }

            $errors[$validationKeys[$attribute] ?? $attribute] = match ($attribute) {
                'country_code' => (string) __('company.identity.country_immutable'),
                'currency' => (string) __('company.identity.currency_immutable'),
            };
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param FiscalIdentityField $attribute */
    private function currentValue(Company $company, string $attribute): ?string
    {
        $value = $company->getAttribute($attribute);

        return is_string($value) ? $value : null;
    }
}
