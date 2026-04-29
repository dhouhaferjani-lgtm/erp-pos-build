<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services\Fiscal\V3;

/**
 * Builds the v3 canonical-JSON payload for a receipt hash.
 *
 * Top-level keys (lexicographic): audit_hash, currency, exchange_group_id,
 * payment_methods_hash, posted_at, previous_hash, receipt_number,
 * schema_version (3), total, vat_breakdown_hash, voucher_ledger_hash.
 */
final class CanonicalPayloadBuilder
{
    /**
     * @param  array{
     *   receipt_number: string,
     *   posted_at: string,
     *   previous_hash: ?string,
     *   total: string,
     *   currency: string,
     *   vat_breakdown: list<array{rate: string, amount: string}>,
     *   payments: list<array{method_code: string, payment_type: string, amount: string, instrument_type: ?string, instrument_serial: ?string}>,
     *   voucher_ledger_entries: list<array{voucher_id: string, voucher_code: string, event: string, amount: string, gl_journal_entry_id: ?string}>,
     *   exchange_group_id: ?string,
     *   audit: ?array{authorized_by_user_id: ?string, override_reason: ?string, policy_trigger: ?string, out_of_window: ?bool, refund_request_id: ?string}
     * }  $input
     */
    public function build(array $input): string
    {
        $encoder = new CanonicalJsonEncoder;

        $payments = $input['payments'];
        usort($payments, function (array $a, array $b): int {
            return [$a['method_code'], $a['instrument_type'] ?? '', $a['instrument_serial'] ?? '', $a['amount']]
                <=> [$b['method_code'], $b['instrument_type'] ?? '', $b['instrument_serial'] ?? '', $b['amount']];
        });

        $vat = $input['vat_breakdown'];
        usort($vat, fn (array $a, array $b): int => strcmp($a['rate'], $b['rate']));

        $voucher = $input['voucher_ledger_entries'];
        usort($voucher, fn (array $a, array $b): int => strcmp($a['voucher_code'], $b['voucher_code']));

        $auditSubject = $input['audit'] ?? [];

        $payload = [
            'audit_hash' => hash('sha256', $encoder->encode($auditSubject)),
            'currency' => strtoupper($input['currency']),
            'exchange_group_id' => $input['exchange_group_id'],
            'payment_methods_hash' => hash('sha256', $encoder->encodeList($payments)),
            'posted_at' => $input['posted_at'],
            'previous_hash' => $input['previous_hash'],
            'receipt_number' => $input['receipt_number'],
            'schema_version' => 3,
            'total' => $input['total'],
            'vat_breakdown_hash' => hash('sha256', $encoder->encodeList($vat)),
            'voucher_ledger_hash' => hash('sha256', $encoder->encodeList($voucher)),
        ];

        return $encoder->encode($payload);
    }
}
