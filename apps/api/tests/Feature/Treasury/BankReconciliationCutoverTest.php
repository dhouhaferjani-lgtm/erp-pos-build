<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankReconciliationCutoverTest extends TestCase
{
    public function test_legacy_checkpoint_writer_and_demo_seed_entry_point_are_removed(): void
    {
        $this->assertFileDoesNotExist(app_path('Modules/Treasury/Application/Services/BankReconciliationService.php'));
        $this->assertFileDoesNotExist(app_path('Modules/Treasury/Presentation/Controllers/BankReconciliationController.php'));
        $this->assertFileDoesNotExist(app_path('Modules/Treasury/Presentation/Requests/StartBankReconciliationRequest.php'));

        // Legacy read-side models retained zero writers after the ⑤b cutover.
        $this->assertFileDoesNotExist(app_path('Modules/Treasury/Domain/BankReconciliation.php'));
        $this->assertFileDoesNotExist(app_path('Modules/Treasury/Domain/BankReconciliationItem.php'));

        $demoSeeder = file_get_contents(database_path('seeders/DemoPharmacySeeder.php'));
        $this->assertIsString($demoSeeder);
        $this->assertStringNotContainsString('BankReconciliationService', $demoSeeder);
        $this->assertStringNotContainsString('seedTunisiaBankReconciliation', $demoSeeder);
    }

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
