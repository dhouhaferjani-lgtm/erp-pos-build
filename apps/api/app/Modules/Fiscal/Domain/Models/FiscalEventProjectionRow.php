<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-(event, projector) projection state row (spec §7.5).
 *
 * Unlike `FiscalEvent`, this table is MUTABLE — it is not chain truth. The
 * OutboxIngestor (Task 19) seeds a row per active projector when an event is
 * ingested; ApplyFiscalEventProjectionJob (Task 23) advances `attempts` /
 * `last_attempted_at` / `last_error` and flips `projection_status` to
 * `applied` or `dead_lettered`. The composite UNIQUE (fiscal_event_id,
 * projector_name) is the idempotency contract.
 *
 * The model is named `FiscalEventProjectionRow` (not `FiscalEventProjection`)
 * to reserve `FiscalEventProjection` for the projector contract introduced in
 * Task 18 — the "row" suffix flags this is the persistence model, not the
 * behavior.
 *
 * @property string $id
 * @property string $fiscal_event_id
 * @property string $projector_name
 * @property ProjectionStatus $projection_status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $applied_at
 * @property Carbon|null $dead_lettered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class FiscalEventProjectionRow extends Model
{
    /** @var string */
    protected $table = 'fiscal_event_projections';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /**
     * Standard timestamps — this row is mutable per spec §7.5.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * Every field the OutboxIngestor (Task 19) sets on insert plus the worker
     * loop's mutable surface (status / attempts / error / lifecycle timestamps).
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'fiscal_event_id',
        'projector_name',
        'projection_status',
        'attempts',
        'last_error',
        'last_attempted_at',
        'applied_at',
        'dead_lettered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'projection_status' => ProjectionStatus::class,
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'applied_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
