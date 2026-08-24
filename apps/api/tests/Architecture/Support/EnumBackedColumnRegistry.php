<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use SplFileInfo;
use Throwable;

/**
 * MECHANICAL derivation of every column whose values are governed by a
 * `app/**\/Domain/Enums/*` enum — the population the enum↔CHECK parity gate
 * asserts over.
 *
 * NO HAND LIST. The sweep's own correction note records that a hand census of
 * this population was already wrong once (`docs/handoff/AUDIT-state-machine-sweep-local-2026-08-23.md`
 * §#26 puts the population at 90 status columns / 76 uncovered; a mechanical
 * derivation disagrees — see `baselines/enum-check-parity-register.md`).
 * So the population is derived at run time from two sources, in this order:
 *
 *  1. **Model casts.** Every instantiable Eloquent model under `app/` is
 *     constructed and its `getCasts()` inspected; every cast whose target is an
 *     `enum_exists()` class declared under `app/**\/Domain/Enums/` contributes
 *     `(model->getTable(), column, enum)`. This is the authoritative source: a
 *     cast IS the statement "this column holds this enum".
 *
 *  2. **Governed audit columns** (`GOVERNED_AUDIT_COLUMNS`). The transition-audit
 *     tables record a governed aggregate's status but are NOT always enum-cast on
 *     their own model. `instrument_events.from_status` / `.to_status` are declared
 *     `string|null` on `App\Modules\Treasury\Domain\InstrumentEvent`, so source (1)
 *     cannot see them, yet §#26 calls them out by name: "the audit trail can record
 *     a state that the enum cannot hydrate". They are declared here explicitly,
 *     with the enum that governs them, and `EnumCheckParityTest` asserts every
 *     declared entry still resolves (table + column exist, enum exists) so the
 *     supplement cannot rot into a lie.
 *     The two sibling audit tables — `workshop_work_order_status_transitions` and
 *     `scheduling_appointment_status_transitions` — ARE enum-cast and therefore
 *     arrive through source (1); they are deliberately NOT duplicated here.
 *
 * KNOWN DERIVATION LIMITS (disclosed, not silently accepted):
 *  - A status column with NO enum cast anywhere is invisible to this registry.
 *    `bank_reconciliations.status` is the live example: the table carries
 *    `bank_reconciliations_status_check` but has no Eloquent model at all, so the
 *    parity gate has no enum to compare its CHECK against. Such columns are a
 *    DIFFERENT defect class (missing enum, not missing CHECK) and are reported in
 *    the register artifact rather than asserted here.
 *  - JSON/array enum casts (`AsEnumCollection::of(...)`) are excluded: a scalar
 *    CHECK cannot express a set-valued column.
 */
final class EnumBackedColumnRegistry
{
    /**
     * Transition-audit columns governed by an enum their own model does not cast.
     *
     * @var list<array{table: string, column: string, enum: class-string, why: string}>
     */
    public const GOVERNED_AUDIT_COLUMNS = [
        [
            'table' => 'instrument_events',
            'column' => 'from_status',
            'enum' => InstrumentStatus::class,
            'why' => 'InstrumentEvent declares from_status as string|null (app/Modules/Treasury/Domain/InstrumentEvent.php), '
                .'but the column records the payment-instrument status the lifecycle moved OUT of. Audit sweep §#26.',
        ],
        [
            'table' => 'instrument_events',
            'column' => 'to_status',
            'enum' => InstrumentStatus::class,
            'why' => 'InstrumentEvent declares to_status as string|null; the column records the status the lifecycle moved INTO. Audit sweep §#26.',
        ],
    ];

    private const DOMAIN_ENUM_PATH = '#[\\\\/]Domain[\\\\/]Enums[\\\\/]#';

    public function __construct(
        private readonly string $appPath,
        private readonly MigrationTableScopeMap $scopes,
    ) {}

    /**
     * Every governed column, sorted by table then column.
     *
     * @return list<array{table: string, column: string, enum: class-string, cases: list<string>, backing: string, origin: string, model: string|null, scope: string}>
     */
    public function derive(): array
    {
        $entries = [];

        foreach ($this->modelClasses() as $class) {
            try {
                /** @var Model $model */
                $model = new $class;
                $table = $model->getTable();
                $casts = $model->getCasts();
            } catch (Throwable) {
                // A model that cannot be constructed with no arguments cannot
                // declare casts we could trust; skipping is safe and is counted
                // by unconstructableModels() so the skip is never silent.
                continue;
            }

            foreach ($casts as $column => $cast) {
                if (! is_string($cast)) {
                    continue;
                }
                $target = explode(':', $cast)[0];
                if (! enum_exists($target)) {
                    continue;
                }
                $reflection = new ReflectionEnum($target);
                $file = (string) $reflection->getFileName();
                if (preg_match(self::DOMAIN_ENUM_PATH, $file) !== 1) {
                    continue;
                }

                $key = $table.'.'.$column;
                $entries[$key] = [
                    'table' => $table,
                    'column' => $column,
                    'enum' => $target,
                    'cases' => $this->caseValues($target),
                    'backing' => $reflection->isBacked() ? (string) $reflection->getBackingType() : 'pure',
                    'origin' => 'model-cast',
                    'model' => $class,
                    'scope' => $this->scopes->scopeOf($table),
                ];
            }
        }

        foreach (self::GOVERNED_AUDIT_COLUMNS as $declared) {
            $key = $declared['table'].'.'.$declared['column'];
            if (isset($entries[$key])) {
                // Already reachable through a cast — the supplement is redundant
                // and the test asserts that redundancy is removed.
                $entries[$key]['origin'] = 'model-cast+governed-audit';

                continue;
            }
            $reflection = new ReflectionEnum($declared['enum']);
            $entries[$key] = [
                'table' => $declared['table'],
                'column' => $declared['column'],
                'enum' => $declared['enum'],
                'cases' => $this->caseValues($declared['enum']),
                'backing' => $reflection->isBacked() ? (string) $reflection->getBackingType() : 'pure',
                'origin' => 'governed-audit',
                'model' => null,
                'scope' => $this->scopes->scopeOf($declared['table']),
            ];
        }

        ksort($entries, SORT_STRING);

        return array_values($entries);
    }

    /**
     * The tenant-database subset — the only rows the parity gate asserts on.
     *
     * @return list<array{table: string, column: string, enum: class-string, cases: list<string>, backing: string, origin: string, model: string|null, scope: string}>
     */
    public function deriveTenantScoped(): array
    {
        return array_values(array_filter(
            $this->derive(),
            static fn (array $entry): bool => $entry['scope'] === MigrationTableScopeMap::SCOPE_TENANT,
        ));
    }

    /**
     * @return list<class-string>
     */
    public function modelClasses(): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->appPath));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(rtrim($this->appPath, '/')) + 1);
            $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));

            if (! class_exists($class)) {
                continue;
            }
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if (! $reflection->isInstantiable()) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * Models the scan found but could not construct — surfaced so the registry's
     * blind spot is measurable instead of silent.
     *
     * @return list<class-string>
     */
    public function unconstructableModels(): array
    {
        $failed = [];
        foreach ($this->modelClasses() as $class) {
            try {
                new $class;
            } catch (Throwable) {
                $failed[] = $class;
            }
        }

        return $failed;
    }

    /**
     * @param  class-string  $enum
     * @return list<string>
     */
    private function caseValues(string $enum): array
    {
        $reflection = new ReflectionEnum($enum);
        $values = [];
        foreach ($enum::cases() as $case) {
            $values[] = $reflection->isBacked() ? (string) $case->value : $case->name;
        }
        sort($values, SORT_STRING);

        return $values;
    }
}
