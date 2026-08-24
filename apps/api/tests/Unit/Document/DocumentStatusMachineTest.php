<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentStatusMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The N-6 adjacency map, stated as a table so a future edit has to change a
 * test row rather than slip through.
 */
final class DocumentStatusMachineTest extends TestCase
{
    private DocumentStatusMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new DocumentStatusMachine;
    }

    /**
     * @return list<array{DocumentStatus, DocumentStatus}>
     */
    public static function allowedEdges(): array
    {
        return [
            [DocumentStatus::Draft, DocumentStatus::Confirmed],
            [DocumentStatus::Draft, DocumentStatus::Cancelled],
            [DocumentStatus::Confirmed, DocumentStatus::Draft],
            [DocumentStatus::Confirmed, DocumentStatus::Posted],
            [DocumentStatus::Confirmed, DocumentStatus::Cancelled],
            [DocumentStatus::Posted, DocumentStatus::Paid],
            [DocumentStatus::Posted, DocumentStatus::Cancelled],
            [DocumentStatus::Paid, DocumentStatus::Posted],
        ];
    }

    #[DataProvider('allowedEdges')]
    public function test_allowed_edges(DocumentStatus $from, DocumentStatus $to): void
    {
        $this->assertTrue($this->machine->isAllowed($from, $to), "{$from->value} -> {$to->value} must be allowed");
    }

    public function test_confirmed_to_paid_is_impossible(): void
    {
        $this->assertFalse(
            $this->machine->isAllowed(DocumentStatus::Confirmed, DocumentStatus::Paid),
            'This is the whole lane: a payment on a confirmed invoice is an advance, not a settlement.',
        );
        $this->assertNotContains(
            DocumentStatus::Paid,
            $this->machine->allowedTargetsOf(DocumentStatus::Confirmed),
        );
    }

    public function test_draft_to_paid_and_draft_to_posted_are_impossible(): void
    {
        $this->assertFalse($this->machine->isAllowed(DocumentStatus::Draft, DocumentStatus::Paid));
        $this->assertFalse($this->machine->isAllowed(DocumentStatus::Draft, DocumentStatus::Posted));
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->assertSame([], $this->machine->allowedTargetsOf(DocumentStatus::Cancelled));

        foreach (DocumentStatus::cases() as $target) {
            $this->assertFalse($this->machine->isAllowed(DocumentStatus::Cancelled, $target));
        }
    }

    public function test_paid_may_only_go_back_to_posted(): void
    {
        $this->assertSame([DocumentStatus::Posted], $this->machine->allowedTargetsOf(DocumentStatus::Paid));
    }

    public function test_self_loops_are_never_allowed(): void
    {
        foreach (DocumentStatus::cases() as $status) {
            $this->assertFalse($this->machine->isAllowed($status, $status), "{$status->value} self-loop must be refused");
        }
    }

    /**
     * F-6 — the four live `Draft -> Posted` posting services (supplier invoice,
     * supplier credit note, expense, income) are a DIFFERENT lifecycle, not a
     * hole. The sales lifecycle must keep the edge forbidden: `Confirmed` is
     * where the delivery-compliance gate, the GL pre-flight and the numbering
     * decision live for an invoice.
     */
    public function test_draft_to_posted_is_legal_only_for_the_types_that_post_directly(): void
    {
        foreach ([
            DocumentType::SupplierInvoice,
            DocumentType::SupplierCreditNote,
            DocumentType::Expense,
            DocumentType::Income,
        ] as $type) {
            $this->assertTrue(
                $this->machine->isAllowed(DocumentStatus::Draft, DocumentStatus::Posted, $type),
                "{$type->value} posts directly from draft",
            );
        }

        foreach ([DocumentType::Invoice, DocumentType::CreditNote, DocumentType::SalesOrder, DocumentType::Quote] as $type) {
            $this->assertFalse(
                $this->machine->isAllowed(DocumentStatus::Draft, DocumentStatus::Posted, $type),
                "{$type->value} must go through Confirmed",
            );
        }

        $this->assertFalse(
            $this->machine->isAllowed(DocumentStatus::Draft, DocumentStatus::Posted),
            'with no type in hand the edge stays forbidden — fail closed',
        );
    }

    public function test_a_direct_posting_type_still_cannot_jump_from_confirmed_to_paid(): void
    {
        $this->assertFalse(
            $this->machine->isAllowed(DocumentStatus::Confirmed, DocumentStatus::Paid, DocumentType::SupplierInvoice),
            'type-awareness must not widen the one edge this lane exists to close',
        );
    }

    public function test_every_status_has_a_defined_row(): void
    {
        foreach (DocumentStatus::cases() as $status) {
            // A missing match arm would raise \UnhandledMatchError.
            $this->machine->allowedTargetsOf($status);
        }

        $this->addToAssertionCount(count(DocumentStatus::cases()));
    }
}
