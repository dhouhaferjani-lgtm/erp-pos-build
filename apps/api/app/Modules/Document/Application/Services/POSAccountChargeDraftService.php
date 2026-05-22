<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Document\Application\DTOs\CreatePOSAccountChargeDraftCommand;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class POSAccountChargeDraftService
{
    public function createDraft(CreatePOSAccountChargeDraftCommand $command): Document
    {
        return DB::transaction(function () use ($command): Document {
            $reference = 'POS-ACCOUNT-CHARGE:'.$command->fiscalEventId;

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'SELECT pg_advisory_xact_lock(hashtext(?))',
                    [$command->tenantId.':'.$command->companyId.':'.$reference.':pos_account_charge_draft'],
                );
            }

            $existingDocuments = Document::query()
                ->with('lines')
                ->where('tenant_id', $command->tenantId)
                ->where('company_id', $command->companyId)
                ->where('reference', $reference)
                ->get();

            if ($existingDocuments->count() > 1) {
                throw new RuntimeException('pos_account_charge_draft_conflict:multiple_documents_for_event');
            }

            $existing = $existingDocuments->first();
            if ($existing instanceof Document) {
                $this->assertExistingDraftMatches($existing, $command);

                return $existing;
            }

            $document = Document::query()->create([
                'tenant_id' => $command->tenantId,
                'company_id' => $command->companyId,
                'partner_id' => $command->partnerId,
                'type' => DocumentType::Invoice,
                'fiscal_category' => FiscalCategory::TaxInvoice,
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => null,
                'document_date' => $command->businessDate,
                'due_date' => $command->dueDate,
                'currency' => $command->currencyCode,
                'subtotal' => $command->subtotal,
                'discount_amount' => $command->transactionDiscountAmount,
                'tax_amount' => $command->vatTotal,
                'total' => $command->total,
                'balance_due' => $command->total,
                'source_document_id' => null,
                'reference' => $reference,
                'payload' => [
                    'fiscal_event_id' => $command->fiscalEventId,
                    'account_charge_uuid' => $command->accountChargeUuid,
                    'canonical_payload' => $command->payloadSnapshot,
                ],
            ]);

            foreach ($command->lineItems as $index => $lineItem) {
                $lineSubtotal = $this->money($lineItem, 'line_subtotal');
                $lineVat = $this->money($lineItem, 'line_vat');

                DocumentLine::query()->create([
                    'document_id' => $document->id,
                    'product_id' => null,
                    'product_code' => $this->optionalString($lineItem, 'sku'),
                    'line_number' => $index + 1,
                    'description' => $this->requiredString($lineItem, 'name'),
                    'quantity' => $this->money($lineItem, 'quantity'),
                    'quantity_delivered' => '0',
                    'quantity_received' => '0',
                    'unit_price' => $this->money($lineItem, 'unit_price'),
                    'discount_percent' => null,
                    'discount_amount' => $this->money($lineItem, 'line_discount_amount'),
                    'tax_rate' => $this->money($lineItem, 'vat_rate'),
                    'line_total' => bcadd($lineSubtotal, $lineVat, $command->currencyScale),
                    'allocated_costs' => '0',
                ]);
            }

            return $document->load('lines');
        });
    }

    private function assertExistingDraftMatches(Document $existing, CreatePOSAccountChargeDraftCommand $command): void
    {
        $mismatches = [];

        if ($existing->partner_id !== $command->partnerId) {
            $mismatches[] = 'partner_id';
        }

        if ($existing->type !== DocumentType::Invoice) {
            $mismatches[] = 'type';
        }

        if ($existing->status !== DocumentStatus::Draft) {
            $mismatches[] = 'status';
        }

        if ($existing->fiscal_status !== FiscalStatus::Draft) {
            $mismatches[] = 'fiscal_status';
        }

        if ($existing->fiscal_category !== FiscalCategory::TaxInvoice) {
            $mismatches[] = 'fiscal_category';
        }

        if ($existing->getAttribute('document_number') !== null) {
            $mismatches[] = 'document_number';
        }

        if ($existing->source_document_id !== null) {
            $mismatches[] = 'source_document_id';
        }

        if ($existing->document_date->toDateString() !== $command->businessDate) {
            $mismatches[] = 'document_date';
        }

        if ($existing->due_date?->toDateString() !== $command->dueDate) {
            $mismatches[] = 'due_date';
        }

        if ($existing->currency !== $command->currencyCode) {
            $mismatches[] = 'currency';
        }

        $payload = $existing->payload;
        if (! is_array($payload)) {
            $mismatches[] = 'payload';
        } else {
            if (($payload['fiscal_event_id'] ?? null) !== $command->fiscalEventId) {
                $mismatches[] = 'payload.fiscal_event_id';
            }

            if (($payload['account_charge_uuid'] ?? null) !== $command->accountChargeUuid) {
                $mismatches[] = 'payload.account_charge_uuid';
            }

            if (($payload['canonical_payload'] ?? null) !== $command->payloadSnapshot) {
                $mismatches[] = 'payload.canonical_payload';
            }
        }

        foreach ([
            'subtotal' => $command->subtotal,
            'discount_amount' => $command->transactionDiscountAmount,
            'tax_amount' => $command->vatTotal,
            'total' => $command->total,
            'balance_due' => $command->total,
        ] as $field => $expected) {
            if (bccomp($this->existingMoney($existing, $field), $expected, $command->currencyScale) !== 0) {
                $mismatches[] = $field;
            }
        }

        if ($existing->lines->count() !== count($command->lineItems)) {
            $mismatches[] = 'line_count';
        }

        $existingLines = $existing->lines->sortBy('line_number')->values();
        foreach ($command->lineItems as $index => $lineItem) {
            $line = $existingLines->get($index);
            if (! $line instanceof DocumentLine) {
                $mismatches[] = 'line_'.($index + 1).'_missing';

                continue;
            }

            foreach ($this->lineMismatches($line, $lineItem, $index + 1, $command->currencyScale) as $lineMismatch) {
                $mismatches[] = $lineMismatch;
            }
        }

        if ($mismatches !== []) {
            throw new RuntimeException('pos_account_charge_draft_conflict:'.implode(',', $mismatches));
        }
    }

    /**
     * @param  array<string, mixed>  $lineItem
     * @return list<string>
     */
    private function lineMismatches(DocumentLine $line, array $lineItem, int $lineNumber, int $currencyScale): array
    {
        $mismatches = [];
        $prefix = 'line_'.$lineNumber.'_';
        $lineSubtotal = $this->money($lineItem, 'line_subtotal');
        $lineVat = $this->money($lineItem, 'line_vat');

        foreach ([
            'line_number' => $lineNumber,
            'product_code' => $this->optionalString($lineItem, 'sku'),
            'description' => $this->requiredString($lineItem, 'name'),
        ] as $field => $expected) {
            if ($line->{$field} !== $expected) {
                $mismatches[] = $prefix.$field;
            }
        }

        foreach ([
            'quantity' => $this->money($lineItem, 'quantity'),
            'unit_price' => $this->money($lineItem, 'unit_price'),
            'discount_amount' => $this->money($lineItem, 'line_discount_amount'),
            'tax_rate' => $this->money($lineItem, 'vat_rate'),
            'line_total' => bcadd($lineSubtotal, $lineVat, $currencyScale),
        ] as $field => $expected) {
            if (bccomp($this->existingLineMoney($line, $field), $expected, $currencyScale) !== 0) {
                $mismatches[] = $prefix.$field;
            }
        }

        return $mismatches;
    }

    /**
     * @param  array<string, mixed>  $lineItem
     */
    private function requiredString(array $lineItem, string $key): string
    {
        $value = $lineItem[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('pos_account_charge_draft_line_invalid:'.$key);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $lineItem
     */
    private function optionalString(array $lineItem, string $key): ?string
    {
        $value = $lineItem[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $lineItem
     * @return numeric-string
     */
    private function money(array $lineItem, string $key): string
    {
        $value = $lineItem[$key] ?? null;
        if (! is_string($value) || ! is_numeric($value)) {
            throw new RuntimeException('pos_account_charge_draft_money_invalid:'.$key);
        }

        return $value;
    }

    /**
     * @return numeric-string
     */
    private function existingMoney(Document $document, string $field): string
    {
        $value = $document->{$field};
        if (! is_string($value) || ! is_numeric($value)) {
            throw new RuntimeException('pos_account_charge_draft_existing_money_invalid:'.$field);
        }

        return $value;
    }

    /**
     * @return numeric-string
     */
    private function existingLineMoney(DocumentLine $line, string $field): string
    {
        $value = $line->{$field};
        if (! is_string($value) || ! is_numeric($value)) {
            throw new RuntimeException('pos_account_charge_draft_existing_line_money_invalid:'.$field);
        }

        return $value;
    }
}
