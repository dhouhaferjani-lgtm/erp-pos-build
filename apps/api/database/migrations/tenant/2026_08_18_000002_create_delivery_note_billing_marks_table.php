<?php

declare(strict_types=1);

use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * M1C — durable delivery-note billing markers and legacy payload backfill.
 *
 * This is deliberately a direct migration write. Runtime claim semantics are
 * introduced separately; this backfill only preserves the best safe legacy
 * attribution and makes every stamped delivery note observable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documents') || ! Schema::hasTable('companies')) {
            return;
        }

        if (Schema::hasTable('delivery_note_billing_marks')) {
            if ($this->markerTableIsComplete()) {
                return;
            }

            throw new RuntimeException(
                'delivery_note_billing_marks exists but is incomplete; repair the schema before rerunning the migration.',
            );
        }

        Schema::create('delivery_note_billing_marks', function (Blueprint $table): void {
            $table->uuid('delivery_note_id')->primary();
            $table->uuid('invoice_id')->nullable();
            $table->string('invoiced_via', 32);
            $table->timestampTz('invoiced_at');
            $table->uuid('company_id');

            $table->foreign('delivery_note_id')
                ->references('id')
                ->on('documents');
            $table->foreign('invoice_id')
                ->references('id')
                ->on('documents')
                ->onDelete('restrict');
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->onDelete('cascade');
            $table->index('company_id');
        });

        $deliveryNotesByTenant = DB::table('documents')
            ->select(['id', 'tenant_id', 'company_id', 'payload'])
            ->where('type', DocumentType::DeliveryNote->value)
            ->orderBy('id')
            ->get()
            ->groupBy('tenant_id');

        if ($deliveryNotesByTenant->isEmpty()) {
            $currentTenant = function_exists('tenant') ? tenant() : null;
            $tenantId = $currentTenant?->getTenantKey();
            $this->logCounts($tenantId === null ? null : (string) $tenantId, $this->newCounts());

            return;
        }

        foreach ($deliveryNotesByTenant as $tenantId => $deliveryNotes) {
            $counts = $this->newCounts();

            foreach ($deliveryNotes as $deliveryNote) {
                $payload = $this->payload($deliveryNote->payload);
                if (! array_key_exists('invoiced_at', $payload) || $payload['invoiced_at'] === null) {
                    continue;
                }

                $invoicedVia = $this->billingLane($payload, $counts);
                $invoiceId = $this->safeInvoiceId($payload, (string) $deliveryNote->company_id, $counts);

                if ($invoiceId === null) {
                    $invoicedVia = DeliveryNoteBillingLane::LegacyUnknown->value;
                }

                if (DB::table('delivery_note_billing_marks')
                    ->where('delivery_note_id', $deliveryNote->id)
                    ->exists()) {
                    continue;
                }

                DB::table('delivery_note_billing_marks')->insert([
                    'delivery_note_id' => $deliveryNote->id,
                    'invoice_id' => $invoiceId,
                    'invoiced_via' => $invoicedVia,
                    'invoiced_at' => $payload['invoiced_at'],
                    'company_id' => $deliveryNote->company_id,
                ]);
                $counts['rows_written']++;
            }

            $this->logCounts((string) $tenantId, $counts);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('delivery_note_billing_marks')) {
            Schema::drop('delivery_note_billing_marks');
        }
    }

    /** @return array<string, mixed> */
    private function payload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload)) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload @param array<string, int> $counts */
    private function billingLane(array $payload, array &$counts): string
    {
        if (! array_key_exists('invoiced_via', $payload) || ! is_string($payload['invoiced_via'])) {
            $counts['missing_invoiced_via']++;

            return DeliveryNoteBillingLane::LegacyUnknown->value;
        }

        return DeliveryNoteBillingLane::tryFrom($payload['invoiced_via'])?->value
            ?? DeliveryNoteBillingLane::LegacyUnknown->value;
    }

    /** @param array<string, mixed> $payload @param array<string, int> $counts */
    private function safeInvoiceId(array $payload, string $companyId, array &$counts): ?string
    {
        if (! array_key_exists('invoice_id', $payload) || $payload['invoice_id'] === null) {
            $counts['missing_invoice_id']++;

            return null;
        }

        $invoiceId = $payload['invoice_id'];
        if (! is_string($invoiceId) || trim($invoiceId) === '' || ! Str::isUuid($invoiceId)) {
            $counts['unparseable_invoice_id']++;

            return null;
        }

        $invoice = DB::table('documents')
            ->select(['id', 'company_id', 'type'])
            ->where('id', $invoiceId)
            ->first();

        if ($invoice === null) {
            $counts['dangling_invoice_id']++;

            return null;
        }

        if ($invoice->company_id !== $companyId) {
            $counts['cross_company_invoice_id']++;

            return null;
        }

        if ($invoice->type !== DocumentType::Invoice->value) {
            $counts['non_invoice_document_id']++;

            return null;
        }

        return $invoiceId;
    }

    private function markerTableIsComplete(): bool
    {
        $columns = collect(Schema::getColumns('delivery_note_billing_marks'))->keyBy('name');
        $expectedNullability = [
            'delivery_note_id' => false,
            'invoice_id' => true,
            'invoiced_via' => false,
            'invoiced_at' => false,
            'company_id' => false,
        ];

        if ($columns->count() !== count($expectedNullability)) {
            return false;
        }

        foreach ($expectedNullability as $column => $nullable) {
            if (! $columns->has($column) || $columns->get($column)['nullable'] !== $nullable) {
                return false;
            }
        }

        $indexes = collect(Schema::getIndexes('delivery_note_billing_marks'));
        $hasPrimaryKey = $indexes->contains(
            static fn (array $index): bool => $index['primary'] === true
                && $index['columns'] === ['delivery_note_id'],
        );
        $hasCompanyIndex = $indexes->contains(
            static fn (array $index): bool => $index['columns'] === ['company_id'],
        );

        if (! $hasPrimaryKey || ! $hasCompanyIndex) {
            return false;
        }

        $foreignKeys = collect(Schema::getForeignKeys('delivery_note_billing_marks'));
        foreach ([
            ['delivery_note_id', 'documents', 'id', 'no action'],
            ['invoice_id', 'documents', 'id', 'restrict'],
            ['company_id', 'companies', 'id', 'cascade'],
        ] as [$column, $foreignTable, $foreignColumn, $onDelete]) {
            $matches = $foreignKeys->contains(
                static fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]
                    && $foreignKey['foreign_table'] === $foreignTable
                    && $foreignKey['foreign_columns'] === [$foreignColumn]
                    && $foreignKey['on_delete'] === $onDelete,
            );

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, int> */
    private function newCounts(): array
    {
        return [
            'rows_written' => 0,
            'missing_invoice_id' => 0,
            'unparseable_invoice_id' => 0,
            'dangling_invoice_id' => 0,
            'cross_company_invoice_id' => 0,
            'non_invoice_document_id' => 0,
            'missing_invoiced_via' => 0,
        ];
    }

    /** @param array<string, int> $counts */
    private function logCounts(?string $tenantId, array $counts): void
    {
        Log::info('delivery_note_billing_marks.backfill', array_merge([
            'tenant_id' => $tenantId,
            'database' => DB::connection()->getDatabaseName(),
        ], $counts));
    }
};
