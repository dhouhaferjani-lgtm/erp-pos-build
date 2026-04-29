<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services\Fiscal\V3;

use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\Fiscal\V3\CanonicalPayloadBuilder;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Shared\Domain\CurrencyScale;

/**
 * Computes the v3 canonical receipt hash by mapping a Receipt Eloquent model
 * onto the CanonicalPayloadBuilder's input shape and calling SHA-256.
 *
 * Phase 1 constraints (Tasks 1-9):
 *   - voucher_ledger_entries is always [] (voucher module not yet merged)
 *   - exchange_group_id is always null (Task 35+)
 *   - audit is always null (refund audit fields come in Task 27+)
 *
 * These will be populated by later tasks as those features land.
 */
final class V3ReceiptHashComputer
{
    public function __construct(
        private readonly CanonicalPayloadBuilder $builder,
    ) {}

    /**
     * Compute the v3 SHA-256 hash for the given receipt.
     *
     * Eager-loads `payments`, `vatDetails`, and `voucherLedgerEntries` if not
     * already loaded so callers do not need to worry about relation state.
     */
    public function compute(Receipt $receipt): string
    {
        if (! $receipt->relationLoaded('payments')) {
            $receipt->load('payments');
        }

        if (! $receipt->relationLoaded('vatDetails')) {
            $receipt->load('vatDetails');
        }

        if (! $receipt->relationLoaded('voucherLedgerEntries')) {
            $receipt->load('voucherLedgerEntries');
        }

        $canonical = $this->builder->build($this->buildInput($receipt));

        return hash('sha256', $canonical);
    }

    /**
     * Map a Receipt to the canonical input array expected by CanonicalPayloadBuilder.
     *
     * Phase E additions (spec §5.0 / §5.1):
     *   - voucher_ledger_entries: populated from VoucherLedger rows where receipt_id = receipt.id,
     *     sorted by voucher_code (via voucher relation). Empty array when none.
     *   - audit: populated from the receipt's new return-audit columns.  NULL when no audit
     *     fields are set (normal sale receipts).
     *
     * Fixture-01 round-trip: cash-only sale with no vouchers → empty voucher_ledger_entries,
     * null audit → hash unchanged from Phase A/B/C/D.
     *
     * @return array{
     *   receipt_number: string,
     *   posted_at: string,
     *   previous_hash: ?string,
     *   total: numeric-string,
     *   currency: string,
     *   vat_breakdown: list<array{rate: string, amount: numeric-string}>,
     *   payments: list<array{method_code: string, payment_type: string, amount: numeric-string, instrument_type: null, instrument_serial: null}>,
     *   voucher_ledger_entries: list<array{voucher_id: string, voucher_code: string, event: string, amount: numeric-string, gl_journal_entry_id: string|null}>,
     *   exchange_group_id: null,
     *   audit: array{authorized_by_user_id: string|null, override_reason: string|null, out_of_window: bool|null, policy_trigger: string|null, refund_request_id: string|null}|null
     * }
     */
    private function buildInput(Receipt $receipt): array
    {
        // posted_at: ISO 8601 UTC with trailing Z as required by the canonical spec
        $postedAt = $receipt->posted_at
            ->utc()
            ->format('Y-m-d\TH:i:s\Z');

        // Resolve the currency scale (EUR=2, TND=3, etc.) for decimal formatting
        $currencyScale = CurrencyScale::for($receipt->currency);

        // total: format at currency scale to produce "12.50" for EUR (not "12.500")
        $total = CurrencyScale::bcformat((string) $receipt->total, $currencyScale);

        // vat_breakdown: rate = tax_rate / 100 (percentage → decimal), amount = vat_amount
        // vat amounts formatted at currency scale to match fixture precision
        /** @var list<array{rate: string, amount: numeric-string}> $vatBreakdown */
        $vatBreakdown = array_values($receipt->vatDetails
            ->map(function ($detail) use ($currencyScale): array {
                /** @var numeric-string $taxRate */
                $taxRate = (string) $detail->tax_rate;
                // Convert percentage (e.g. "20.00") to decimal rate (e.g. "0.20")
                $rate = bcdiv($taxRate, '100', 4);
                // Trim trailing zeros after decimal to match fixture format
                // e.g. "0.2000" → "0.20"
                $rate = $this->normalisedRate($rate);

                return [
                    'rate' => $rate,
                    'amount' => CurrencyScale::bcformat((string) $detail->vat_amount, $currencyScale),
                ];
            })
            ->all());

        // payments: method_code and instrument_* are not yet separate columns on
        // pos_receipt_payments in Phase 1. Mapping convention:
        //   method_code  = strtolower(payment_type)  — the tender identifier (cash, card…)
        //   payment_type = "pos"                      — Phase 1 sentinel: all POS receipts
        //                                               carry a channel type of "pos";
        //                                               a dedicated column lands in Task 3.3+
        // Amounts formatted at currency scale.
        // instrument_type and instrument_serial are null until voucher tasks land.
        /** @var list<array{method_code: string, payment_type: string, amount: numeric-string, instrument_type: null, instrument_serial: null}> $payments */
        $payments = array_values($receipt->payments
            ->map(function ($payment) use ($currencyScale): array {
                return [
                    'method_code' => strtolower((string) $payment->payment_type),
                    'payment_type' => 'pos',
                    'amount' => CurrencyScale::bcformat((string) $payment->amount, $currencyScale),
                    'instrument_type' => null,
                    'instrument_serial' => null,
                ];
            })
            ->all());

        // voucher_ledger_entries: populated from VoucherLedger rows linked to this receipt (spec §5.0).
        // For cash-only receipts this will be an empty array, preserving fixture-01 round-trip.
        $voucherLedgerEntries = $this->buildVoucherLedgerEntries($receipt, $currencyScale);

        // audit: only present on return receipts that recorded an override or out-of-window event.
        $audit = $this->buildAuditBlock($receipt);

        return [
            'receipt_number' => $receipt->receipt_number,
            'posted_at' => $postedAt,
            'previous_hash' => $receipt->previous_hash,
            'total' => $total,
            'currency' => $receipt->currency,
            'vat_breakdown' => $vatBreakdown,
            'payments' => $payments,
            'voucher_ledger_entries' => $voucherLedgerEntries,
            'exchange_group_id' => null,
            'audit' => $audit,
        ];
    }

    /**
     * Build the voucher_ledger_entries array for the canonical hash payload.
     *
     * Entries are sorted by voucher_code to ensure byte-stable ordering across
     * concurrent inserts.  If no VoucherLedger rows are linked, returns [].
     *
     * Fields match the CanonicalPayloadBuilder expected shape (spec §5.0):
     *   voucher_id, voucher_code, event, amount, gl_journal_entry_id.
     *
     * @return list<array{voucher_id: string, voucher_code: string, event: string, amount: numeric-string, gl_journal_entry_id: string|null}>
     */
    private function buildVoucherLedgerEntries(Receipt $receipt, int $currencyScale): array
    {
        if (! $receipt->relationLoaded('voucherLedgerEntries')) {
            return [];
        }

        $entries = $receipt->voucherLedgerEntries;

        if ($entries->isEmpty()) {
            return [];
        }

        // Eagerly load the voucher relation so we can read the code
        $entries->load('voucher');

        return array_values(
            $entries
                ->sortBy(fn (VoucherLedger $row): string => (string) ($row->voucher->code ?? ''))
                ->map(function (VoucherLedger $row) use ($currencyScale): array {
                    return [
                        'voucher_id' => $row->voucher_id,
                        'voucher_code' => (string) ($row->voucher->code ?? ''),
                        'event' => $row->event->value,
                        'amount' => CurrencyScale::bcformat((string) $row->amount, $currencyScale),
                        'gl_journal_entry_id' => $row->gl_journal_entry_id,
                    ];
                })
                ->all()
        );
    }

    /**
     * Build the audit sub-object for the canonical hash payload.
     *
     * Returns null when none of the audit fields are set (regular sale receipts,
     * in-window returns with no override).  This preserves the fixture-01
     * round-trip test: sale receipt → audit = null → same hash as before Phase E.
     *
     * @return array{authorized_by_user_id: string|null, override_reason: string|null, out_of_window: bool|null, policy_trigger: string|null, refund_request_id: string|null}|null
     */
    private function buildAuditBlock(Receipt $receipt): ?array
    {
        if (
            $receipt->authorized_by_user_id === null
            && $receipt->override_reason === null
            && $receipt->out_of_window === null
            && $receipt->policy_trigger === null
            && $receipt->refund_request_id === null
        ) {
            return null;
        }

        return [
            'authorized_by_user_id' => $receipt->authorized_by_user_id,
            'override_reason' => $receipt->override_reason,
            'out_of_window' => $receipt->out_of_window,
            'policy_trigger' => $receipt->policy_trigger,
            'refund_request_id' => $receipt->refund_request_id,
        ];
    }

    /**
     * Normalise a bcmath decimal rate string to the minimal representation.
     *
     * bcdiv('20.00', '100', 4) → "0.2000"; we want "0.20" to match the fixture
     * convention (2 significant decimal places for rates like 0.20, 0.05).
     *
     * Strategy: trim trailing zeros, but keep at least 2 decimal places so
     * "0.2" becomes "0.20" (matching "0.20" in fixture 01).
     */
    private function normalisedRate(string $rate): string
    {
        // Split on decimal point
        if (! str_contains($rate, '.')) {
            return $rate.'.00';
        }

        [$integer, $fractional] = explode('.', $rate, 2);

        // Trim trailing zeros but keep at least 2 decimal places
        $fractional = rtrim($fractional, '0');
        if (strlen($fractional) < 2) {
            $fractional = str_pad($fractional, 2, '0');
        }

        return $integer.'.'.$fractional;
    }
}
