<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Company;
use Illuminate\Validation\ValidationException;
use LogicException;

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

            // The default arm is statically unreachable under the literal-union contract
            // documented above, so PHPStan sees the last named arm as exhaustive. It is kept
            // deliberately as a runtime backstop: a caller that violates the docblock contract
            // (dynamic keys, future field) then fails loudly with a named LogicException instead
            // of a bare UnhandledMatchError 500.
            $errors[$validationKeys[$attribute] ?? $attribute] = match ($attribute) {
                'country_code' => (string) __('company.identity.country_immutable'),
                // @phpstan-ignore match.alwaysTrue (intentional: default arm below is a runtime backstop, not dead code)
                'currency' => (string) __('company.identity.currency_immutable'),
                default => throw new LogicException("{$attribute} is not an immutable company identity field."),
            };
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param FiscalIdentityField $attribute */
    private function currentValue(Company $company, string $attribute): ?string
    {
        // Runtime backstop for the literal-union docblock contract: never let an arbitrary
        // attribute name reach Company::getAttribute() from this service.
        if (! in_array($attribute, [
            'country_code',
            'currency',
            'legal_name',
            'tax_id',
            'registration_number',
            'vat_number',
        ], true)) {
            throw new LogicException("{$attribute} is not a company fiscal identity field.");
        }

        $value = $company->getAttribute($attribute);

        return is_string($value) ? $value : null;
    }
}
