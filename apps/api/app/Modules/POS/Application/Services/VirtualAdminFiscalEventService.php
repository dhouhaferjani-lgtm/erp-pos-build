<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

final class VirtualAdminFiscalEventService
{
    public function __construct(
        private readonly VirtualAdminTerminalResolver $terminalResolver,
        private readonly ConnectionInterface $db,
        private readonly FiscalIntegrityProvider $integrity,
        private readonly FiscalPayloadConstraintValidator $payloadValidator,
    ) {}

    public function appendAccountStatusChanged(
        Partner $partner,
        CustomerAccountStatus $oldStatus,
        CustomerAccountStatus $newStatus,
        string $actorUserId,
        string $reason,
    ): FiscalEvent {
        $terminal = $this->terminalResolver->resolve($partner->tenant_id, $partner->company_id);

        return $this->db->transaction(function () use (
            $partner,
            $oldStatus,
            $newStatus,
            $actorUserId,
            $reason,
            $terminal,
        ): FiscalEvent {
            /** @var stdClass|null $lockedTerminal */
            $lockedTerminal = $this->db->table('pos_terminals')
                ->where('id', $terminal->id)
                ->where('tenant_id', $partner->tenant_id)
                ->where('company_id', $partner->company_id)
                ->where('type', TerminalType::VirtualAdmin->value)
                ->lockForUpdate()
                ->first(['id', 'genesis_seed']);

            if ($lockedTerminal === null) {
                throw new RuntimeException('Virtual admin terminal vanished before fiscal event append.');
            }

            $now = Carbon::now('UTC')->setMicrosecond(0);
            $payload = [
                'actor_user_id' => $actorUserId,
                'company_id' => $partner->company_id,
                'event_time_device' => $now->format('Y-m-d\TH:i:s.v\Z'),
                'new_status' => $newStatus->value,
                'old_status' => $oldStatus->value,
                'partner_id' => $partner->id,
                'partner_snapshot' => [
                    'id' => $partner->id,
                    'name' => $partner->name,
                    'type' => $partner->type->value,
                ],
                'reason' => $reason,
                'status_version' => $partner->account_status_version,
                'tenant_id' => $partner->tenant_id,
                'terminal_id' => $terminal->id,
                'training_flag' => false,
            ];

            $this->validatePayload(FiscalEventType::ACCOUNT_STATUS_CHANGED, $payload);

            [$sequenceNumber, $previousHash] = $this->resolveChainPlacement(
                tenantId: $partner->tenant_id,
                terminalId: $terminal->id,
                genesisSeed: (string) $lockedTerminal->genesis_seed,
            );

            $envelope = [
                'business_date' => $now->copy()->startOfDay()->toDateString(),
                'company_id' => $partner->company_id,
                'event_time_device' => $now->format('Y-m-d\TH:i:s\Z'),
                'event_type' => FiscalEventType::ACCOUNT_STATUS_CHANGED->value,
                'event_version' => 1,
                'operator_id' => $actorUserId,
                'payload' => $payload,
                'previous_hash' => $previousHash,
                'reference_document_id' => null,
                'reference_event_id' => null,
                'sequence_number' => $sequenceNumber,
                'signature_version' => $this->integrity->version(),
                'tenant_id' => $partner->tenant_id,
                'terminal_id' => $terminal->id,
            ];
            $canonicalBytes = $this->canonicalEncode($envelope);
            $currentHash = $this->integrity->computeHash($canonicalBytes);

            /** @var FiscalEvent $event */
            $event = FiscalEvent::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $partner->tenant_id,
                'company_id' => $partner->company_id,
                'terminal_id' => $terminal->id,
                'operator_id' => $actorUserId,
                'event_type' => FiscalEventType::ACCOUNT_STATUS_CHANGED,
                'event_version' => 1,
                'signature_version' => $this->integrity->version(),
                'sequence_number' => $sequenceNumber,
                'event_time_device' => $now,
                'business_date' => $now->copy()->startOfDay(),
                'last_server_time_seen' => null,
                'server_received_at' => $now,
                'reference_event_id' => null,
                'reference_document_id' => null,
                'source_event_class' => null,
                'source_event_id' => null,
                'partner_id' => $partner->id,
                'partner_identity_snapshot' => $payload['partner_snapshot'],
                'canonical_bytes' => $canonicalBytes,
                'previous_hash' => $previousHash,
                'current_hash' => $currentHash,
                'signature_status' => SignatureStatus::NotRequired,
                'integrity_status' => IntegrityStatus::Verified,
                'integrity_exception_class' => null,
                'integrity_exception_reason' => null,
                'payload' => $payload,
                'payload_parse_status' => PayloadParseStatus::Parsed,
            ]);

            return $event->refresh();
        });
    }

    /**
     * Author a server-side `DEPOSIT_RECEIPT` — the back-office / mobile-web
     * counterpart of the device-authored `ACCOUNT_PAYMENT`. A store owner records
     * a payment toward a customer's account from the admin dashboard (no physical
     * terminal), so the event is authored through the virtual-admin terminal +
     * hash chain, exactly like `appendAccountStatusChanged()`.
     *
     * The service owns the fiscal coordinates (terminal, sequence, hash, clock,
     * business date) and the server-generated `deposit_receipt_uuid`; the caller
     * (Phase 5 `RecordCustomerDepositService`) supplies the customer, the money,
     * and the actor. Money is carried as a numeric-string at the currency's scale
     * (`CurrencyScale`) — never float / `number_format`.
     *
     * The downstream allocation behavior (settle FIFO, overflow → credit) is NOT
     * decided here: `treasury_allocation_policy` is fixed to `"FIFO"` and the
     * `TreasuryDepositBridge` projector (Phase 4) delegates to the same
     * `PaymentAllocationService` the desktop path uses — identical money math by
     * construction.
     *
     * @param  string  $amount  numeric-string (formatted here at the currency scale)
     */
    public function appendDepositReceipt(
        Partner $partner,
        string $actorUserId,
        string $actorName,
        string $currencyCode,
        string $amount,
        string $methodCode,
        ?string $repositoryId,
        ?string $notes,
    ): FiscalEvent {
        $terminal = $this->terminalResolver->resolve($partner->tenant_id, $partner->company_id);

        return $this->db->transaction(function () use (
            $partner,
            $actorUserId,
            $actorName,
            $currencyCode,
            $amount,
            $methodCode,
            $repositoryId,
            $notes,
            $terminal,
        ): FiscalEvent {
            /** @var stdClass|null $lockedTerminal */
            $lockedTerminal = $this->db->table('pos_terminals')
                ->where('id', $terminal->id)
                ->where('tenant_id', $partner->tenant_id)
                ->where('company_id', $partner->company_id)
                ->where('type', TerminalType::VirtualAdmin->value)
                ->lockForUpdate()
                ->first(['id', 'genesis_seed']);

            if ($lockedTerminal === null) {
                throw new RuntimeException('Virtual admin terminal vanished before fiscal event append.');
            }

            $now = Carbon::now('UTC')->setMicrosecond(0);
            $scale = CurrencyScale::for($currencyCode);
            $amountFormatted = $this->normalizeDepositAmount($amount, $scale);
            $depositReceiptUuid = (string) Str::uuid();

            $customer = [
                'customer_category' => $partner->customer_category?->value,
                'customer_id' => $partner->id,
                'email' => $partner->email,
                'name' => $partner->name,
                'phone' => $partner->phone,
            ];

            $payment = [
                'amount' => $amountFormatted,
                'method_code' => $methodCode,
                'repository_id' => $repositoryId,
            ];

            $payload = [
                'actor_name' => $actorName,
                'actor_user_id' => $actorUserId,
                'business_date' => $now->copy()->startOfDay()->toDateString(),
                'company_id' => $partner->company_id,
                'currency_code' => $currencyCode,
                'currency_scale' => $scale,
                'customer' => $customer,
                'deposit_receipt_uuid' => $depositReceiptUuid,
                'event_time_device' => $now->format('Y-m-d\TH:i:s.v\Z'),
                'notes' => $notes,
                'partner_id' => $partner->id,
                'payment' => $payment,
                'tenant_id' => $partner->tenant_id,
                'terminal_id' => $terminal->id,
                'training_flag' => false,
                'treasury_allocation_policy' => 'FIFO',
            ];

            $this->validatePayload(FiscalEventType::DEPOSIT_RECEIPT, $payload);

            [$sequenceNumber, $previousHash] = $this->resolveChainPlacement(
                tenantId: $partner->tenant_id,
                terminalId: $terminal->id,
                genesisSeed: (string) $lockedTerminal->genesis_seed,
            );

            $envelope = [
                'business_date' => $now->copy()->startOfDay()->toDateString(),
                'company_id' => $partner->company_id,
                'event_time_device' => $now->format('Y-m-d\TH:i:s\Z'),
                'event_type' => FiscalEventType::DEPOSIT_RECEIPT->value,
                'event_version' => 1,
                'operator_id' => $actorUserId,
                'payload' => $payload,
                'previous_hash' => $previousHash,
                'reference_document_id' => null,
                'reference_event_id' => null,
                'sequence_number' => $sequenceNumber,
                'signature_version' => $this->integrity->version(),
                'tenant_id' => $partner->tenant_id,
                'terminal_id' => $terminal->id,
            ];
            $canonicalBytes = $this->canonicalEncode($envelope);
            $currentHash = $this->integrity->computeHash($canonicalBytes);

            /** @var FiscalEvent $event */
            $event = FiscalEvent::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $partner->tenant_id,
                'company_id' => $partner->company_id,
                'terminal_id' => $terminal->id,
                'operator_id' => $actorUserId,
                'event_type' => FiscalEventType::DEPOSIT_RECEIPT,
                'event_version' => 1,
                'signature_version' => $this->integrity->version(),
                'sequence_number' => $sequenceNumber,
                'event_time_device' => $now,
                'business_date' => $now->copy()->startOfDay(),
                'last_server_time_seen' => null,
                'server_received_at' => $now,
                'reference_event_id' => null,
                'reference_document_id' => null,
                'source_event_class' => null,
                'source_event_id' => null,
                'partner_id' => $partner->id,
                'partner_identity_snapshot' => $customer,
                'canonical_bytes' => $canonicalBytes,
                'previous_hash' => $previousHash,
                'current_hash' => $currentHash,
                'signature_status' => SignatureStatus::NotRequired,
                'integrity_status' => IntegrityStatus::Verified,
                'integrity_exception_class' => null,
                'integrity_exception_reason' => null,
                'payload' => $payload,
                'payload_parse_status' => PayloadParseStatus::Parsed,
            ]);

            return $event->refresh();
        });
    }

    /**
     * Normalize a caller-supplied deposit amount to the currency's canonical
     * fixed-scale numeric-string, FAILING LOUD on any value that cannot be
     * represented exactly at that scale.
     *
     * `CurrencyScale::bcformat()` truncates excess precision silently (e.g.
     * `bcformat('10.009', 2) === '10.00'`). For a fiscal receipt that would seal
     * LESS money than the caller supplied into the immutable hash chain, so we
     * reject over-precise input rather than truncate it. Negative values are
     * rejected here (the deposit money regex also forbids them) and zero is
     * rejected downstream by the payload constraint validator
     * (`payment.amount` must be > 0 unless training).
     */
    private function normalizeDepositAmount(string $amount, int $scale): string
    {
        $raw = trim($amount);
        // The regex restricts to a plain non-negative decimal (no sign, no
        // scientific notation, no leading zeros).
        if (preg_match('/^(0|[1-9]\d*)(\.\d+)?$/D', $raw) !== 1) {
            throw new RuntimeException(
                'deposit_receipt_amount_invalid:amount must be a non-negative decimal string; got '.var_export($amount, true)
            );
        }

        // Reject ANY non-zero precision beyond the currency scale by inspecting
        // the fractional digits directly. A windowed bccomp() comparison would
        // miss excess precision past the window (e.g. EUR '10.000000001'), which
        // would then be silently truncated into the immutable hash chain.
        // Trailing zeros past the scale are harmless padding and accepted.
        $dotPos = strpos($raw, '.');
        if ($dotPos !== false) {
            $fraction = substr($raw, $dotPos + 1);
            if (strlen($fraction) > $scale && rtrim(substr($fraction, $scale), '0') !== '') {
                throw new RuntimeException(
                    'deposit_receipt_amount_precision:amount '.$raw.' exceeds currency scale '.$scale
                );
            }
        }

        return CurrencyScale::bcformat($raw, $scale);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePayload(FiscalEventType $type, array $payload): void
    {
        $keySetError = $this->payloadValidator->validatePayloadKeySet($type, $payload);
        if ($keySetError !== null) {
            throw new RuntimeException(sprintf('%s payload failed key-set validation: %s', $type->value, $keySetError));
        }

        try {
            $this->payloadValidator->validatePerEventConstraints($type, $payload);
        } catch (Throwable $previous) {
            throw new RuntimeException(sprintf('%s payload failed constraint validation: %s', $type->value, $previous->getMessage()), 0, $previous);
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function resolveChainPlacement(string $tenantId, string $terminalId, string $genesisSeed): array
    {
        /** @var stdClass|null $prior */
        $prior = $this->db->table('fiscal_events')
            ->where('tenant_id', $tenantId)
            ->where('terminal_id', $terminalId)
            ->orderByDesc('sequence_number')
            ->first(['sequence_number', 'current_hash']);

        if ($prior === null) {
            return [1, $genesisSeed];
        }

        return [
            ((int) $prior->sequence_number) + 1,
            (string) $prior->current_hash,
        ];
    }

    /**
     * @throws JsonException
     */
    private function canonicalEncode(mixed $value): string
    {
        $normalized = $this->sortObjectKeys($value);

        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sortObjectKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortObjectKeys($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->sortObjectKeys($item), $value);
    }
}
