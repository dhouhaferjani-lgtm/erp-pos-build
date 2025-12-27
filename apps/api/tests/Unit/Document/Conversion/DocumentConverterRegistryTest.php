<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Conversion;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class DocumentConverterRegistryTest extends TestCase
{
    use RefreshDatabase;

    private DocumentConverterRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new DocumentConverterRegistry;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_register_converter_stores_converter(): void
    {
        $converter = $this->createMockConverter(
            DocumentType::Quote,
            DocumentType::SalesOrder
        );

        $this->registry->register($converter);

        $retrieved = $this->registry->getConverter(
            DocumentType::Quote,
            DocumentType::SalesOrder
        );

        $this->assertNotNull($retrieved);
        $this->assertSame($converter, $retrieved);
    }

    public function test_register_duplicate_converter_throws_exception(): void
    {
        $converter1 = $this->createMockConverter(
            DocumentType::Quote,
            DocumentType::SalesOrder
        );
        $converter2 = $this->createMockConverter(
            DocumentType::Quote,
            DocumentType::SalesOrder
        );

        $this->registry->register($converter1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A converter for quote to sales_order is already registered');

        $this->registry->register($converter2);
    }

    public function test_get_converter_returns_null_for_unregistered_pair(): void
    {
        $converter = $this->registry->getConverter(
            DocumentType::Quote,
            DocumentType::Invoice
        );

        $this->assertNull($converter);
    }

    public function test_convert_uses_registered_converter(): void
    {
        $sourceDocument = $this->createTestDocument(DocumentType::Quote);
        $targetDocument = $this->createTestDocument(DocumentType::SalesOrder);

        $converter = Mockery::mock(DocumentConverterInterface::class);
        $converter->shouldReceive('sourceType')->andReturn(DocumentType::Quote);
        $converter->shouldReceive('targetType')->andReturn(DocumentType::SalesOrder);
        $converter->shouldReceive('convert')
            ->once()
            ->with($sourceDocument, ['option1' => 'value1'])
            ->andReturn($targetDocument);

        $this->registry->register($converter);

        $result = $this->registry->convert(
            $sourceDocument,
            DocumentType::SalesOrder,
            ['option1' => 'value1']
        );

        $this->assertSame($targetDocument, $result);
    }

    public function test_convert_throws_exception_for_unregistered_conversion(): void
    {
        $sourceDocument = $this->createTestDocument(DocumentType::Quote);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No converter registered for quote to invoice conversion');

        $this->registry->convert($sourceDocument, DocumentType::Invoice);
    }

    public function test_can_convert_returns_true_when_converter_allows(): void
    {
        $sourceDocument = $this->createTestDocument(DocumentType::Quote);

        $converter = Mockery::mock(DocumentConverterInterface::class);
        $converter->shouldReceive('sourceType')->andReturn(DocumentType::Quote);
        $converter->shouldReceive('targetType')->andReturn(DocumentType::SalesOrder);
        $converter->shouldReceive('canConvert')
            ->once()
            ->with($sourceDocument)
            ->andReturn(true);

        $this->registry->register($converter);

        $result = $this->registry->canConvert($sourceDocument, DocumentType::SalesOrder);

        $this->assertTrue($result);
    }

    public function test_can_convert_returns_false_when_converter_disallows(): void
    {
        $sourceDocument = $this->createTestDocument(DocumentType::Quote);

        $converter = Mockery::mock(DocumentConverterInterface::class);
        $converter->shouldReceive('sourceType')->andReturn(DocumentType::Quote);
        $converter->shouldReceive('targetType')->andReturn(DocumentType::SalesOrder);
        $converter->shouldReceive('canConvert')
            ->once()
            ->with($sourceDocument)
            ->andReturn(false);

        $this->registry->register($converter);

        $result = $this->registry->canConvert($sourceDocument, DocumentType::SalesOrder);

        $this->assertFalse($result);
    }

    public function test_can_convert_returns_false_for_unregistered_conversion(): void
    {
        $sourceDocument = $this->createTestDocument(DocumentType::Quote);

        $result = $this->registry->canConvert($sourceDocument, DocumentType::Invoice);

        $this->assertFalse($result);
    }

    public function test_get_available_conversions_returns_all_targets(): void
    {
        $quoteToOrder = $this->createMockConverter(
            DocumentType::Quote,
            DocumentType::SalesOrder
        );
        $quoteToInvoice = $this->createMockConverter(
            DocumentType::Quote,
            DocumentType::Invoice
        );
        $orderToInvoice = $this->createMockConverter(
            DocumentType::SalesOrder,
            DocumentType::Invoice
        );

        $this->registry->register($quoteToOrder);
        $this->registry->register($quoteToInvoice);
        $this->registry->register($orderToInvoice);

        $quoteTargets = $this->registry->getAvailableConversions(DocumentType::Quote);
        $orderTargets = $this->registry->getAvailableConversions(DocumentType::SalesOrder);
        $invoiceTargets = $this->registry->getAvailableConversions(DocumentType::Invoice);

        $this->assertCount(2, $quoteTargets);
        $this->assertContains(DocumentType::SalesOrder, $quoteTargets);
        $this->assertContains(DocumentType::Invoice, $quoteTargets);

        $this->assertCount(1, $orderTargets);
        $this->assertContains(DocumentType::Invoice, $orderTargets);

        $this->assertEmpty($invoiceTargets);
    }

    public function test_get_conversion_errors_returns_converter_errors(): void
    {
        $sourceDocument = $this->createTestDocument(DocumentType::Quote);
        $errors = ['Quote is expired', 'Quote is cancelled'];

        $converter = Mockery::mock(DocumentConverterInterface::class);
        $converter->shouldReceive('sourceType')->andReturn(DocumentType::Quote);
        $converter->shouldReceive('targetType')->andReturn(DocumentType::SalesOrder);
        $converter->shouldReceive('getConversionErrors')
            ->once()
            ->with($sourceDocument)
            ->andReturn($errors);

        $this->registry->register($converter);

        $result = $this->registry->getConversionErrors(
            $sourceDocument,
            DocumentType::SalesOrder
        );

        $this->assertSame($errors, $result);
    }

    public function test_get_conversion_errors_returns_error_for_unregistered_conversion(): void
    {
        $sourceDocument = $this->createTestDocument(DocumentType::Quote);

        $result = $this->registry->getConversionErrors(
            $sourceDocument,
            DocumentType::Invoice
        );

        $this->assertCount(1, $result);
        $this->assertStringContainsString('No converter registered', $result[0]);
    }

    public function test_has_converter_returns_correct_boolean(): void
    {
        $converter = $this->createMockConverter(
            DocumentType::Quote,
            DocumentType::SalesOrder
        );

        $this->registry->register($converter);

        $this->assertTrue(
            $this->registry->hasConverter(DocumentType::Quote, DocumentType::SalesOrder)
        );
        $this->assertFalse(
            $this->registry->hasConverter(DocumentType::Quote, DocumentType::Invoice)
        );
        $this->assertFalse(
            $this->registry->hasConverter(DocumentType::SalesOrder, DocumentType::Quote)
        );
    }

    public function test_get_converters_returns_all_registered(): void
    {
        $converter1 = $this->createMockConverter(
            DocumentType::Quote,
            DocumentType::SalesOrder
        );
        $converter2 = $this->createMockConverter(
            DocumentType::SalesOrder,
            DocumentType::Invoice
        );

        $this->registry->register($converter1);
        $this->registry->register($converter2);

        $converters = $this->registry->getConverters();

        $this->assertCount(2, $converters);
        $this->assertArrayHasKey('quote:sales_order', $converters);
        $this->assertArrayHasKey('sales_order:invoice', $converters);
    }

    public function test_empty_registry_returns_empty_arrays(): void
    {
        $this->assertEmpty($this->registry->getConverters());
        $this->assertEmpty($this->registry->getAvailableConversions(DocumentType::Quote));
    }

    /**
     * Create a mock converter for testing.
     */
    private function createMockConverter(
        DocumentType $source,
        DocumentType $target
    ): DocumentConverterInterface {
        $converter = Mockery::mock(DocumentConverterInterface::class);
        $converter->shouldReceive('sourceType')->andReturn($source);
        $converter->shouldReceive('targetType')->andReturn($target);

        return $converter;
    }

    /**
     * Create a test document with the given type.
     */
    private function createTestDocument(DocumentType $type): Document
    {
        // Create minimal related entities needed for the document
        $tenant = \App\Modules\Tenant\Domain\Tenant::factory()->create();
        $company = \App\Modules\Company\Domain\Company::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $partner = \App\Modules\Partner\Domain\Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        return Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => $type,
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => 'TEST-'.strtoupper($type->value).'-001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'balance_due' => '119.00',
        ]);
    }
}
