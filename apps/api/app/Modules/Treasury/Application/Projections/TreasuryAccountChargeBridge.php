<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections;

use App\Modules\Accounting\Domain\DTOs\CreatePOSChargeJournalEntryCommand;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountChargeView;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionDependencyMissingException;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionInvariantViolationException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\PosCustomerAlias;
use Illuminate\Support\Facades\DB;

/**
 * Treasury-operational bridge for device-authored ACCOUNT_CHARGE events.
 *
 * POS-core owns the printable receipt in every deployment; this bridge is
 * gated behind Treasury and posts the AR journal entry from sealed canonical
 * bytes when the receivables module is active.
 */
final class TreasuryAccountChargeBridge implements FiscalEventProjector
{
    public function __construct(
        private readonly CanonicalPayloadReader $canonicalReader,
        private readonly GeneralLedgerService $ledgerService,
    ) {}

    public function name(): string
    {
        return 'treasury_account_charge_bridge';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::ACCOUNT_CHARGE;
    }

    public function requiresModule(): string
    {
        return 'Treasury';
    }

    public function priority(): int
    {
        return 150;
    }

    public function apply(FiscalEvent $event): void
    {
        $view = $this->canonicalReader->forAccountCharge($event);

        DB::transaction(function () use ($event, $view): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'SELECT pg_advisory_xact_lock(hashtext(?))',
                    [$event->id.':treasury_account_charge_bridge'],
                );
            }

            $partner = $this->resolveCustomer($event, $view);

            $existing = $this->existingJournalEntryForEvent($event);
            if ($existing instanceof JournalEntry) {
                $this->assertExistingJournalEntryMatches($event, $existing, $view, $partner);

                return;
            }

            $this->ledgerService->createPOSChargeEntry(new CreatePOSChargeJournalEntryCommand(
                tenantId: $event->tenant_id,
                companyId: $event->company_id,
                partnerId: $partner->id,
                fiscalEventId: $event->id,
                accountChargeUuid: $view->payload->accountChargeUuid,
                businessDate: $view->payload->businessDate,
                currencyCode: $view->payload->currencyCode,
                currencyScale: $view->payload->currencyScale,
                subtotal: $this->numericString($event, 'totals.subtotal', $view->totals->subtotal),
                vatTotal: $this->numericString($event, 'totals.vat_total', $view->totals->vatTotal),
                total: $this->numericString($event, 'totals.total', $view->totals->total),
                transactionDiscountAmount: $this->numericString(
                    $event,
                    'transaction_discount_amount',
                    $view->payload->transactionDiscountAmount,
                ),
                vatBreakdown: $view->vatBreakdown,
                lineVatSummary: $view->lineItems,
                actorUserId: $view->payload->cashierId,
            ));
        });
    }

    private function resolveCustomer(FiscalEvent $event, AccountChargeView $view): Partner
    {
        $customerId = $view->customer->customerId;

        if ($view->customer->customerSyncStatus === 'pending_create') {
            $alias = PosCustomerAlias::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('company_id', $event->company_id)
                ->where('client_customer_uuid', $customerId)
                ->first();

            if (! $alias instanceof PosCustomerAlias) {
                $foreignCompanyAlias = PosCustomerAlias::query()
                    ->where('tenant_id', $event->tenant_id)
                    ->where('client_customer_uuid', $customerId)
                    ->first();

                if ($foreignCompanyAlias instanceof PosCustomerAlias) {
                    throw $this->invariant(
                        $event,
                        'customer_alias_cross_company:client_customer_uuid='.
                            $customerId.
                            ':alias_company_id='.
                            $foreignCompanyAlias->company_id,
                    );
                }

                throw new ProjectionDependencyMissingException(
                    projectorName: $this->name(),
                    fiscalEventId: $event->id,
                    missingDependency: 'pos_customer_aliases row for client_customer_uuid='.$customerId,
                );
            }

            $customerId = $alias->server_partner_id;
        } elseif ($view->customer->customerSyncStatus !== 'synced') {
            throw $this->invariant($event, 'customer_sync_status_unsupported:'.$view->customer->customerSyncStatus);
        }

        $partner = Partner::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->whereIn('type', [PartnerType::Customer, PartnerType::Both])
            ->whereKey($customerId)
            ->first();

        if (! $partner instanceof Partner) {
            throw $this->invariant($event, 'customer_not_found:customer_id='.$customerId);
        }

        return $partner;
    }

    private function existingJournalEntryForEvent(FiscalEvent $event): ?JournalEntry
    {
        $entries = JournalEntry::query()
            ->with('lines.account')
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->where('source_type', 'pos_account_charge')
            ->where('source_id', $event->id)
            ->get();

        if ($entries->count() > 1) {
            throw $this->invariant($event, 'idempotency_conflict:multiple_journal_entries_for_event');
        }

        $entry = $entries->first();

        return $entry instanceof JournalEntry ? $entry : null;
    }

    private function assertExistingJournalEntryMatches(
        FiscalEvent $event,
        JournalEntry $existing,
        AccountChargeView $view,
        Partner $partner,
    ): void {
        $mismatches = [];

        $expected = [
            'tenant_id' => $event->tenant_id,
            'company_id' => $event->company_id,
            'entry_date' => $view->payload->businessDate,
            'description' => 'POS Account Charge '.$view->payload->accountChargeUuid,
            'status' => JournalEntryStatus::Draft->value,
        ];

        foreach ($expected as $field => $value) {
            $actual = match ($field) {
                'entry_date' => $existing->entry_date->toDateString(),
                'status' => $existing->status->value,
                default => $existing->{$field},
            };

            if ($actual !== $value) {
                $mismatches[] = $field;
            }
        }

        if ($mismatches !== []) {
            throw $this->invariant($event, 'idempotency_conflict:'.implode(',', $mismatches));
        }

        $this->assertExistingJournalLinesMatch($event, $existing, $view, $partner);
    }

    private function assertExistingJournalLinesMatch(
        FiscalEvent $event,
        JournalEntry $existing,
        AccountChargeView $view,
        Partner $partner,
    ): void {
        $expected = [
            SystemAccountPurpose::CustomerReceivable->value => [
                'debit' => $this->numericString($event, 'totals.total', $view->totals->total),
                'credit' => '0',
                'partner_id' => $partner->id,
            ],
            SystemAccountPurpose::ProductRevenue->value => [
                'debit' => '0',
                'credit' => $this->numericString($event, 'totals.subtotal', $view->totals->subtotal),
                'partner_id' => null,
            ],
        ];

        $vatTotal = $this->numericString($event, 'totals.vat_total', $view->totals->vatTotal);
        if ($this->isPositive($vatTotal, $view->payload->currencyScale)) {
            $expected[SystemAccountPurpose::VatCollected->value] = [
                'debit' => '0',
                'credit' => $vatTotal,
                'partner_id' => null,
            ];
        }

        $discount = $this->numericString(
            $event,
            'transaction_discount_amount',
            $view->payload->transactionDiscountAmount,
        );
        if ($this->isPositive($discount, $view->payload->currencyScale)) {
            $expected[SystemAccountPurpose::SalesDiscount->value] = [
                'debit' => $discount,
                'credit' => '0',
                'partner_id' => null,
            ];
        }

        if ($existing->lines->count() !== count($expected)) {
            throw $this->invariant($event, 'idempotency_conflict:line_count');
        }

        foreach ($expected as $purpose => $lineExpectation) {
            $lines = $existing->lines->filter(
                static fn (JournalLine $line): bool => $line->account->system_purpose?->value === $purpose,
            )->values();

            if ($lines->count() !== 1) {
                throw $this->invariant($event, 'idempotency_conflict:line_purpose:'.$purpose);
            }

            $line = $lines->first();
            if (! $line instanceof JournalLine) {
                throw $this->invariant($event, 'idempotency_conflict:line_purpose:'.$purpose);
            }

            $lineMismatches = [];
            if (bccomp($line->debit, $lineExpectation['debit'], $view->payload->currencyScale) !== 0) {
                $lineMismatches[] = 'debit';
            }

            if (bccomp($line->credit, $lineExpectation['credit'], $view->payload->currencyScale) !== 0) {
                $lineMismatches[] = 'credit';
            }

            if ($line->partner_id !== $lineExpectation['partner_id']) {
                $lineMismatches[] = 'partner_id';
            }

            if ($lineMismatches !== []) {
                throw $this->invariant(
                    $event,
                    'idempotency_conflict:line:'.$purpose.':'.implode(',', $lineMismatches),
                );
            }
        }
    }

    private function invariant(FiscalEvent $event, string $reason): ProjectionInvariantViolationException
    {
        return new ProjectionInvariantViolationException(
            projectorName: $this->name(),
            fiscalEventId: $event->id,
            reason: $reason,
        );
    }

    /**
     * @return numeric-string
     */
    private function numericString(FiscalEvent $event, string $field, string $value): string
    {
        if (! is_numeric($value)) {
            throw $this->invariant($event, 'non_numeric_money_field:'.$field);
        }

        return $value;
    }

    /**
     * @param  numeric-string  $value
     */
    private function isPositive(string $value, int $scale): bool
    {
        return bccomp($value, '0', $scale) === 1;
    }
}
