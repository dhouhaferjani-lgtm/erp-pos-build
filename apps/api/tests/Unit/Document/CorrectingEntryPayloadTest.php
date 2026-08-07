<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Document\Application\DTOs\CorrectingEntryLegData;
use App\Modules\Document\Application\DTOs\CorrectingEntryPayload;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * R2-F4 — the typed shape of `documents.payload` for a correcting entry.
 *
 * Rule 3 (strict typing): a JSONB column must have a corresponding PHP DTO.
 * `documents.payload` is the existing `jsonb` column
 * (`2025_11_30_080000_create_documents_table.php:34`, "for additional data per
 * document type") — reusing it is what makes this lane zero-schema, and this DTO
 * is what stops it from becoming an untyped bag.
 *
 * Amounts are `numeric-string` throughout (rule 19): they are written straight
 * into `journal_lines.debit` / `.credit` and must never round-trip through a
 * float.
 */
final class CorrectingEntryPayloadTest extends TestCase
{
    public function test_it_round_trips_through_the_json_array_shape(): void
    {
        $payload = new CorrectingEntryPayload(
            'Stranded VAT on 4457 — missing counterpart leg (W-6 D1b)',
            [
                new CorrectingEntryLegData('acc-1', '19.000', '0', 'Debit VAT'),
                new CorrectingEntryLegData('acc-2', '0', '19.000', null),
            ],
        );

        $restored = CorrectingEntryPayload::fromArray($payload->toArray());

        self::assertSame($payload->reason, $restored->reason);
        self::assertCount(2, $restored->legs);
        self::assertSame('acc-1', $restored->legs[0]->accountId);
        self::assertSame('19.000', $restored->legs[0]->debit);
        self::assertSame('0', $restored->legs[0]->credit);
        self::assertSame('Debit VAT', $restored->legs[0]->description);
        self::assertNull($restored->legs[1]->description);
    }

    /**
     * The payload is stored under a NAMESPACED key so a correcting entry can
     * never collide with the other `payload` tenants (credit-note reasons,
     * supplier-invoice fan-out, POS metadata …).
     */
    public function test_it_reads_and_writes_the_namespaced_payload_key(): void
    {
        $payload = new CorrectingEntryPayload('reason', [
            new CorrectingEntryLegData('acc-1', '1.000', '0', null),
        ]);

        $stored = $payload->toDocumentPayload();

        self::assertArrayHasKey(CorrectingEntryPayload::PAYLOAD_KEY, $stored);
        self::assertSame(
            'reason',
            CorrectingEntryPayload::fromDocumentPayload($stored)->reason,
        );
    }

    public function test_reading_a_document_payload_without_the_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CorrectingEntryPayload::fromDocumentPayload(['something_else' => []]);
    }

    /**
     * A correction with no legs would post an EMPTY journal entry — noise in the
     * chain that corrects nothing.
     */
    public function test_a_payload_with_no_legs_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorrectingEntryPayload('reason', []);
    }

    public function test_an_empty_reason_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorrectingEntryPayload('   ', [
            new CorrectingEntryLegData('acc-1', '1.000', '0', null),
        ]);
    }

    /**
     * The same XOR rule `DoubleEntryValidator::hasValidLines()` enforces on
     * manual entries: a leg is a debit OR a credit, never both and never
     * neither.
     */
    public function test_a_leg_with_both_a_debit_and_a_credit_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorrectingEntryLegData('acc-1', '5.000', '5.000', null);
    }

    public function test_a_leg_with_neither_a_debit_nor_a_credit_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorrectingEntryLegData('acc-1', '0', '0', null);
    }

    public function test_a_negative_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorrectingEntryLegData('acc-1', '-5.000', '0', null);
    }

    public function test_a_non_numeric_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorrectingEntryLegData('acc-1', 'nineteen', '0', null);
    }

    public function test_a_blank_account_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorrectingEntryLegData('', '5.000', '0', null);
    }
}
