<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Loyalty summary keyed on a partner record — powers the boss-app partner card
 * and the cashier enrollment surface. Not-a-member partners return
 * `is_member: false` with an empty enrollments array.
 */
#[TypeScript]
class PartnerLoyaltySummaryData extends Data
{
    /**
     * @param  DataCollection<int, PartnerEnrollmentSummaryData>|array<int, PartnerEnrollmentSummaryData>  $enrollments
     */
    public function __construct(
        public bool $is_member,
        public ?string $member_id,
        public ?string $phone,
        public ?string $first_name,
        #[DataCollectionOf(PartnerEnrollmentSummaryData::class)]
        public DataCollection|array $enrollments,
    ) {}

    public static function notMember(): self
    {
        return new self(
            is_member: false,
            member_id: null,
            phone: null,
            first_name: null,
            enrollments: [],
        );
    }

    public static function fromMember(LoyaltyMember $member): self
    {
        return new self(
            is_member: true,
            member_id: $member->id,
            phone: $member->phone,
            first_name: $member->first_name,
            enrollments: $member->enrollments
                ->map(fn ($enrollment) => PartnerEnrollmentSummaryData::fromModel($enrollment))
                ->values()
                ->all(),
        );
    }
}
