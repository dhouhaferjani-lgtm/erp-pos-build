<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Billing;

use App\Modules\Document\Application\DTOs\DeliveryNoteBillingState;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteAlreadyClaimedException;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteClaimNotFinalisedException;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteClaimRequiresTransactionException;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use LogicException;

/**
 * Owns the atomic delivery-note billing reservation and projection writes.
 *
 * The caller must already have opened the transaction and locked delivery-note
 * rows in ascending id order before calling {@see claim()}. This service never
 * begins or commits a transaction. It reserves every delivery note before the
 * invoice closure runs, preserving the global order "sorted delivery notes,
 * then invoice numbering" so no invoice or number exists for a losing claim.
 *
 * Reservation and finalisation run in that same caller transaction. Therefore
 * a crash, lost connection, deadlock, or closure exception rolls back payload
 * stamps, marker rows, the invoice, and its number together. Other sessions
 * cannot observe the transient marker with a null invoice id under PostgreSQL
 * MVCC; a competing unique insert blocks until commit or rollback. There is no
 * partial-commit path and no unfinalised-claim reconciliation job: a committed
 * runtime-lane marker with a null invoice id is a defect.
 */
class DeliveryNoteBillingClaimService
{
    public function __construct(protected readonly ConnectionInterface $db) {}

    /** @param Closure(DeliveryNoteClaimSet): string $createInvoice */
    public function claim(
        DeliveryNoteClaimRequest $request,
        Closure $createInvoice,
    ): DeliveryNoteClaimSet {
        if ($this->db->transactionLevel() < 1) {
            throw new DeliveryNoteClaimRequiresTransactionException;
        }

        $set = $this->reserve($request);
        $invoiceId = $createInvoice($set);
        $this->finalise($set, $invoiceId);

        return $set;
    }

    protected function reserve(DeliveryNoteClaimRequest $request): DeliveryNoteClaimSet
    {
        $deliveryNoteIds = $request->deliveryNoteIds;
        sort($deliveryNoteIds, SORT_STRING);
        $invoicedAt = now()->toIso8601String();
        $billingState = new DeliveryNoteBillingState($invoicedAt, null, $request->invoicedVia);
        $payloadPatch = $billingState->toPayloadPatch();
        unset($payloadPatch['invoice_id']);

        foreach ($deliveryNoteIds as $deliveryNoteId) {
            $affected = $this->db->update(
                'UPDATE documents SET payload = '.$this->reservePayloadMergeSql()
                .' WHERE id = ? AND type = ? AND company_id = ?'
                .' AND '.$this->payloadValueSql('invoiced_at').' IS NULL',
                [
                    $payloadPatch['invoiced_at'],
                    $payloadPatch['invoiced_via'],
                    $deliveryNoteId,
                    DocumentType::DeliveryNote->value,
                    $request->companyId,
                ],
            );

            if ($affected !== 1) {
                throw new DeliveryNoteAlreadyClaimedException($deliveryNoteId);
            }

            try {
                $this->db->table('delivery_note_billing_marks')->insert([
                    'delivery_note_id' => $deliveryNoteId,
                    'invoice_id' => null,
                    'invoiced_via' => $request->invoicedVia->value,
                    'invoiced_at' => $invoicedAt,
                    'company_id' => $request->companyId,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isDeliveryNoteMarkerCollision($exception)) {
                    throw $exception;
                }

                throw new DeliveryNoteAlreadyClaimedException($deliveryNoteId, $exception);
            }
        }

        return DeliveryNoteClaimSet::fromReservation(
            $deliveryNoteIds,
            $request->companyId,
            $request->invoicedVia,
            $invoicedAt,
        );
    }

    protected function finalise(DeliveryNoteClaimSet $set, string $invoiceId): void
    {
        $billingState = new DeliveryNoteBillingState($set->invoicedAt, $invoiceId, $set->invoicedVia);
        $payloadPatch = $billingState->toPayloadPatch();

        $markerCount = $this->db->table('delivery_note_billing_marks')
            ->whereIn('delivery_note_id', $set->deliveryNoteIds)
            ->whereNull('invoice_id')
            ->update(['invoice_id' => $invoiceId]);

        if ($markerCount !== $set->count()) {
            throw DeliveryNoteClaimNotFinalisedException::forMarkerCount($set->count(), $markerCount);
        }

        $placeholders = implode(', ', array_fill(0, $set->count(), '?'));
        $payloadCount = $this->db->update(
            'UPDATE documents SET payload = '.$this->finalisePayloadMergeSql()
            .' WHERE id IN ('.$placeholders.') AND type = ? AND company_id = ?'
            .' AND '.$this->payloadValueSql('invoiced_at').' IS NOT NULL'
            .' AND '.$this->payloadValueSql('invoice_id').' IS NULL',
            [
                $payloadPatch['invoice_id'],
                ...$set->deliveryNoteIds,
                DocumentType::DeliveryNote->value,
                $set->companyId,
            ],
        );

        if ($payloadCount !== $set->count()) {
            throw DeliveryNoteClaimNotFinalisedException::forPayloadCount($set->count(), $payloadCount);
        }
    }

    private function finalisePayloadMergeSql(): string
    {
        return $this->driverName() === 'pgsql'
            ? "COALESCE(payload, '{}'::jsonb) || jsonb_build_object('invoice_id', ?::text)"
            : "json_patch(COALESCE(payload, '{}'), json_object('invoice_id', ?))";
    }

    private function reservePayloadMergeSql(): string
    {
        return $this->driverName() === 'pgsql'
            ? "COALESCE(payload, '{}'::jsonb) || jsonb_build_object('invoiced_at', ?::text, 'invoiced_via', ?::text)"
            : "json_patch(COALESCE(payload, '{}'), json_object('invoiced_at', ?, 'invoiced_via', ?))";
    }

    private function payloadValueSql(string $key): string
    {
        return $this->driverName() === 'pgsql'
            ? "(payload->>'{$key}')"
            : "json_extract(payload, '$.{$key}')";
    }

    private function driverName(): string
    {
        if (! $this->db instanceof Connection) {
            throw new LogicException('Delivery-note billing claims require a Laravel database connection.');
        }

        return $this->db->getDriverName();
    }

    private function isDeliveryNoteMarkerCollision(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'delivery_note_billing_marks_pkey')
            || str_contains($message, 'delivery_note_billing_marks_delivery_note_id_unique')
            || str_contains($message, 'delivery_note_billing_marks.delivery_note_id');
    }
}
