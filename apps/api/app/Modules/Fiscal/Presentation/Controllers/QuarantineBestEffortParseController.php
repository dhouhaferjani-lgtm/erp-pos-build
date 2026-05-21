<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Application\Services\BestEffortPayloadParser;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use App\Modules\Fiscal\Presentation\Resources\BestEffortParseResource;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QuarantineBestEffortParseController extends Controller
{
    public function __construct(
        private readonly BestEffortPayloadParser $parser,
    ) {}

    public function store(Request $request, string $id): BestEffortParseResource|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authenticated user could not be resolved.',
                ],
            ], 401);
        }

        $quarantine = FiscalEventQuarantine::query()
            ->where('id', $id)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if ($quarantine instanceof FiscalEventQuarantine) {
            $eventType = FiscalEventType::from($quarantine->event_type);
            $result = $this->parser->parse($this->canonicalBytesFromQuarantine($quarantine), $eventType);

            return new BestEffortParseResource([
                'id' => $quarantine->id,
                'source' => 'fiscal_event_quarantine',
                'fiscal_event_id' => null,
                'event_type' => $eventType->value,
                'parsed' => $result->parsed,
                'defects' => $result->defectsToArray(),
            ]);
        }

        $event = FiscalEvent::query()
            ->where('id', $id)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (! $event instanceof FiscalEvent) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'No quarantined fiscal event was found for this tenant.',
                ],
            ], 404);
        }

        if (
            $event->integrity_exception_class !== 'canonical_parse_failure'
            || $event->payload_parse_status->value !== 'failed'
        ) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_PARSE_FAILURE',
                    'message' => 'Best-effort parsing is only available for unresolved canonical_parse_failure rows.',
                ],
            ], 409);
        }

        $result = $this->parser->parse($this->canonicalBytesToString($event->canonical_bytes), $event->event_type);

        return new BestEffortParseResource([
            'id' => $event->id,
            'source' => 'fiscal_events',
            'fiscal_event_id' => $event->id,
            'event_type' => $event->event_type->value,
            'parsed' => $result->parsed,
            'defects' => $result->defectsToArray(),
        ]);
    }

    private function canonicalBytesToString(mixed $bytes): string
    {
        if (is_resource($bytes)) {
            $contents = stream_get_contents($bytes);

            return $contents === false ? '' : $contents;
        }

        return is_string($bytes) ? $bytes : '';
    }

    private function canonicalBytesFromQuarantine(FiscalEventQuarantine $quarantine): string
    {
        $rawEnvelope = $quarantine->raw_envelope;
        $payload = is_array($rawEnvelope['payload'] ?? null) ? $rawEnvelope['payload'] : null;
        $rawCanonicalBytes = $payload['canonical_bytes'] ?? $rawEnvelope['canonical_bytes'] ?? null;

        if (is_string($rawCanonicalBytes)) {
            return $rawCanonicalBytes;
        }

        return $this->canonicalBytesToString($quarantine->canonical_bytes);
    }
}
