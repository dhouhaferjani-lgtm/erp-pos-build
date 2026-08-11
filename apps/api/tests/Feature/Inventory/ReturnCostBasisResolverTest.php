<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Application\DTOs\ReturnCostBasis;
use App\Modules\Inventory\Application\Services\ReturnCostBasisResolver;
use App\Modules\Inventory\Domain\StockMovement;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

final class ReturnCostBasisResolverTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
    }

    public function test_fifo_drain_uses_a_stable_weighted_average_of_exit_movements(): void
    {
        $this->dpProduct->update(['cost_price' => '10.000000']);
        $first = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('1.0000')]);
        $this->dpProduct->update(['cost_price' => '20.000000']);
        $second = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('2.0000')]);

        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('3.0000')]);
        $this->dpLinkConvertedShape($invoice, [$first, $second]);
        $return = $this->returnNote($invoice, '2.0000');
        $line = $return->lines->sole();

        $resolver = app(ReturnCostBasisResolver::class);
        $basis = $resolver->resolveForReturnLine($line, '2.0000');
        $again = $resolver->resolveForReturnLine($line, '2.0000');

        $this->assertSame(ReturnCostBasis::SOURCE_EXIT_MOVEMENT, $basis->source);
        $this->assertSame('15.000000', $basis->unitCost);
        $this->assertSame($basis->movementIds, $again->movementIds);
        $this->assertSame(
            StockMovement::query()->whereIn('reference_id', [$first->id, $second->id])
                ->orderBy('occurred_at')->orderBy('id')->limit(2)->pluck('id')->all(),
            $basis->movementIds,
        );
    }

    public function test_no_attributable_exit_falls_back_honestly_to_current_cost(): void
    {
        $this->dpProduct->update(['cost_price' => '27.125000']);
        $return = $this->returnNote(null, '1.0000');
        Log::spy();

        $basis = app(ReturnCostBasisResolver::class)
            ->resolveForReturnLine($return->lines->sole(), '1.0000');

        $this->assertSame(ReturnCostBasis::SOURCE_CURRENT_COST, $basis->source);
        $this->assertSame('27.125000', $basis->unitCost);
        $this->assertSame([], $basis->movementIds);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'no attributable delivery exit exists'),
        );
    }

    private function returnNote(?Document $source, string $quantity): Document
    {
        return $this->dpCreateDocument([
            'type' => DocumentType::ReturnNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'RN-BASIS-'.bin2hex(random_bytes(3)),
            'source_document_id' => $source?->id,
        ], [$this->dpPhysicalLine($quantity)]);
    }
}
