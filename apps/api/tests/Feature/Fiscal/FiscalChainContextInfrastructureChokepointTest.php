<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use Tests\TestCase;

final class FiscalChainContextInfrastructureChokepointTest extends TestCase
{
    public function test_fiscal_ingest_schema_and_verifier_are_context_scoped(): void
    {
        $root = base_path();

        $envelope = $this->read($root.'/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php');
        $this->assertStringContainsString('public string $chainContext', $envelope);
        $this->assertStringContainsString("'z_session'", $envelope);
        $this->assertStringContainsString("'training_z_session'", $envelope);

        $ingestor = $this->read($root.'/app/Modules/Fiscal/Application/Services/OutboxIngestor.php');
        $this->assertStringContainsString("->where('chain_context', \$envelope->chainContext)", $ingestor);
        $this->assertStringContainsString('tenant_id, company_id, terminal_id, chain_context, sequence_number', $ingestor);
        $this->assertStringContainsString("'chain_context' => \$envelope->chainContext", $ingestor);

        $migration = $this->read($root.'/database/migrations/tenant/2026_05_14_100001_create_fiscal_events_table.php');
        $this->assertStringContainsString("\$table->string('chain_context', 32)", $migration);
        $this->assertStringContainsString("'tenant_id', 'company_id', 'terminal_id', 'chain_context', 'sequence_number'", $migration);

        $verifier = $this->read($root.'/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php');
        $this->assertStringContainsString('{--chain-context=operational', $verifier);
        $this->assertStringContainsString("->where('chain_context', \$chainContext)", $verifier);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        $this->assertIsString($contents, sprintf('Expected to read %s', $path));

        return $contents;
    }
}
