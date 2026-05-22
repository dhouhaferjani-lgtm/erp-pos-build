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
        $json = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new JsonException('Unable to encode canonical fiscal envelope.');
        }

        return $json;
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
