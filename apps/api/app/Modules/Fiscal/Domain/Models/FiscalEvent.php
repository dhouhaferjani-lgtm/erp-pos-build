<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Server-side mirror of a device-authored fiscal event (spec §3.2 / §7).
 *
 * The table is immutable chain truth — Task 8 layers the BEFORE UPDATE / DELETE /
 * TRUNCATE triggers on top. Projection state lives in `fiscal_event_projections`,
 * not on this row. Writes happen exclusively through the OutboxIngestor (Task 19);
 * the model only exposes typed reads + the small mutable surface the resolver and
 * parser need (payload + parse status + integrity status + resolution columns).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $terminal_id
 * @property string $operator_id
 * @property FiscalEventType $event_type
 * @property int $event_version
 * @property string $signature_version
 * @property int $sequence_number
 * @property Carbon $event_time_device
 * @property Carbon $business_date
 * @property Carbon|null $last_server_time_seen
 * @property Carbon $server_received_at
 * @property string|null $reference_event_id
 * @property string|null $reference_document_id
 * @property string|null $source_event_class
 * @property string|null $source_event_id
 * @property string|null $partner_id
 * @property array<string, mixed>|null $partner_identity_snapshot
 * @property string $canonical_bytes
 * @property string $previous_hash
 * @property string $current_hash
 * @property SignatureStatus $signature_status
 * @property string|null $signature_algorithm
 * @property string|null $signature_value
 * @property int|null $signature_counter
 * @property string|null $signature_provider
 * @property string|null $signing_device_id
 * @property string|null $certificate_id
 * @property string|null $signed_payload_ref
 * @property string|null $time_source_value
 * @property string|null $time_format
 * @property string|null $provider_transaction_id
 * @property IntegrityStatus $integrity_status
 * @property string|null $integrity_exception_class
 * @property string|null $integrity_exception_reason
 * @property Carbon|null $integrity_resolved_at
 * @property string|null $integrity_resolved_by
 * @property array<string, mixed>|null $payload
 * @property PayloadParseStatus $payload_parse_status
 * @property Carbon $created_at
 */
final class FiscalEvent extends Model
{
    /** @var string */
    protected $table = 'fiscal_events';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /**
     * `created_at` is set by the DB default; there is no `updated_at` column —
     * the row is immutable chain truth and the few mutable fields are touched
     * via explicit application-layer updates, not Eloquent timestamping.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Every field the OutboxIngestor (Task 19) sets on insert. The mutable surface
     * (payload / parse status / integrity columns) is included so the StrictCanonicalParser
     * (Task 16) and the quarantine resolver can update through the model as well.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'tenant_id',
        'company_id',
        'terminal_id',
        'operator_id',
        'event_type',
        'event_version',
        'signature_version',
        'sequence_number',
        'event_time_device',
        'business_date',
        'last_server_time_seen',
        'server_received_at',
        'reference_event_id',
        'reference_document_id',
        'source_event_class',
        'source_event_id',
        'partner_id',
        'partner_identity_snapshot',
        'canonical_bytes',
        'previous_hash',
        'current_hash',
        'signature_status',
        'signature_algorithm',
        'signature_value',
        'signature_counter',
        'signature_provider',
        'signing_device_id',
        'certificate_id',
        'signed_payload_ref',
        'time_source_value',
        'time_format',
        'provider_transaction_id',
        'integrity_status',
        'integrity_exception_class',
        'integrity_exception_reason',
        'integrity_resolved_at',
        'integrity_resolved_by',
        'payload',
        'payload_parse_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => FiscalEventType::class,
            'event_version' => 'integer',
            'sequence_number' => 'integer',
            'event_time_device' => 'datetime',
            'business_date' => 'date',
            'last_server_time_seen' => 'datetime',
            'server_received_at' => 'datetime',
            'partner_identity_snapshot' => 'array',
            'signature_status' => SignatureStatus::class,
            'signature_counter' => 'integer',
            'integrity_status' => IntegrityStatus::class,
            'integrity_resolved_at' => 'datetime',
            'payload' => 'array',
            'payload_parse_status' => PayloadParseStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
