<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use PHPUnit\Framework\TestCase;

final class DocumentTypePurchaseRfqTest extends TestCase
{
    public function test_purchase_quote_request_type_has_required_contract(): void
    {
        $type = DocumentType::PurchaseQuoteRequest;

        $this->assertSame('purchase_rfq', $type->value);
        $this->assertLessThanOrEqual(20, strlen($type->value));
        $this->assertSame('DP', $type->getPrefix());
        $this->assertSame('Purchase Quote Request', $type->label());
        $this->assertFalse($type->affectsReceivable());
        $this->assertFalse($type->canTransitionToPaid());
        $this->assertSame(FiscalCategory::NonFiscal, FiscalCategory::fromDocumentType($type));
    }
}
