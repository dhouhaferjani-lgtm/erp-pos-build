<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Services;

use App\Modules\Loyalty\Domain\Entities\MemberStampCard;
use App\Modules\Loyalty\Domain\Entities\StampCardDefinition;
use App\Modules\Loyalty\Domain\Services\StampCardService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class StampCardServiceTest extends TestCase
{
    private StampCardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StampCardService;
    }

    /** @test */
    public function it_qualifies_when_transaction_matches_qualifying_products(): void
    {
        $definition = $this->createDefinition([
            'qualifying_items' => [
                'product_ids' => ['prod-1', 'prod-2'],
            ],
        ]);

        $transactionData = [
            'items' => [
                ['product_id' => 'prod-1', 'quantity' => 1],
            ],
        ];

        $result = $this->service->qualifiesForStamp($definition, $transactionData);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_does_not_qualify_when_transaction_missing_qualifying_products(): void
    {
        $definition = $this->createDefinition([
            'qualifying_items' => [
                'product_ids' => ['prod-1', 'prod-2'],
            ],
        ]);

        $transactionData = [
            'items' => [
                ['product_id' => 'prod-3', 'quantity' => 1],
            ],
        ];

        $result = $this->service->qualifiesForStamp($definition, $transactionData);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_qualifies_when_no_qualifying_items_configured(): void
    {
        $definition = $this->createDefinition([
            'qualifying_items' => [
                'all_products' => true,
            ],
        ]);

        $transactionData = [
            'items' => [
                ['product_id' => 'any-product', 'quantity' => 1],
            ],
        ];

        $result = $this->service->qualifiesForStamp($definition, $transactionData);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_qualifies_when_transaction_matches_qualifying_categories(): void
    {
        $definition = $this->createDefinition([
            'qualifying_items' => [
                'category_ids' => ['cat-1', 'cat-2'],
            ],
        ]);

        $transactionData = [
            'items' => [
                ['product_id' => 'prod-1', 'category_id' => 'cat-1', 'quantity' => 1],
            ],
        ];

        $result = $this->service->qualifiesForStamp($definition, $transactionData);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_does_not_qualify_when_product_is_excluded(): void
    {
        $definition = $this->createDefinition([
            'qualifying_items' => [
                'all_products' => true,
                'excluded_product_ids' => ['prod-excluded'],
            ],
        ]);

        $transactionData = [
            'items' => [
                ['product_id' => 'prod-excluded', 'quantity' => 1],
            ],
        ];

        $result = $this->service->qualifiesForStamp($definition, $transactionData);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_does_not_qualify_when_category_is_excluded(): void
    {
        $definition = $this->createDefinition([
            'qualifying_items' => [
                'all_products' => true,
                'excluded_category_ids' => ['cat-excluded'],
            ],
        ]);

        $transactionData = [
            'items' => [
                ['product_id' => 'prod-1', 'category_id' => 'cat-excluded', 'quantity' => 1],
            ],
        ];

        $result = $this->service->qualifiesForStamp($definition, $transactionData);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_calculates_stamps_returns_one_for_basic_qualifying_transaction(): void
    {
        $definition = $this->createDefinition([
            'stamps_per_item' => 1,
            'qualifying_items' => [
                'product_ids' => ['prod-1'],
            ],
        ]);

        $transactionData = [
            'items' => [
                ['product_id' => 'prod-1', 'quantity' => 1],
            ],
        ];

        $stamps = $this->service->calculateStamps($definition, $transactionData);

        $this->assertSame(1, $stamps);
    }

    /** @test */
    public function it_calculates_stamps_returns_multiple_for_multiple_qualifying_items(): void
    {
        $definition = $this->createDefinition([
            'stamps_per_item' => 1,
            'qualifying_items' => [
                'product_ids' => ['prod-1', 'prod-2'],
            ],
        ]);

        $transactionData = [
            'items' => [
                ['product_id' => 'prod-1', 'quantity' => 2],
                ['product_id' => 'prod-2', 'quantity' => 1],
            ],
        ];

        $stamps = $this->service->calculateStamps($definition, $transactionData);

        $this->assertSame(3, $stamps); // 2 + 1
    }

    /** @test */
    public function it_calculates_stamps_returns_zero_for_non_qualifying_transaction(): void
    {
        $definition = $this->createDefinition([
            'stamps_per_item' => 1,
            'qualifying_items' => [
                'product_ids' => ['prod-1'],
            ],
        ]);

        $transactionData = [
            'items' => [
                ['product_id' => 'prod-99', 'quantity' => 5],
            ],
        ];

        $stamps = $this->service->calculateStamps($definition, $transactionData);

        $this->assertSame(0, $stamps);
    }

    /** @test */
    public function it_calculates_stamps_applies_stamp_multiplier(): void
    {
        $definition = $this->createDefinition([
            'stamps_per_item' => 2,
            'qualifying_items' => [
                'product_ids' => ['prod-1'],
            ],
        ]);

        $transactionData = [
            'items' => [
                ['product_id' => 'prod-1', 'quantity' => 3],
            ],
        ];

        $stamps = $this->service->calculateStamps($definition, $transactionData);

        $this->assertSame(6, $stamps); // 3 items * 2 stamps per item
    }

    /** @test */
    public function it_respects_min_price_qualification(): void
    {
        $definition = $this->createDefinition([
            'stamps_per_item' => 1,
            'qualifying_items' => [
                'all_products' => true,
                'min_price' => '10.00',
            ],
        ]);

        $transactionDataQualifies = [
            'items' => [
                ['product_id' => 'prod-1', 'price' => '15.00', 'quantity' => 1],
            ],
        ];

        $transactionDataDoesNotQualify = [
            'items' => [
                ['product_id' => 'prod-2', 'price' => '5.00', 'quantity' => 1],
            ],
        ];

        $this->assertTrue($this->service->qualifiesForStamp($definition, $transactionDataQualifies));
        $this->assertFalse($this->service->qualifiesForStamp($definition, $transactionDataDoesNotQualify));
    }

    /** @test */
    public function it_respects_max_price_qualification(): void
    {
        $definition = $this->createDefinition([
            'stamps_per_item' => 1,
            'qualifying_items' => [
                'all_products' => true,
                'max_price' => '50.00',
            ],
        ]);

        $transactionDataQualifies = [
            'items' => [
                ['product_id' => 'prod-1', 'price' => '30.00', 'quantity' => 1],
            ],
        ];

        $transactionDataDoesNotQualify = [
            'items' => [
                ['product_id' => 'prod-2', 'price' => '75.00', 'quantity' => 1],
            ],
        ];

        $this->assertTrue($this->service->qualifiesForStamp($definition, $transactionDataQualifies));
        $this->assertFalse($this->service->qualifiesForStamp($definition, $transactionDataDoesNotQualify));
    }

    /** @test */
    public function it_checks_if_card_is_complete(): void
    {
        $definition = $this->createDefinition(['stamps_required' => 10]);

        $incompleteCard = $this->createMemberCard([
            'current_stamps' => 5,
        ]);

        $completeCard = $this->createMemberCard([
            'current_stamps' => 10,
        ]);

        $this->assertFalse($this->service->isComplete($incompleteCard, $definition));
        $this->assertTrue($this->service->isComplete($completeCard, $definition));
    }

    /** @test */
    public function it_checks_if_card_is_complete_when_exceeds_required(): void
    {
        $definition = $this->createDefinition(['stamps_required' => 10]);

        $card = $this->createMemberCard([
            'current_stamps' => 15,
        ]);

        $this->assertTrue($this->service->isComplete($card, $definition));
    }

    /** @test */
    public function it_calculates_stamps_remaining(): void
    {
        $definition = $this->createDefinition(['stamps_required' => 10]);

        $card = $this->createMemberCard([
            'current_stamps' => 3,
        ]);

        $remaining = $this->service->stampsRemaining($card, $definition);

        $this->assertSame(7, $remaining);
    }

    /** @test */
    public function it_returns_zero_stamps_remaining_when_complete(): void
    {
        $definition = $this->createDefinition(['stamps_required' => 10]);

        $card = $this->createMemberCard([
            'current_stamps' => 10,
        ]);

        $remaining = $this->service->stampsRemaining($card, $definition);

        $this->assertSame(0, $remaining);
    }

    /** @test */
    public function it_returns_zero_stamps_remaining_when_over_complete(): void
    {
        $definition = $this->createDefinition(['stamps_required' => 10]);

        $card = $this->createMemberCard([
            'current_stamps' => 15,
        ]);

        $remaining = $this->service->stampsRemaining($card, $definition);

        $this->assertSame(0, $remaining);
    }

    /** @test */
    public function it_calculates_progress_percentage(): void
    {
        $definition = $this->createDefinition(['stamps_required' => 10]);

        $card = $this->createMemberCard([
            'current_stamps' => 5,
        ]);

        $percentage = $this->service->progressPercentage($card, $definition);

        $this->assertEquals(50.0, $percentage);
    }

    /** @test */
    public function it_returns_hundred_percent_when_complete(): void
    {
        $definition = $this->createDefinition(['stamps_required' => 10]);

        $card = $this->createMemberCard([
            'current_stamps' => 10,
        ]);

        $percentage = $this->service->progressPercentage($card, $definition);

        $this->assertEquals(100.0, $percentage);
    }

    /** @test */
    public function it_caps_progress_percentage_at_hundred(): void
    {
        $definition = $this->createDefinition(['stamps_required' => 10]);

        $card = $this->createMemberCard([
            'current_stamps' => 15,
        ]);

        $percentage = $this->service->progressPercentage($card, $definition);

        $this->assertEquals(100.0, $percentage);
    }

    /** @test */
    public function it_returns_zero_progress_percentage_when_stamps_required_is_zero(): void
    {
        $definition = $this->createDefinition(['stamps_required' => 0]);

        $card = $this->createMemberCard([
            'current_stamps' => 5,
        ]);

        $percentage = $this->service->progressPercentage($card, $definition);

        $this->assertEquals(0.0, $percentage);
    }

    /** @test */
    public function it_handles_empty_transaction_items(): void
    {
        $definition = $this->createDefinition([
            'qualifying_items' => [
                'product_ids' => ['prod-1'],
            ],
        ]);

        $transactionData = [
            'items' => [],
        ];

        $result = $this->service->qualifiesForStamp($definition, $transactionData);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_handles_missing_items_key_in_transaction(): void
    {
        $definition = $this->createDefinition([
            'qualifying_items' => [
                'product_ids' => ['prod-1'],
            ],
        ]);

        $transactionData = [];

        $result = $this->service->qualifiesForStamp($definition, $transactionData);

        $this->assertFalse($result);
    }

    /**
     * Create a stub StampCardDefinition
     *
     * @param  array<string, mixed>  $attributes
     * @return object{stamps_required: int, stamps_per_item: int, qualifying_items: array<string, mixed>}
     */
    private function createDefinition(array $attributes = []): object
    {
        $defaults = [
            'stamps_required' => 10,
            'stamps_per_item' => 1,
            'qualifying_items' => ['all_products' => true],
        ];

        $merged = array_merge($defaults, $attributes);

        return (object) $merged;
    }

    /**
     * Create a stub MemberStampCard
     *
     * @param  array<string, mixed>  $attributes
     * @return object{current_stamps: int, completed_at: Carbon|null}
     */
    private function createMemberCard(array $attributes = []): object
    {
        $defaults = [
            'current_stamps' => 0,
            'completed_at' => null,
        ];

        $merged = array_merge($defaults, $attributes);

        return (object) $merged;
    }
}
