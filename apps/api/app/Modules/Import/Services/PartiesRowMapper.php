<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Shared\Domain\CurrencyScale;

final class PartiesRowMapper
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function toPartnerData(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'type' => $data['type'] ?? null,
            'code' => $data['code'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'vat_number' => $data['tax_id'] ?? null,
            'street_address' => $data['address_line1'] ?? null,
            'city' => $data['address_city'] ?? null,
            'postal_code' => $data['address_postal_code'] ?? null,
            'country' => $data['address_country'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ar: ?array<string, mixed>, ap: ?array<string, mixed>}
     */
    public function toBalancePayloads(array $data, string $defaultBalanceDate, string $partnerCode): array
    {
        $type = (string) ($data['type'] ?? '');
        $customerBalance = $type === 'customer'
            ? $this->balanceOrNull($data['opening_balance'] ?? null)
            : $this->balanceOrNull($data['opening_balance_customer'] ?? null);
        $supplierBalance = $type === 'supplier'
            ? $this->balanceOrNull($data['opening_balance'] ?? null)
            : $this->balanceOrNull($data['opening_balance_supplier'] ?? null);

        return [
            'ar' => $customerBalance === null
                ? null
                : $this->balancePayload($data, $defaultBalanceDate, $partnerCode, $customerBalance),
            'ap' => $supplierBalance === null
                ? null
                : $this->balancePayload($data, $defaultBalanceDate, $partnerCode, $supplierBalance),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function extraValidationErrors(array $data): array
    {
        $type = (string) ($data['type'] ?? '');
        $errors = [];

        if ($type === 'both' && $this->hasNonEmptyValue($data['opening_balance'] ?? null)) {
            $errors[] = "For partners of type 'both', use opening_balance_customer / opening_balance_supplier instead of opening_balance.";
        }

        if ($type === 'customer' && $this->hasNonEmptyValue($data['opening_balance_supplier'] ?? null)) {
            $errors[] = 'Customer rows must not use opening_balance_supplier.';
        }

        if ($type === 'supplier' && $this->hasNonEmptyValue($data['opening_balance_customer'] ?? null)) {
            $errors[] = 'Supplier rows must not use opening_balance_customer.';
        }

        return $errors;
    }

    private function balanceOrNull(mixed $value): ?string
    {
        if (! $this->hasNonEmptyValue($value)) {
            return null;
        }

        $formatted = CurrencyScale::bcformatStrict((string) $value, 3);

        return bccomp($formatted, '0', 3) === 0 ? null : $formatted;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function balancePayload(array $data, string $defaultBalanceDate, string $partnerCode, string $balance): array
    {
        $date = $this->hasNonEmptyValue($data['balance_date'] ?? null)
            ? trim((string) $data['balance_date'])
            : $defaultBalanceDate;
        $reference = $this->hasNonEmptyValue($data['reference'] ?? null)
            ? trim((string) $data['reference'])
            : null;
        $magnitude = CurrencyScale::bcformatStrict(ltrim($balance, '-'), 3);

        return [
            'partner_code' => $partnerCode,
            'external_invoice_number' => $reference,
            'document_date' => $date,
            'due_date' => $date,
            'total' => $magnitude,
            'open_amount' => $magnitude,
            'document_type' => str_starts_with($balance, '-') ? 'credit_note' : 'invoice',
            'notes' => null,
        ];
    }

    private function hasNonEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return trim((string) $value) !== '';
    }
}
