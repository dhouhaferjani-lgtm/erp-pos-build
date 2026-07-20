<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankReconciliationCutoverTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function legacyEndpoints(): array
    {
        $reconciliationId = '019f0000-0000-7000-8000-000000000001';
        $paymentId = '019f0000-0000-7000-8000-000000000002';

        return [
            'index' => ['GET', '/api/v1/bank-reconciliations'],
            'show' => ['GET', "/api/v1/bank-reconciliations/{$reconciliationId}"],
            'summary' => ['GET', "/api/v1/bank-reconciliations/{$reconciliationId}/summary"],
            'store' => ['POST', '/api/v1/bank-reconciliations'],
            'match' => ['POST', "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$paymentId}"],
            'unmatch' => ['POST', "/api/v1/bank-reconciliations/{$reconciliationId}/unmatch/{$paymentId}"],
            'complete' => ['POST', "/api/v1/bank-reconciliations/{$reconciliationId}/complete"],
            'cancel' => ['POST', "/api/v1/bank-reconciliations/{$reconciliationId}/cancel"],
        ];
    }

    #[DataProvider('legacyEndpoints')]
    public function test_legacy_bank_reconciliation_endpoints_are_removed(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertNotFound();
    }
}
