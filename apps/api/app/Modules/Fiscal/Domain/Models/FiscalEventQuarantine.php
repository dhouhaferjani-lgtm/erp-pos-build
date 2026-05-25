<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Non-admissible-envelope quarantine partition (spec §8).
 *
 * Holds `sequence_conflict` envelopes that physically cannot enter
 * `fiscal_events` because their (tenant, terminal, sequence) slot is already
 * occupied by a different event. Phase 1's only valid
 * `integrity_exception_class` value is `'sequence_conflict'` — the migration
 * pins that at the DB layer.
 *
 * Unlike `FiscalEvent`, this table is MUTABLE — the resolution flow writes
 * `resolved_at` / `resolved_by` when an admin clears the incident. Per the
 * boundary discipline learned from Task 9 review, the lifecycle columns
 * (`resolved_at`, `resolved_by`) and the incident-classification columns
 * (`integrity_exception_class`, `integrity_exception_reason`) are NOT
 * fillable: the OutboxIngestor (Task 19) writes the envelope + the incident
 * classification via targeted assignment / `forceFill()`, and the resolver
 * writes the resolution stamps via explicit lifecycle code, never through
 * mass-assignment from external input.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $terminal_id
 * @property string $operator_id
 * @property string $envelope_event_id
 * @property string $event_type
 * @property int $event_version
 * @property string $signature_version
 * @property int $claimed_sequence_number
 * @property Carbon $event_time_device
 * @property Carbon $business_date
 * @property string $chain_context
 * @property Carbon|null $last_server_time_seen
 * @property string|null $reference_event_id
 * @property string|null $reference_document_id
 * @property string|null $source_event_class
 * @property string|null $source_event_id
 * @property string $previous_hash
 * @property string $current_hash
 * @property string $canonical_bytes
 * @property array<string, mixed> $raw_envelope
 * @property string|null $payload_parse_status
 * @property IntegrityExceptionClass $integrity_exception_class
 * @property string $integrity_exception_reason
 * @property string $conflicting_event_id
 * @property Carbon $server_received_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 * @property Carbon $created_at
 */
final class FiscalEventQuarantine extends Model
{
    /** @var string */
    protected $table = 'fiscal_event_quarantine';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /**
     * The migration writes `created_at` via `useCurrent()`; no `updated_at`
     * column exists (resolution stamps carry their own timestamp via
     * `resolved_at`).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Insert-time identity + envelope mirror fields only.
     *
     * The lifecycle columns (`resolved_at`, `resolved_by`) and the incident
     * classification columns (`integrity_exception_class`,
     * `integrity_exception_reason`) are deliberately omitted: the resolver
     * mutates lifecycle state via explicit code, and the classification is
     * write-once and assigned through `forceFill()` by the OutboxIngestor.
     * This mirrors the boundary discipline applied in Task 9.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'tenant_id',
        'company_id',
        'terminal_id',
        'operator_id',
        'envelope_event_id',
        'event_type',
        'event_version',
        'signature_version',
        'claimed_sequence_number',
        'event_time_device',
        'business_date',
        'chain_context',
        'last_server_time_seen',
        'reference_event_id',
        'reference_document_id',
        'source_event_class',
        'source_event_id',
        'previous_hash',
        'current_hash',
        'canonical_bytes',
        'raw_envelope',
        'payload_parse_status',
        'conflicting_event_id',
        'server_received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'claimed_sequence_number' => 'integer',
            'event_time_device' => 'datetime',
            'business_date' => 'date',
            'last_server_time_seen' => 'datetime',
            'raw_envelope' => 'array',
            'integrity_exception_class' => IntegrityExceptionClass::class,
            'server_received_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
