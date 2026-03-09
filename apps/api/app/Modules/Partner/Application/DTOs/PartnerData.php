<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\DTOs;

use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Enums\PartnerType;
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
        public ?string $code,
        public ?string $email,
        public ?string $phone,
        public ?string $country_code,
        public ?string $vat_number,
        public ?string $notes,
        public ?string $receivable_balance,
        public ?string $credit_balance,
        public ?string $payable_balance,
        public ?string $street_address,
        public ?string $street_address_2,
        public ?string $city,
        public ?string $state,
        public ?string $postal_code,
        public ?string $country,
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
            code: $partner->code,
            email: $partner->email,
            phone: $partner->phone,
            country_code: $partner->country_code,
            vat_number: $partner->vat_number,
            notes: $partner->notes,
            receivable_balance: $partner->receivable_balance,
            credit_balance: $partner->credit_balance,
            payable_balance: $partner->payable_balance,
            street_address: $partner->street_address,
            street_address_2: $partner->street_address_2,
            city: $partner->city,
            state: $partner->state,
            postal_code: $partner->postal_code,
            country: $partner->country,
            contacts_count: $partner->relationLoaded('partyContacts')
                ? $partner->partyContacts->count()
                : ($partner->party_contacts_count ?? 0),
            primary_contact_name: $partner->relationLoaded('contacts')
                ? $partner->contacts->first(fn ($c) => (bool) $c->pivot->is_primary)?->full_name
                : null,
            created_at: $partner->created_at?->toIso8601String() ?? '',
            updated_at: $partner->updated_at?->toIso8601String(),
        );
    }
}
