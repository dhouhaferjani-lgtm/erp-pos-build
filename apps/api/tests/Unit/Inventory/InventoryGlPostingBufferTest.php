<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Application\Services\InventoryGlPostingService;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class InventoryGlPostingBufferTest extends TestCase
{
    public function test_marker_discards_only_contexts_enqueued_after_it(): void
    {
        $posting = (new ReflectionClass(InventoryGlPostingService::class))->newInstanceWithoutConstructor();
        $buffer = new InventoryGlPostingBuffer($posting);

        $buffer->enqueue($this->context('movement-before'));
        $mark = $buffer->mark();
        $buffer->enqueue($this->context('movement-after'));

        $buffer->rollbackTo($mark);

        $this->assertFalse($buffer->isEmpty());
        $this->assertSame(1, $buffer->mark());

        $buffer->reset();
        $this->assertTrue($buffer->isEmpty());
    }

    public function test_kind_contract_is_exhaustive(): void
    {
        $this->assertSame(
            ['exit', 'entry', 'count_correction', 'batch_write_off'],
            array_map(static fn (MovementGlKind $kind): string => $kind->value, MovementGlKind::cases()),
        );
    }

    private function context(string $movementId): MovementGlContext
    {
        return new MovementGlContext(
            kind: MovementGlKind::Exit,
            movementId: $movementId,
            companyId: 'company-id',
            currencyCode: 'TND',
            reason: MovementReason::Delivery,
            quantityBefore: '3.0000',
            quantityAfter: '0.0000',
            unitCost: '1.666666',
            sourceType: 'Document',
            sourceId: 'document-id',
            occurredAt: new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            entryDate: new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            postedByUserId: null,
            isHistorical: false,
        );
    }
}
