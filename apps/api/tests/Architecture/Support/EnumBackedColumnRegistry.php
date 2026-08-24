<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use App\Models\SuperAdmin;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use SplFileInfo;
use Throwable;

/**
 * MECHANICAL derivation of every column whose values are governed by an
 * `app/**\/Domain/Enums/*` or `app/**\/Shared/Enums/*` enum — the population the
 * enum↔CHECK parity gate asserts over.
 *
 * NO HAND LIST. The sweep's own correction note records that a hand census of
 * this population was already wrong once (`docs/handoff/AUDIT-state-machine-sweep-local-2026-08-23.md`
 * §#26 puts the population at 90 status columns / 76 uncovered; a mechanical
 * derivation disagrees — see `baselines/enum-check-parity-register.md`).
 * So the population is derived at run time from two sources, in this order:
 *
 *  1. **Model casts.** Every instantiable Eloquent model under `app/` is
 *     constructed and its `getCasts()` inspected; every cast whose target is an
 *     `enum_exists()` class whose FILE lives under `app/` in a `Domain/Enums/` or
 *     `Shared/Enums/` directory contributes `(model->getTable(), column, enum)`.
 *     This is the authoritative source: a cast IS the statement "this column holds
 *     this enum".
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
 *    Such columns are a DIFFERENT defect class (missing enum, not missing CHECK).
 *    They are declared in `UNGATEABLE_COLUMNS` below — a rot-guarded, first-class
 *    section of the register — rather than described in prose: the live instances
 *    are `bank_reconciliations.status` (no Eloquent model at all),
 *    `fiscal_event_quarantine.payload_parse_status` (model, no cast) and
 *    `super_admins.role` (central auth table, model, no cast).
 *  - **The enum FILE PATH is a population boundary.** Only enums under `app/` in a
 *    `Domain/Enums/` or `Shared/Enums/` directory are recognised. This is a real
 *    filter, not a formality: `products.enrichment_status` (tenant table, 6-case
 *    `App\Shared\Enums\EnrichmentStatus`, no CHECK live) was dropped SILENTLY by
 *    the original `Domain/Enums/`-only recogniser and was therefore missing from
 *    the burn-down denominator. Every cast-target enum still outside the
 *    recognised paths — `App\Enums\Vertical` on `tenants.vertical` is the live
 *    one — is returned by `excludedEnumColumns()` BY COLUMN NAME and rendered in
 *    the register, so no exclusion is silent.
 *  - JSON/array enum casts (`AsEnumCollection::of(...)`) are excluded: a scalar
 *    CHECK cannot express a set-valued column.
 *  - A model whose constructor cannot run with no arguments is skipped (it cannot
 *    declare casts we could trust). The set is empty today; if it ever is not, the
 *    skipped model's enum columns leave the population silently. Measured by
 *    `EnumCheckParityTest` via the population reconciliation rather than by a
 *    separate counter.
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

    /**
     * Columns whose values ARE governed by a state machine but which this gate
     * structurally cannot assert: there is no enum for the CHECK to be compared
     * against. Declared rather than described so `EnumCheckParityTest` can prove
     * each one still holds — a hand list that nothing checks is a hand list that
     * rots.
     *
     * @var list<array{table: string, column: string, kind: string, model: class-string|null, why: string}>
     */
    public const UNGATEABLE_COLUMNS = [
        [
            'table' => 'bank_reconciliations',
            'column' => 'status',
            'kind' => 'no-model',
            'model' => null,
            'why' => 'The table carries bank_reconciliations_status_check but NO Eloquent model maps to it, '
                .'so there is no enum to compare the CHECK against. The §#26 sweep counted it among the 14 '
                .'constrained; this gate cannot see it in either direction.',
        ],
        [
            'table' => 'fiscal_event_quarantine',
            'column' => 'payload_parse_status',
            'kind' => 'no-cast',
            'model' => FiscalEventQuarantine::class,
            'why' => 'OutboxIngestor writes PayloadParseStatus->value into this column, but '
                .'FiscalEventQuarantine::casts() omits it while casting its sibling '
                .'integrity_exception_class. Its OTHER sibling, fiscal_events.payload_parse_status, IS cast. '
                .'Adding the cast is a production change and pulls the column into the population for free.',
        ],
        [
            'table' => 'super_admins',
            'column' => 'role',
            'kind' => 'no-cast',
            'model' => SuperAdmin::class,
            'why' => 'App\\Models\\Enums\\SuperAdminRole exists, but SuperAdmin::casts() does not use it and the '
                .'central super_admins table has ZERO CHECK constraints. This is the highest-privilege '
                .'instance of the no-cast class: a role column on the central auth table.',
        ],
    ];

    /**
     * Enum-file directories the derivation recognises. The FILE path is used, not
     * the namespace, so a misplaced file is caught rather than trusted.
     *
     * @var list<string>
     */
    private const INCLUDED_ENUM_PATHS = [
        '#[\\\\/]Domain[\\\\/]Enums[\\\\/]#',
        '#[\\\\/]Shared[\\\\/]Enums[\\\\/]#',
    ];

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
                // declare casts we could trust. The set is empty on this tree; the
                // skip is a DISCLOSED limit (see KNOWN DERIVATION LIMITS above),
                // not a counted one — the counter that used to be claimed here was
                // never called by anything.
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
                if (! $this->isRecognisedEnumFile((string) $reflection->getFileName())) {
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
     * Every cast-target enum this registry EXCLUDES because its file is outside the
     * recognised enum directories — reported by column so the population boundary
     * is disclosed by name rather than by adjective (T-1).
     *
     * @return list<array{table: string, column: string, enum: class-string, file: string, scope: string}>
     */
    public function excludedEnumColumns(): array
    {
        $excluded = [];

        foreach ($this->modelClasses() as $class) {
            try {
                /** @var Model $model */
                $model = new $class;
                $table = $model->getTable();
                $casts = $model->getCasts();
            } catch (Throwable) {
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
                $file = (string) (new ReflectionEnum($target))->getFileName();
                if ($this->isRecognisedEnumFile($file) || ! $this->isUnderAppPath($file)) {
                    continue;
                }

                $excluded[$table.'.'.$column] = [
                    'table' => $table,
                    'column' => $column,
                    'enum' => $target,
                    'file' => $this->relativeFile($file),
                    'scope' => $this->scopes->scopeOf($table),
                ];
            }
        }

        ksort($excluded, SORT_STRING);

        return array_values($excluded);
    }

    /**
     * Every table an Eloquent model under `app/` maps to — the input the
     * `no-model` un-gateable entries are checked against.
     *
     * @return list<string>
     */
    public function tablesWithModels(): array
    {
        $tables = [];
        foreach ($this->modelClasses() as $class) {
            try {
                /** @var Model $model */
                $model = new $class;
                $tables[$model->getTable()] = true;
            } catch (Throwable) {
                continue;
            }
        }

        $list = array_keys($tables);
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * PURE rot-guard for `UNGATEABLE_COLUMNS`. Every declared entry must still be
     * true: the column exists, it is still OUTSIDE the asserted population, and the
     * stated reason still holds. The moment someone adds the missing cast (or a
     * model for the table), the entry is redundant and must be deleted — at which
     * point the column enters the population and the gate starts asserting it.
     *
     * @param  array<string, list<string>>  $schemaColumns  table => live column names
     * @param  list<string>  $population  "table.column" of every column the registry derived
     * @param  list<string>  $tablesWithModels
     * @return list<string>
     */
    public static function ungateableProblems(array $schemaColumns, array $population, array $tablesWithModels): array
    {
        $problems = [];
        $populationSet = array_fill_keys($population, true);

        foreach (self::UNGATEABLE_COLUMNS as $declared) {
            $key = $declared['table'].'.'.$declared['column'];

            if (! isset($schemaColumns[$declared['table']]) || ! in_array($declared['column'], $schemaColumns[$declared['table']], true)) {
                $problems[] = $key.' — declared UNGATEABLE but the live schema has no such column; remove or repoint the entry.';

                continue;
            }

            if (isset($populationSet[$key])) {
                $problems[] = $key.' — declared UNGATEABLE but it IS in the derived population now (the cast was added). '
                    .'Delete the entry: the gate can assert this column.';

                continue;
            }

            if ($declared['kind'] === 'no-model') {
                if (in_array($declared['table'], $tablesWithModels, true)) {
                    $problems[] = $key.' — declared UNGATEABLE as "no Eloquent model", but a model now maps to '
                        .$declared['table'].'. Re-evaluate: cast the column and delete this entry.';
                }

                continue;
            }

            if ($declared['kind'] !== 'no-cast') {
                $problems[] = $key.' — unknown UNGATEABLE kind "'.$declared['kind'].'".';

                continue;
            }

            if ($declared['model'] === null || ! class_exists($declared['model'])) {
                $problems[] = $key.' — declared UNGATEABLE as "no cast" against model '
                    .($declared['model'] ?? 'null').', which no longer exists.';
            }
        }

        return $problems;
    }

    private function isRecognisedEnumFile(string $file): bool
    {
        if (! $this->isUnderAppPath($file)) {
            return false;
        }

        foreach (self::INCLUDED_ENUM_PATHS as $pattern) {
            if (preg_match($pattern, $file) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isUnderAppPath(string $file): bool
    {
        return $file !== '' && str_starts_with($file, rtrim($this->appPath, '/').'/');
    }

    private function relativeFile(string $file): string
    {
        return 'app/'.substr($file, strlen(rtrim($this->appPath, '/')) + 1);
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
