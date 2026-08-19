<?php

declare(strict_types=1);

use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DocumentType;
use Carbon\CarbonImmutable;
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
    private const BACKFILL_CHUNK_SIZE = 100;

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

        /** @var array<string, array<string, int>> $countsByTenant */
        $countsByTenant = [];
        $sawDeliveryNote = false;

        DB::table('documents')
            ->select(['id', 'tenant_id', 'company_id', 'payload'])
            ->where('type', DocumentType::DeliveryNote->value)
            ->orderBy('id')
            ->chunkById(self::BACKFILL_CHUNK_SIZE, function ($deliveryNotes) use (&$countsByTenant, &$sawDeliveryNote): void {
                foreach ($deliveryNotes as $deliveryNote) {
                    $sawDeliveryNote = true;
                    $tenantId = (string) $deliveryNote->tenant_id;
                    if (! array_key_exists($tenantId, $countsByTenant)) {
                        $countsByTenant[$tenantId] = $this->newCounts();
                    }
                    $counts = &$countsByTenant[$tenantId];

                    $payload = $this->payload($deliveryNote->payload);
                    if (! array_key_exists('invoiced_at', $payload) || $payload['invoiced_at'] === null) {
                        continue;
                    }

                    $invoicedAt = $this->safeInvoicedAt($payload, $counts);
                    if ($invoicedAt === null) {
                        continue;
                    }

                    $invoicedVia = $this->billingLane($payload, $counts);
                    $invoiceId = $this->safeInvoiceId($payload, (string) $deliveryNote->company_id, $counts);

                    if ($invoiceId === null) {
                        $invoicedVia = DeliveryNoteBillingLane::LegacyUnknown->value;
                    }

                    DB::table('delivery_note_billing_marks')->insert([
                        'delivery_note_id' => $deliveryNote->id,
                        'invoice_id' => $invoiceId,
                        'invoiced_via' => $invoicedVia,
                        'invoiced_at' => $invoicedAt,
                        'company_id' => $deliveryNote->company_id,
                    ]);
                    $counts['rows_written']++;
                }
            }, 'id');

        if (! $sawDeliveryNote) {
            $currentTenant = function_exists('tenant') ? tenant() : null;
            $tenantId = $currentTenant?->getTenantKey();
            $this->logCounts($tenantId === null ? null : (string) $tenantId, $this->newCounts());

            return;
        }

        foreach ($countsByTenant as $tenantId => $counts) {
            $this->logCounts($tenantId, $counts);
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

    /**
     * Validate `payload.invoiced_at` before it reaches a NOT NULL timestamptz column.
     *
     * Every other legacy shape in this backfill is validated and counted; `invoiced_at`
     * alone went in raw, so a truthy-but-unparseable value (`true`, `"yes"`, a blank
     * string, an object) raised 22007/22P02 and ABORTED the migration — contradicting the
     * backfill's own contract that dirty rows are neutralised rather than fatal, and doing
     * so during `tenants:migrate`, which this repository auto-runs on every push to
     * origin/dev. Count and skip instead, exactly like `unparseable_invoice_id`.
     *
     * The value Carbon RESOLVED is what gets inserted — not the raw string.
     *
     * F-6 as first written validated with `CarbonImmutable::parse()` but inserted the raw
     * string, leaving PostgreSQL to parse it a second time with a DIFFERENT grammar, so the
     * abort path was narrowed rather than closed. Measured:
     *
     *   value        CarbonImmutable::parse   ::timestamptz
     *   '+1 day'     OK                       ERROR 22007   <- still aborted tenants:migrate
     *   '@175…'      OK                       ERROR 22008   <- still aborted tenants:migrate
     *   'now'        OK                       OK, but resolved at INSERT time
     *   'yes', '0'   THROW (counted)          ERROR
     *
     * Inserting `$parsed->toIso8601String()` collapses the two grammars into Carbon's alone:
     * every value this method accepts is now, by construction, a value PostgreSQL accepts,
     * and a relative value resolves once, here, rather than again at INSERT. A well-formed
     * absolute timestamp keeps its instant and its offset (ISO 8601 is round-trip exact for
     * the `timestamptz` column), so nothing about the existing backfill's output changes.
     * (M5-terminal treasury F-6; completed in r2 by treasury `R2-4`.)
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $counts
     */
    private function safeInvoicedAt(array $payload, array &$counts): ?string
    {
        $invoicedAt = $payload['invoiced_at'];
        if (! is_string($invoicedAt) || trim($invoicedAt) === '') {
            $counts['unparseable_invoiced_at']++;

            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($invoicedAt);
        } catch (Throwable) {
            $counts['unparseable_invoiced_at']++;

            return null;
        }

        return $parsed->toIso8601String();
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
            'unparseable_invoiced_at' => 0,
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
