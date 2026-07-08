<?php

declare(strict_types=1);

namespace Tests\Unit\DocumentIngestion;

use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use App\Modules\DocumentIngestion\Application\Services\ExtractionReconciler;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use PHPUnit\Framework\TestCase;

final class ExtractionReconcilerTest extends TestCase
{
    public function test_tnd_invoice_fixture_is_consistent_at_currency_scale(): void
    {
        $result = $this->reconciler()->reconcile($this->fixture('extraction_invoice_fr.json'), 'TND');

        $this->assertTrue($result->consistent);
        $this->assertSame([], $result->flags);
    }

    public function test_invoice_subtotal_off_by_one_millieme_is_flagged(): void
    {
        $payload = $this->fixturePayload('extraction_invoice_fr.json');
        $payload['header']['subtotal']['value'] = '244.751';

        $result = $this->reconciler()->reconcile(ExtractionResultData::from($payload), 'TND');

        $this->assertFalse($result->consistent);
        $this->assertContains('subtotal_mismatch', $result->flags);
    }

    public function test_invoice_line_total_mismatch_identifies_one_based_line_number(): void
    {
        $payload = $this->fixturePayload('extraction_invoice_fr.json');
        $payload['lines'][1]['line_total']['value'] = '93.751';

        $result = $this->reconciler()->reconcile(ExtractionResultData::from($payload), 'TND');

        $this->assertFalse($result->consistent);
        $this->assertContains('line_2_total_mismatch', $result->flags);
        $this->assertContains('subtotal_mismatch', $result->flags);
    }

    public function test_delivery_note_without_prices_is_consistent_when_quantities_exist(): void
    {
        $result = $this->reconciler()->reconcile($this->fixture('extraction_bl_fr.json'), 'TND');

        $this->assertTrue($result->consistent);
        $this->assertSame([], $result->flags);
    }

    private function reconciler(): ExtractionReconciler
    {
        return new ExtractionReconciler(new class implements CurrencyScaleResolverInterface
        {
            public function getScale(?string $currencyCode = null): int
            {
                if ($currencyCode === null) {
                    throw new \RuntimeException('Reconciler must pass an explicit currency.');
                }

                return $currencyCode === 'TND' ? 3 : 2;
            }

            public function getScaleSafe(?string $currencyCode = null, int $fallback = 3): int
            {
                return $this->getScale($currencyCode);
            }
        });
    }

    private function fixture(string $name): ExtractionResultData
    {
        return ExtractionResultData::from($this->fixturePayload($name));
    }

    /**
     * @return array<string, mixed>
     */
    private function fixturePayload(string $name): array
    {
        $json = file_get_contents(__DIR__.'/../../Fixtures/document_ingestion/'.$name);
        $this->assertIsString($json);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
