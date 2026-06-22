<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\DTOs;

use App\Modules\Partner\Domain\Enums\ConsolidationFrequency;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Enums\PaymentTerms;
use App\Modules\Partner\Domain\Partner;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PartnerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public PartnerType $type,
        public ?CustomerCategory $customer_category,
        public ?string $company_legal_name,
        public ?string $business_registration_number,
        public ?PaymentTerms $payment_terms,
        public ?int $payment_terms_days,
        public ?string $credit_limit,
        public ?string $discount_percentage,
        public bool $invoice_consolidation,
        public ?ConsolidationFrequency $consolidation_frequency,
        public ?string $code,
        public ?string $email,
        public ?string $phone,
        public ?string $country_code,
        public ?string $vat_number,
        public ?string $notes,
        public ?string $receivable_balance,
        public ?string $credit_balance,
        public ?string $payable_balance,
        public string $net_balance,
        public ?string $street_address,
        public ?string $street_address_2,
        public ?string $city,
        public ?string $state,
        public ?string $postal_code,
        public ?string $country,
        public bool $is_active,
        public CustomerAccountStatus $account_status,
        public int $account_status_version,
        public ?string $account_status_changed_at,
        public ?string $account_status_changed_by,
        public ?string $account_status_reason,
        public int $contacts_count,
        public ?string $primary_contact_name,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Partner $partner): self
    {
        return new self(
            id: $partner->id,
            name: $partner->name,
            type: $partner->type,
            customer_category: $partner->customer_category,
            company_legal_name: $partner->company_legal_name,
            business_registration_number: $partner->business_registration_number,
            payment_terms: $partner->payment_terms,
            payment_terms_days: $partner->payment_terms_days,
            credit_limit: $partner->credit_limit,
            discount_percentage: $partner->discount_percentage,
            invoice_consolidation: (bool) $partner->invoice_consolidation,
            consolidation_frequency: $partner->consolidation_frequency,
            code: $partner->code,
            email: $partner->email,
            phone: $partner->phone,
            country_code: $partner->country_code,
            vat_number: $partner->vat_number,
            notes: $partner->notes,
            receivable_balance: $partner->receivable_balance,
            credit_balance: $partner->credit_balance,
            payable_balance: $partner->payable_balance,
            net_balance: $partner->net_balance,
            street_address: $partner->street_address,
            street_address_2: $partner->street_address_2,
            city: $partner->city,
            state: $partner->state,
            postal_code: $partner->postal_code,
            country: $partner->country,
            is_active: (bool) $partner->is_active,
            account_status: $partner->account_status,
            account_status_version: $partner->account_status_version,
            account_status_changed_at: $partner->account_status_changed_at?->toIso8601String(),
            account_status_changed_by: $partner->account_status_changed_by,
            account_status_reason: $partner->account_status_reason,
            contacts_count: $partner->relationLoaded('partyContacts')
                ? $partner->partyContacts->count()
                : ($partner->party_contacts_count ?? 0),
            primary_contact_name: $partner->relationLoaded('contacts')
                ? $partner->contacts->first(fn ($c) => (bool) $c->getAttribute('pivot')?->getAttribute('is_primary'))?->full_name
                : null,
            created_at: $partner->created_at?->toIso8601String() ?? '',
            updated_at: $partner->updated_at?->toIso8601String(),
        );
    }
}
