<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Document\Domain\DTOs\FiscalAuthorityTypes;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalAuthorityMode;
use App\Modules\Document\Domain\Enums\FiscalAuthorityStatus;
use App\Modules\Document\Domain\Enums\PolicyExpertiseStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * C-QR0a — the fiscal-authority vocabulary, pinned.
 *
 * SPEC §1 (fiscal row) + §2.3. These three enums and the one DTO are the ONLY
 * thing this lane ships beyond nullable columns and casts; nothing reads them
 * yet (the resolver, the guard and the refusal are C-QR0b). Pinning the exact
 * case sets here is what makes the later activation lane a behaviour change
 * rather than a vocabulary change: a case added or renamed in C-QR0b has to
 * come here first, in the open.
 *
 * The DTO exists because rule 3 (strict typing) forbids a JSON column without a
 * PHP DTO: `country_document_settings.fiscal_authority_types` is a SET of
 * `DocumentType` values, so it round-trips through a value object that refuses
 * an unknown string instead of an untyped `array`.
 */
final class FiscalAuthorityEnumsTest extends TestCase
{
    public function test_fiscal_authority_mode_cases_are_exact(): void
    {
        self::assertSame(
            ['not_required', 'required'],
            array_map(static fn (FiscalAuthorityMode $m): string => $m->value, FiscalAuthorityMode::cases()),
        );
    }

    public function test_fiscal_authority_status_cases_are_exact(): void
    {
        self::assertSame(
            ['not_required', 'pending', 'accepted', 'rejected'],
            array_map(static fn (FiscalAuthorityStatus $s): string => $s->value, FiscalAuthorityStatus::cases()),
        );
    }

    public function test_policy_expertise_status_cases_are_exact(): void
    {
        self::assertSame(
            ['approved', 'provisional'],
            array_map(static fn (PolicyExpertiseStatus $s): string => $s->value, PolicyExpertiseStatus::cases()),
        );
    }

    /**
     * The `not_required` value is shared by the country MODE and the per-document
     * STATUS on purpose (SPEC §2.3: a DN/RN row initialises to `not_required`
     * regardless of the country mode). They are still distinct types — a mode may
     * never be stored in the status column and vice versa.
     */
    public function test_mode_and_status_are_distinct_types(): void
    {
        self::assertNotInstanceOf(FiscalAuthorityStatus::class, FiscalAuthorityMode::NotRequired);
        self::assertNull(FiscalAuthorityMode::tryFrom('pending'));
        self::assertNull(FiscalAuthorityStatus::tryFrom('required'));
    }

    public function test_the_dto_round_trips_the_json_list(): void
    {
        $types = FiscalAuthorityTypes::fromArray(['invoice', 'credit_note']);

        self::assertSame(['invoice', 'credit_note'], $types->toArray());
        self::assertSame(['invoice', 'credit_note'], json_decode(json_encode($types, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['invoice', 'credit_note'], FiscalAuthorityTypes::fromArray($types->toArray())->toArray());
    }

    /**
     * Canonical order = `DocumentType::cases()` order, so the stored JSON is a
     * SET and two rows that mean the same thing compare equal byte-for-byte.
     */
    public function test_the_dto_canonicalises_order_and_deduplicates(): void
    {
        $types = FiscalAuthorityTypes::fromArray(['credit_note', 'invoice', 'invoice']);

        self::assertSame(['invoice', 'credit_note'], $types->toArray());
    }

    public function test_the_dto_refuses_a_value_that_is_not_a_document_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FiscalAuthorityTypes::fromArray(['invoice', 'not_a_document_type']);
    }

    public function test_the_dto_exposes_membership_and_emptiness(): void
    {
        $types = FiscalAuthorityTypes::of(DocumentType::Invoice, DocumentType::CreditNote);

        self::assertTrue($types->contains(DocumentType::Invoice));
        self::assertFalse($types->contains(DocumentType::DeliveryNote));
        self::assertFalse($types->isEmpty());
        self::assertTrue(FiscalAuthorityTypes::none()->isEmpty());
        self::assertSame([], FiscalAuthorityTypes::none()->toArray());
    }
}
