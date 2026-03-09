<?php

declare(strict_types=1);

namespace App\Modules\Contact\Application\DTOs;

use App\Modules\Contact\Domain\Contact;
use App\Modules\Contact\Domain\Enums\Gender;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ContactData extends Data
{
    /**
     * @param  array<int, ContactPartyData>  $parties
     */
    public function __construct(
        public string $id,
        public string $first_name,
        public ?string $last_name,
        public string $full_name,
        public ?string $email,
        public ?string $phone,
        public ?string $mobile,
        public ?string $date_of_birth,
        public ?Gender $gender,
        public ?string $national_id,
        public ?string $avatar_media_id,
        public ?string $notes,
        public bool $is_active,
        public string $created_at,
        public ?string $updated_at,
        public array $parties = [],
    ) {}

    public static function fromModel(Contact $contact): self
    {
        $parties = [];
        if ($contact->relationLoaded('parties')) {
            foreach ($contact->parties as $party) {
                $parties[] = new ContactPartyData(
                    id: $party->id,
                    name: $party->name,
                    type: $party->type->value,
                    job_title: $party->pivot->job_title,
                    department: $party->pivot->department,
                    is_primary: (bool) $party->pivot->is_primary,
                );
            }
        }

        return new self(
            id: $contact->id,
            first_name: $contact->first_name,
            last_name: $contact->last_name,
            full_name: $contact->full_name,
            email: $contact->email,
            phone: $contact->phone,
            mobile: $contact->mobile,
            date_of_birth: $contact->date_of_birth?->format('Y-m-d'),
            gender: $contact->gender,
            national_id: $contact->national_id,
            avatar_media_id: $contact->avatar_media_id,
            notes: $contact->notes,
            is_active: $contact->is_active,
            created_at: $contact->created_at?->toIso8601String() ?? '',
            updated_at: $contact->updated_at?->toIso8601String(),
            parties: $parties,
        );
    }
}
