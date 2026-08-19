<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Document-per-action write scanner (enforcement package P1).
 *
 * Statically enumerates every WRITE (insert/update/delete — reads are out of
 * scope) against the four tables whose mutation the document-per-action
 * principle governs, and classifies each site as LINKED (a justifying document
 * reference is present per the per-table rule) or VIOLATION.
 *
 * The engine is deliberately fail-closed: whenever a payload or a receiver type
 * cannot be resolved statically at a site that is IN CONTRACT, the site is
 * reported as a violation rather than waved through. Sites the contract does
 * not govern are classified `not_applicable` and never reported (see the
 * per-table rules below for exactly which ones, and why).
 *
 * No database, no Laravel container: pure PHP-Parser AST traversal over the
 * production tree, in the house style of tests/Architecture/TenantScopedFindCallsTest.php.
 *
 * ---------------------------------------------------------------------------
 * PER-TABLE REFERENCE RULES (P1-M1 deliverable — the brief's §2 table is the
 * starting contract; this is the operative statement of it)
 * ---------------------------------------------------------------------------
 *
 * A "write class" is one of:
 *   CREATE  — create/forceCreate/createMany/createQuietly/firstOrCreate/
 *             updateOrCreate/insert/insertGetId/insertOrIgnore/insertUsing/
 *             upsert, DB::table() insert/upsert, raw INSERT
 *   MUTATE  — update/updateQuietly/updateOrInsert/save/saveQuietly/saveMany/
 *             push/increment/decrement/incrementEach/decrementEach/restore,
 *             DB::table()->update/increment/decrement, raw UPDATE
 *   DELETE  — delete/deleteQuietly/forceDelete/destroy/truncate,
 *             DB::table()->delete/truncate, raw DELETE
 *
 * 1. `journal_entries` — reference columns `source_type` + `source_id`
 *    (`JournalEntry::$fillable`; note `journal_entries(source_type,source_id)`
 *    is NOT globally unique, so the check is PRESENCE, never uniqueness).
 *    - CREATE : LINKED iff the payload statically carries BOTH `source_type`
 *               and `source_id` with non-null values. Unresolvable payload
 *               (variable, spread, dynamic key) => VIOLATION (fail closed).
 *    - DELETE : always VIOLATION. A posted journal entry is removed by a
 *               reversal document, never by a row delete (DPA lane V1).
 *    - MUTATE : VIOLATION when the payload resolvably NULLS or blanks either
 *               linkage column (linkage erasure), or when the mechanism is
 *               increment/decrement (numeric mutation of a fiscal row).
 *               Otherwise NOT IN CONTRACT: `journal_entries` carries no
 *               monetary amount (debit/credit live on `journal_entry_lines`,
 *               outside this package's four-table contract), so a lifecycle
 *               update (status, posted_at, fiscal_hash, reversal_*) mutates a
 *               row whose justification was fixed at creation. Recorded scope
 *               boundary, not an oversight.
 *
 * 2. `stock_movements` — reference columns `reference_type` + `reference_id`,
 *    PAIRED (the S0 seam: StockAdjustmentService::recordMovement asserts
 *    `assertReferenceLinkagePaired`).
 *    - CREATE : LINKED iff the payload statically carries BOTH `reference_type`
 *               and `reference_id` with non-null values; unresolvable => VIOLATION.
 *    - MUTATE : always VIOLATION — the movement ledger is append-only; a
 *               correction is a new (reversing) movement, never an edit.
 *    - DELETE : always VIOLATION, same reason.
 *
 * 3. `stock_levels` — NO reference column exists on this table (see
 *    `StockLevel::$fillable`), so the rule cannot be a column rule. Per the
 *    brief: "a level mutation must be traceable to a movement (i.e. flag direct
 *    StockLevel writes that bypass the movement chokepoint)". Operative,
 *    checkable form — the MOVEMENT-PAIRING PREDICATE:
 *      LINKED iff the enclosing function body ALSO contains either
 *        (a) a call named `recordMovement` (the S0 chokepoint), or
 *        (b) a CREATE-class write to `stock_movements`.
 *      Every other write site bypasses the chokepoint => VIOLATION.
 *    This is deliberately NOT a class allowlist: an allowlist would let a new
 *    violator be waved through by adding its class name, whereas the pairing
 *    predicate has to be satisfied by the code at the site itself.
 *    SCOPE of the predicate: only writes that can move the ON-HAND `quantity`
 *    column. A MUTATE-class write whose payload is statically resolvable and
 *    touches only `reserved`/`reserved_quantity`/`min_quantity`/`max_quantity`
 *    is a soft hold or a threshold setting — no stock enters or leaves and
 *    nothing posts to the ledger — so it is classified `not_applicable`.
 *    CREATE-class and DELETE-class writes are always in contract (a new row
 *    establishes an on-hand value even at zero), and an unresolvable payload
 *    (`save()`, a variable array) never earns the exemption: fail closed.
 *
 * 4. `inventory_batch_stock` — same situation (no reference column on
 *    `BatchStock::$fillable`) and therefore the SAME movement-pairing
 *    predicate as `stock_levels`.
 *
 * 5. RAW SQL (any of the four tables) — always VIOLATION. The scanner does not
 *    parse SQL column lists, and a justified write always has a model or
 *    query-builder form, so a raw INSERT/UPDATE/DELETE against these tables has
 *    no LINKED form by rule. This is a contract decision, not a scanner
 *    limitation: it is why the raw-SQL row of the fixture matrix has no
 *    negative case.
 *
 * A per-site key is line-number-free and stable under reformatting:
 *   `<relative file>::<class>::<function>::<table>::<mechanism>#<ordinal>`
 * where <ordinal> disambiguates repeated identical mechanisms inside one
 * function (1-based, in source order).
 */
final class DocumentPerActionWriteScanner
{
    /**
     * Table => the Eloquent model FQCN whose `$table` is that table.
     *
     * @var array<string, string>
     */
    public const TABLE_MODELS = [
        'journal_entries' => 'App\Modules\Accounting\Domain\JournalEntry',
        'stock_movements' => 'App\Modules\Inventory\Domain\StockMovement',
        'stock_levels' => 'App\Modules\Inventory\Domain\StockLevel',
        'inventory_batch_stock' => 'App\Modules\BatchExpiry\Domain\Entities\BatchStock',
    ];

    /**
     * Reference-column pair per table; null when the table has no reference
     * column and the movement-pairing predicate applies instead.
     *
     * @var array<string, array{0: string, 1: string}|null>
     */
    public const TABLE_REFERENCE_COLUMNS = [
        'journal_entries' => ['source_type', 'source_id'],
        'stock_movements' => ['reference_type', 'reference_id'],
        'stock_levels' => null,
        'inventory_batch_stock' => null,
    ];

    /**
     * The on-hand quantity column on both level tables. A MUTATE-class write
     * that provably touches none of it (only `reserved`/`reserved_quantity`/
     * `min_quantity`/`max_quantity`) is a soft hold or a threshold setting: no
     * stock leaves or enters the company, nothing posts to the ledger, so the
     * document-per-action contract does not govern it. Unresolvable payloads
     * are never credited with this exemption (fail closed).
     */
    private const ON_HAND_COLUMN = 'quantity';

    /**
     * `inventory_batch_movements` — the join row whose NOT-NULL `movement_id`
     * FK ties a batch-stock change to an aggregate `stock_movements` row.
     */
    private const BATCH_MOVEMENT_MODEL = 'App\\Modules\\BatchExpiry\\Domain\\Entities\\BatchMovement';

    /**
     * Eloquent/builder write method => [mechanism bucket, write class].
     *
     * The mechanism bucket is the vocabulary the brief's fixture matrix uses
     * (§2 deliverable 6); the write class drives the per-table rule above.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const WRITE_METHODS = [
        'create' => ['create', 'CREATE'],
        'forceCreate' => ['create', 'CREATE'],
        'createQuietly' => ['create', 'CREATE'],
        'createMany' => ['create', 'CREATE'],
        'firstOrCreate' => ['firstOrCreate', 'CREATE'],
        'updateOrCreate' => ['updateOrCreate', 'CREATE'],
        'insert' => ['create', 'CREATE'],
        'insertGetId' => ['create', 'CREATE'],
        'insertOrIgnore' => ['create', 'CREATE'],
        'insertUsing' => ['create', 'CREATE'],
        'upsert' => ['create', 'CREATE'],
        'update' => ['update', 'MUTATE'],
        'updateQuietly' => ['update', 'MUTATE'],
        'updateOrInsert' => ['update', 'MUTATE'],
        'save' => ['save', 'MUTATE'],
        'saveQuietly' => ['save', 'MUTATE'],
        'saveMany' => ['save', 'MUTATE'],
        'push' => ['save', 'MUTATE'],
        'restore' => ['save', 'MUTATE'],
        'increment' => ['increment', 'MUTATE'],
        'incrementEach' => ['increment', 'MUTATE'],
        'incrementQuietly' => ['increment', 'MUTATE'],
        'decrement' => ['decrement', 'MUTATE'],
        'decrementEach' => ['decrement', 'MUTATE'],
        'decrementQuietly' => ['decrement', 'MUTATE'],
        'delete' => ['delete', 'DELETE'],
        'deleteQuietly' => ['delete', 'DELETE'],
        'forceDelete' => ['delete', 'DELETE'],
        'destroy' => ['delete', 'DELETE'],
        'truncate' => ['delete', 'DELETE'],
    ];

    /**
     * Methods whose first (and for firstOrCreate/updateOrCreate/upsert also
     * second) array argument is the written payload.
     *
     * @var array<string, int>
     */
    private const PAYLOAD_ARG_COUNT = [
        'create' => 1,
        'forceCreate' => 1,
        'createQuietly' => 1,
        'insert' => 1,
        'insertGetId' => 1,
        'insertOrIgnore' => 1,
        'firstOrCreate' => 2,
        'updateOrCreate' => 2,
        'upsert' => 2,
        'update' => 1,
        'updateQuietly' => 1,
        'updateOrInsert' => 2,
    ];

    /**
     * Raw-SQL sinks: DB facade methods whose string argument is executed SQL.
     *
     * @var list<string>
     */
    private const RAW_SQL_METHODS = ['statement', 'unprepared', 'insert', 'update', 'delete', 'affectingStatement'];

    /** @var array<string, string> alias/short name => FQCN, for the file being scanned */
    private array $useMap = [];

    private string $namespace = '';

    private string $currentClass = '';

    /** @var array<string, string> property name => model FQCN */
    private array $propertyTypes = [];

    /** @var array<string, string> method name => model FQCN, for `$this->helper()` receivers */
    private array $methodReturnTypes = [];

    /** @var array<string, string> variable name => model FQCN */
    private array $varTypes = [];

    /** @var array<string, string> relation method name => model FQCN (unambiguous ones only) */
    private array $relationMap = [];

    private NodeFinder $finder;

    private Parser $parser;

    public function __construct()
    {
        $this->finder = new NodeFinder;
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Scan a set of absolute roots and return every write site found.
     *
     * @param  list<string>  $roots  absolute directory (or file) paths
     * @param  string  $relativeTo  absolute prefix stripped from reported paths
     * @return list<array{key: string, file: string, line: int, class: string, function: string, table: string, mechanism: string, write_class: string, classification: string, reason: string}>
     */
    public function scan(array $roots, string $relativeTo): array
    {
        $this->relationMap = $this->buildRelationMap($roots);

        $sites = [];
        foreach ($this->files($roots) as $path) {
            foreach ($this->scanFile($path, $relativeTo) as $site) {
                $sites[] = $site;
            }
        }

        usort($sites, static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['key']] <=> [$b['file'], $b['line'], $b['key']]);

        return $sites;
    }

    /**
     * @return list<array{key: string, file: string, line: int, class: string, function: string, table: string, mechanism: string, write_class: string, classification: string, reason: string}>
     */
    public function scanFile(string $path, string $relativeTo): array
    {
        $code = (string) file_get_contents($path);
        $stmts = $this->parser->parse($code);
        if ($stmts === null) {
            return [];
        }

        $relative = str_starts_with($path, $relativeTo) ? substr($path, strlen($relativeTo)) : $path;
        $relative = ltrim($relative, '/');

        $this->namespace = $this->firstNamespace($stmts);
        $this->useMap = $this->buildUseMap($stmts);

        $sites = [];

        foreach ($this->classLikes($stmts) as $classLike) {
            $this->currentClass = $classLike['name'];
            $this->propertyTypes = $this->buildPropertyTypes($classLike['node']);
            $this->methodReturnTypes = $this->buildMethodReturnTypes($classLike['node']);

            foreach ($this->functionLikes($classLike['node']) as $fn) {
                foreach ($this->scanFunction($fn['node'], $relative, $fn['name']) as $site) {
                    $sites[] = $site;
                }
            }
        }

        // Function-like code outside any class (rare in app/, but real: helpers,
        // route/closure files). Scanned with an empty class context.
        $this->currentClass = '';
        $this->propertyTypes = [];
        $this->methodReturnTypes = [];
        foreach ($this->topLevelFunctionLikes($stmts) as $fn) {
            foreach ($this->scanFunction($fn['node'], $relative, $fn['name']) as $site) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    /**
     * @return list<array{key: string, file: string, line: int, class: string, function: string, table: string, mechanism: string, write_class: string, classification: string, reason: string}>
     */
    private function scanFunction(Node $fn, string $relativeFile, string $functionName): array
    {
        $this->varTypes = $this->buildVarTypes($fn);

        $pairing = $this->functionRecordsMovement($fn);
        $erasesLinkage = $this->functionErasesLinkage($fn);

        /** @var list<array{node: Node, table: string, mechanism: string, write_class: string, payload: array{resolved: bool, keys: array<string, bool>}}> $raw */
        $raw = [];

        foreach ($this->finder->find($fn, static fn (Node $n): bool => $n instanceof Expr\MethodCall
            || $n instanceof Expr\NullsafeMethodCall
            || $n instanceof Expr\StaticCall) as $node) {
            $hit = $this->classifyCall($node);
            if ($hit !== null) {
                $raw[] = $hit;
            }
        }

        foreach ($this->finder->find($fn, static fn (Node $n): bool => $n instanceof Expr\StaticCall || $n instanceof Expr\MethodCall) as $node) {
            $hit = $this->classifyRawSql($node);
            if ($hit !== null) {
                $raw[] = $hit;
            }
        }

        usort($raw, static fn (array $a, array $b): int => $a['node']->getStartLine() <=> $b['node']->getStartLine());

        $ordinals = [];
        $sites = [];

        foreach ($raw as $hit) {
            $bucket = $hit['table'].'::'.$hit['mechanism'];
            $ordinals[$bucket] = ($ordinals[$bucket] ?? 0) + 1;

            [$classification, $reason] = $this->classifySite(
                $hit['table'],
                $hit['write_class'],
                $hit['mechanism'],
                $hit['payload'],
                $pairing,
                $erasesLinkage[$hit['table']] ?? false,
            );

            $key = sprintf(
                '%s::%s::%s::%s::%s#%d',
                $relativeFile,
                $this->currentClass === '' ? '(none)' : $this->currentClass,
                $functionName,
                $hit['table'],
                $hit['mechanism'],
                $ordinals[$bucket],
            );

            $sites[] = [
                'key' => $key,
                'file' => $relativeFile,
                'line' => $hit['node']->getStartLine(),
                'class' => $this->currentClass,
                'function' => $functionName,
                'table' => $hit['table'],
                'mechanism' => $hit['mechanism'],
                'write_class' => $hit['write_class'],
                'classification' => $classification,
                'reason' => $reason,
            ];
        }

        return $sites;
    }

    /**
     * The operative per-table rules (see the class docblock).
     *
     * @param  array{resolved: bool, keys: array<string, bool>}  $payload  keys => value-is-non-null
     * @param  array{movement: bool, batch_movement: bool}  $pairing
     * @param  bool  $erasesLinkage  the enclosing function assigns null to a reference column of this table
     * @return array{0: string, 1: string} [classification, reason]
     */
    private function classifySite(string $table, string $writeClass, string $mechanism, array $payload, array $pairing, bool $erasesLinkage): array
    {
        // Raw SQL is never credited and never exempted: the scanner does not
        // parse SQL column lists, and a justified write against these four
        // tables always has a model or query-builder form. There is therefore
        // no LINKED form of a raw write — by rule, not by scanner limitation.
        if ($mechanism === 'raw_sql') {
            return ['violation', sprintf('raw SQL write against %s — no linkage is provable and none is accepted; use the document-keyed service path', $table)];
        }

        $reference = self::TABLE_REFERENCE_COLUMNS[$table];

        if ($reference === null) {
            // stock_levels / inventory_batch_stock — movement-pairing predicate,
            // applied only to writes that can move the ON-HAND quantity.
            if ($writeClass === 'MUTATE' && $payload['resolved'] && $payload['keys'] !== []
                && ! array_key_exists(self::ON_HAND_COLUMN, $payload['keys'])) {
                return ['not_applicable', 'mutates only reservation/threshold columns ('.implode(', ', array_keys($payload['keys'])).') — a soft hold with no on-hand or ledger effect'];
            }

            if ($pairing['movement']) {
                return ['linked', 'enclosing function records a stock movement (recordMovement call or stock_movements create) in the same scope'];
            }

            if ($table === 'inventory_batch_stock' && $pairing['batch_movement']) {
                return ['linked', 'enclosing function creates an inventory_batch_movements row carrying movement_id (NOT-NULL FK to stock_movements)'];
            }

            return ['violation', 'level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint'];
        }

        [$typeColumn, $idColumn] = $reference;

        if ($writeClass === 'DELETE') {
            return ['violation', sprintf('%s rows are append-only; a correction is a reversing document, never a row delete', $table)];
        }

        if ($writeClass === 'CREATE') {
            if (! $payload['resolved']) {
                return ['violation', sprintf('payload not statically resolvable — cannot prove %s/%s linkage (fail closed)', $typeColumn, $idColumn)];
            }

            $hasType = ($payload['keys'][$typeColumn] ?? false) === true;
            $hasId = ($payload['keys'][$idColumn] ?? false) === true;

            if ($hasType && $hasId) {
                return ['linked', sprintf('payload carries %s + %s', $typeColumn, $idColumn)];
            }

            return ['violation', sprintf('create-class write without paired %s/%s linkage', $typeColumn, $idColumn)];
        }

        // MUTATE
        if ($table === 'stock_movements') {
            return ['violation', 'the stock-movement ledger is append-only; an existing movement is never mutated'];
        }

        // journal_entries MUTATE
        if ($mechanism === 'increment' || $mechanism === 'decrement') {
            return ['violation', 'numeric mutation of a fiscal journal row without a justifying document'];
        }

        if ($payload['resolved']) {
            $nullsType = array_key_exists($typeColumn, $payload['keys']) && $payload['keys'][$typeColumn] === false;
            $nullsId = array_key_exists($idColumn, $payload['keys']) && $payload['keys'][$idColumn] === false;
            if ($nullsType || $nullsId) {
                return ['violation', sprintf('lifecycle update erases %s/%s linkage', $typeColumn, $idColumn)];
            }
        }

        if ($erasesLinkage) {
            return ['violation', sprintf('the enclosing function assigns null to %s/%s before persisting — linkage erasure', $typeColumn, $idColumn)];
        }

        return ['not_applicable', 'journal_entries lifecycle mutation: this table carries no monetary amount (amounts live on journal_entry_lines, outside the P1 contract) and the row\'s justification was fixed at creation'];
    }

    /**
     * @return array{node: Node, table: string, mechanism: string, write_class: string, payload: array{resolved: bool, keys: array<string, bool>}}|null
     */
    private function classifyCall(Node $node): ?array
    {
        if (! $node instanceof Expr\MethodCall && ! $node instanceof Expr\NullsafeMethodCall && ! $node instanceof Expr\StaticCall) {
            return null;
        }
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }

        $method = $node->name->toString();
        if (! isset(self::WRITE_METHODS[$method])) {
            return null;
        }

        [$mechanism, $writeClass] = self::WRITE_METHODS[$method];

        $table = $this->resolveChainTable($node);
        if ($table === null) {
            return null;
        }

        if ($this->isQueryBuilderChain($node)) {
            $mechanism = 'query_builder';
        }

        return [
            'node' => $node,
            'table' => $table,
            'mechanism' => $mechanism,
            'write_class' => $writeClass,
            'payload' => $this->extractPayload($node, $method),
        ];
    }

    /**
     * Raw SQL executed through the DB facade / a connection: DB::statement(...),
     * DB::update(...), DB::insert(...), DB::delete(...), DB::unprepared(...).
     *
     * @return array{node: Node, table: string, mechanism: string, write_class: string, payload: array{resolved: bool, keys: array<string, bool>}}|null
     */
    private function classifyRawSql(Node $node): ?array
    {
        if (! $node instanceof Expr\StaticCall && ! $node instanceof Expr\MethodCall) {
            return null;
        }
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }
        $method = $node->name->toString();
        if (! in_array($method, self::RAW_SQL_METHODS, true)) {
            return null;
        }
        if (! $this->receiverIsDb($node)) {
            return null;
        }

        $args = $node->getArgs();
        if ($args === []) {
            return null;
        }
        $sql = $this->stringValue($args[0]->value);
        if ($sql === null) {
            return null;
        }

        $upper = strtoupper(preg_replace('/\s+/', ' ', $sql) ?? '');

        foreach (array_keys(self::TABLE_MODELS) as $table) {
            if (! str_contains(strtolower($sql), $table)) {
                continue;
            }

            $writeClass = null;
            if (preg_match('/\bINSERT\s+(INTO|IGNORE)/', $upper) === 1) {
                $writeClass = 'CREATE';
            } elseif (preg_match('/\bUPDATE\b/', $upper) === 1) {
                $writeClass = 'MUTATE';
            } elseif (preg_match('/\bDELETE\s+FROM\b|\bTRUNCATE\b/', $upper) === 1) {
                $writeClass = 'DELETE';
            }

            if ($writeClass === null) {
                continue;
            }

            return [
                'node' => $node,
                'table' => $table,
                'mechanism' => 'raw_sql',
                'write_class' => $writeClass,
                // Raw SQL column extraction is not attempted: a raw write is
                // never credited with linkage (fail closed by construction).
                'payload' => ['resolved' => false, 'keys' => []],
            ];
        }

        return null;
    }

    private function receiverIsDb(Node $node): bool
    {
        if ($node instanceof Expr\StaticCall) {
            return $node->class instanceof Node\Name && $this->isDbFacade($this->resolveName($node->class));
        }

        // ->connection(...)->statement(...) style
        $var = $node instanceof Expr\MethodCall ? $node->var : null;
        while ($var instanceof Expr\MethodCall || $var instanceof Expr\NullsafeMethodCall) {
            $var = $var->var;
        }
        if ($var instanceof Expr\StaticCall && $var->class instanceof Node\Name) {
            return $this->isDbFacade($this->resolveName($var->class));
        }

        return false;
    }

    private function isDbFacade(string $fqcn): bool
    {
        return $fqcn === 'Illuminate\Support\Facades\DB' || $fqcn === 'DB';
    }

    /**
     * Resolve which target table (if any) a write call chain acts on.
     */
    private function resolveChainTable(Node $node): ?string
    {
        $receiver = null;

        if ($node instanceof Expr\StaticCall) {
            if (! $node->class instanceof Node\Name) {
                return null;
            }

            return $this->tableForModel($this->resolveName($node->class));
        }

        /** @var Expr\MethodCall|Expr\NullsafeMethodCall $node */
        $receiver = $node->var;

        // Walk the chain down to its base, checking each hop.
        $cursor = $receiver;
        while ($cursor !== null) {
            if ($cursor instanceof Expr\MethodCall || $cursor instanceof Expr\NullsafeMethodCall) {
                if ($cursor->name instanceof Node\Identifier) {
                    $relation = $this->relationMap[$cursor->name->toString()] ?? null;
                    if ($relation !== null) {
                        $table = $this->tableForModel($relation);
                        if ($table !== null) {
                            return $table;
                        }
                    }
                    $builderTable = $this->queryBuilderTable($cursor);
                    if ($builderTable !== null) {
                        return $builderTable;
                    }
                    $ownHelper = $this->ownHelperReturn($cursor);
                    if ($ownHelper !== null) {
                        $table = $this->tableForModel($ownHelper);
                        if ($table !== null) {
                            return $table;
                        }
                    }
                }
                $cursor = $cursor->var;

                continue;
            }

            if ($cursor instanceof Expr\StaticCall) {
                // `DB::table('stock_levels')->insert([...])` — the builder hop
                // is a STATIC call, so it must be checked here too, not only on
                // the MethodCall hops (`DB::connection(…)->table(…)`).
                $builderTable = $this->queryBuilderTable($cursor);
                if ($builderTable !== null) {
                    return $builderTable;
                }
                if ($cursor->class instanceof Node\Name) {
                    return $this->tableForModel($this->resolveName($cursor->class));
                }

                return null;
            }

            if ($cursor instanceof Expr\Variable && is_string($cursor->name)) {
                $type = $this->varTypes[$cursor->name] ?? null;

                return $type === null ? null : $this->tableForModel($type);
            }

            if ($cursor instanceof Expr\PropertyFetch && $cursor->name instanceof Node\Identifier) {
                $type = $this->propertyTypes[$cursor->name->toString()] ?? null;

                return $type === null ? null : $this->tableForModel($type);
            }

            if ($cursor instanceof Expr\New_ && $cursor->class instanceof Node\Name) {
                return $this->tableForModel($this->resolveName($cursor->class));
            }

            return null;
        }

        return null;
    }

    /**
     * `DB::table('journal_entries')` / `->table('stock_levels')` hop.
     */
    private function queryBuilderTable(Node $node): ?string
    {
        if (! $node instanceof Expr\MethodCall && ! $node instanceof Expr\StaticCall && ! $node instanceof Expr\NullsafeMethodCall) {
            return null;
        }
        if (! $node->name instanceof Node\Identifier || $node->name->toString() !== 'table') {
            return null;
        }
        $args = $node->getArgs();
        if ($args === []) {
            return null;
        }
        $name = $this->stringValue($args[0]->value);
        if ($name === null) {
            return null;
        }
        $name = trim(explode(' ', trim($name))[0]);

        return isset(self::TABLE_MODELS[$name]) ? $name : null;
    }

    private function isQueryBuilderChain(Node $node): bool
    {
        $cursor = $node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall ? $node->var : null;
        while ($cursor !== null) {
            if ($this->queryBuilderTable($cursor) !== null) {
                return true;
            }
            if ($cursor instanceof Expr\MethodCall || $cursor instanceof Expr\NullsafeMethodCall) {
                $cursor = $cursor->var;

                continue;
            }
            if ($cursor instanceof Expr\StaticCall) {
                // Only the `table('…')` static hop itself qualifies, and it was
                // already tested above.
                return false;
            }

            return false;
        }

        return false;
    }

    private function tableForModel(string $fqcn): ?string
    {
        foreach (self::TABLE_MODELS as $table => $model) {
            if ($fqcn === $model) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @return array{resolved: bool, keys: array<string, bool>} key => value is non-null
     */
    private function extractPayload(Node $node, string $method): array
    {
        // increment('column', …) / decrement('column', …) name the touched
        // column in argument 0; incrementEach(['col' => n]) uses an array.
        if (in_array($method, ['increment', 'decrement', 'incrementQuietly', 'decrementQuietly', 'incrementEach', 'decrementEach'], true)) {
            $args = $node->getArgs();
            if ($args === []) {
                return ['resolved' => false, 'keys' => []];
            }
            $first = $args[0]->value;
            if ($first instanceof Expr\Array_) {
                $keys = [];
                $resolved = true;
                foreach ($first->items as $item) {
                    $key = $item?->key === null ? null : $this->stringValue($item->key);
                    if ($key === null) {
                        $resolved = false;

                        continue;
                    }
                    $keys[$key] = true;
                }

                return ['resolved' => $resolved, 'keys' => $keys];
            }
            $column = $this->stringValue($first);
            if ($column === null) {
                return ['resolved' => false, 'keys' => []];
            }

            return ['resolved' => true, 'keys' => [$column => true]];
        }

        $count = self::PAYLOAD_ARG_COUNT[$method] ?? 0;
        if ($count === 0) {
            // save()/delete()/... carry no inspectable payload.
            return ['resolved' => false, 'keys' => []];
        }

        $args = $node->getArgs();
        $keys = [];
        $resolved = true;
        $seenArray = false;

        for ($i = 0; $i < $count; $i++) {
            if (! isset($args[$i])) {
                continue;
            }
            $value = $args[$i]->value;
            if (! $value instanceof Expr\Array_) {
                $resolved = false;

                continue;
            }
            $seenArray = true;
            foreach ($value->items as $item) {
                if ($item === null || $item->key === null || $item->unpack) {
                    $resolved = false;

                    continue;
                }
                $key = $this->stringValue($item->key);
                if ($key === null) {
                    $resolved = false;

                    continue;
                }
                $isNonNull = ! ($item->value instanceof Expr\ConstFetch
                    && $item->value->name->toLowerString() === 'null');
                // A key seen non-null anywhere wins; a null-only key stays false.
                $keys[$key] = ($keys[$key] ?? false) || $isNonNull;
            }
        }

        if (! $seenArray) {
            $resolved = false;
        }

        return ['resolved' => $resolved, 'keys' => $keys];
    }

    /**
     * The movement-pairing predicate for stock_levels / inventory_batch_stock.
     *
     * `movement` — the enclosing function records an aggregate stock movement:
     *   a `recordMovement` call (the S0 chokepoint) or a CREATE-class write to
     *   `stock_movements`.
     * `batch_movement` — the enclosing function creates an `inventory_batch_movements`
     *   row that statically carries a non-null `movement_id`. That column is a
     *   NOT-NULL FK to `stock_movements` (see BatchMovement's docblock: "Links
     *   batch-level stock movements to the aggregate stock_movements table"), so
     *   the batch-stock change IS traceable to a justifying movement. Only
     *   `inventory_batch_stock` may be justified this way, and only when the
     *   `movement_id` key is unconditionally present in the payload — a
     *   conditionally-assembled array (BatchStockService::recordBatchMovement,
     *   where `movement_id` is optional) does not qualify.
     *
     * @return array{movement: bool, batch_movement: bool}
     */
    private function functionRecordsMovement(Node $fn): array
    {
        $result = ['movement' => false, 'batch_movement' => false];

        foreach ($this->finder->find($fn, static fn (Node $n): bool => $n instanceof Expr\MethodCall
            || $n instanceof Expr\NullsafeMethodCall
            || $n instanceof Expr\StaticCall) as $call) {
            /** @var Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call */
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }
            if ($call->name->toString() === 'recordMovement') {
                $result['movement'] = true;

                continue;
            }
            $hit = $this->classifyCall($call);
            if ($hit !== null && $hit['table'] === 'stock_movements' && $hit['write_class'] === 'CREATE') {
                $result['movement'] = true;

                continue;
            }
            if ($this->isLinkedBatchMovementCreate($call)) {
                $result['batch_movement'] = true;
            }
        }

        return $result;
    }

    /**
     * Linkage erasure through PROPERTY assignment: `$entry->source_id = null;`
     * followed by `$entry->save()`. The payload of a `save()` is invisible to
     * the scanner, so without this the erasure path would be the one way to
     * strip a justifying reference without tripping the guard.
     *
     * @return array<string, bool> table => an erasing assignment was seen
     */
    private function functionErasesLinkage(Node $fn): array
    {
        $out = [];

        foreach ($this->finder->findInstanceOf($fn, Expr\Assign::class) as $assign) {
            /** @var Expr\Assign $assign */
            if (! $assign->var instanceof Expr\PropertyFetch || ! $assign->var->name instanceof Node\Identifier) {
                continue;
            }
            if (! ($assign->expr instanceof Expr\ConstFetch && $assign->expr->name->toLowerString() === 'null')) {
                continue;
            }

            $column = $assign->var->name->toString();
            foreach (self::TABLE_REFERENCE_COLUMNS as $table => $columns) {
                if ($columns === null || ! in_array($column, $columns, true)) {
                    continue;
                }
                $receiver = $this->expressionModel($assign->var->var, $this->varTypes);
                if ($receiver !== null && $this->tableForModel($receiver) === $table) {
                    $out[$table] = true;
                }
            }
        }

        return $out;
    }

    private function isLinkedBatchMovementCreate(Node $call): bool
    {
        if (! $call instanceof Expr\StaticCall || ! $call->class instanceof Node\Name) {
            return false;
        }
        if (! $call->name instanceof Node\Identifier) {
            return false;
        }
        $method = $call->name->toString();
        if (! in_array($method, ['create', 'forceCreate', 'firstOrCreate', 'updateOrCreate', 'insert'], true)) {
            return false;
        }
        if ($this->resolveName($call->class) !== self::BATCH_MOVEMENT_MODEL) {
            return false;
        }
        $payload = $this->extractPayload($call, $method);

        return $payload['resolved'] && ($payload['keys']['movement_id'] ?? false) === true;
    }

    // ---------------------------------------------------------------- parsing

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function files(array $roots): array
    {
        $out = [];
        foreach ($roots as $root) {
            if (is_file($root)) {
                $out[] = $root;

                continue;
            }
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $entry) {
                if ($entry instanceof SplFileInfo && $entry->getExtension() === 'php') {
                    $out[] = $entry->getPathname();
                }
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Relation method name => model FQCN, for relation-mediated writes
     * (`$batch->stocks()->create([...])`). Names that map to more than one
     * model are dropped as ambiguous.
     *
     * @param  list<string>  $roots
     * @return array<string, string>
     */
    private function buildRelationMap(array $roots): array
    {
        $candidates = [];

        foreach ($this->files($roots) as $path) {
            $code = (string) file_get_contents($path);
            if (! str_contains($code, 'hasMany') && ! str_contains($code, 'hasOne') && ! str_contains($code, 'morphMany')) {
                continue;
            }
            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                continue;
            }
            $namespace = $this->firstNamespace($stmts);
            $useMap = $this->buildUseMap($stmts);

            foreach ($this->finder->findInstanceOf($stmts, Stmt\ClassMethod::class) as $method) {
                foreach ($this->finder->find($method, static fn (Node $n): bool => $n instanceof Expr\MethodCall) as $call) {
                    /** @var Expr\MethodCall $call */
                    if (! $call->name instanceof Node\Identifier) {
                        continue;
                    }
                    if (! in_array($call->name->toString(), ['hasMany', 'hasOne', 'morphMany', 'morphOne'], true)) {
                        continue;
                    }
                    $args = $call->getArgs();
                    if ($args === []) {
                        continue;
                    }
                    $target = $args[0]->value;
                    if (! $target instanceof Expr\ClassConstFetch || ! $target->class instanceof Node\Name) {
                        continue;
                    }
                    $fqcn = $this->resolveNameWith($target->class, $namespace, $useMap);
                    if ($this->tableForModel($fqcn) === null) {
                        continue;
                    }
                    $candidates[$method->name->toString()][$fqcn] = true;
                }
            }
        }

        $map = [];
        foreach ($candidates as $name => $targets) {
            if (count($targets) === 1) {
                $map[$name] = array_key_first($targets);
            }
        }

        return $map;
    }

    /**
     * @param  list<Node>  $stmts
     */
    private function firstNamespace(array $stmts): string
    {
        foreach ($this->finder->findInstanceOf($stmts, Stmt\Namespace_::class) as $ns) {
            return $ns->name === null ? '' : $ns->name->toString();
        }

        return '';
    }

    /**
     * @param  list<Node>  $stmts
     * @return array<string, string>
     */
    private function buildUseMap(array $stmts): array
    {
        $map = [];
        foreach ($this->finder->findInstanceOf($stmts, Stmt\Use_::class) as $use) {
            foreach ($use->uses as $useUse) {
                $fqcn = $useUse->name->toString();
                $alias = $useUse->alias?->toString() ?? $useUse->name->getLast();
                $map[$alias] = $fqcn;
            }
        }
        foreach ($this->finder->findInstanceOf($stmts, Stmt\GroupUse::class) as $group) {
            $prefix = $group->prefix->toString();
            foreach ($group->uses as $useUse) {
                $fqcn = $prefix.'\\'.$useUse->name->toString();
                $alias = $useUse->alias?->toString() ?? $useUse->name->getLast();
                $map[$alias] = $fqcn;
            }
        }

        return $map;
    }

    /**
     * @param  list<Node>  $stmts
     * @return list<array{name: string, node: Node}>
     */
    private function classLikes(array $stmts): array
    {
        $out = [];
        foreach ($this->finder->findInstanceOf($stmts, Stmt\ClassLike::class) as $classLike) {
            /** @var Stmt\ClassLike $classLike */
            $name = $classLike->name?->toString();
            if ($name === null) {
                $name = '(anonymous)';
            }
            $out[] = [
                'name' => $this->namespace === '' ? $name : $this->namespace.'\\'.$name,
                'node' => $classLike,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, node: Node}>
     */
    private function functionLikes(Node $classLike): array
    {
        $out = [];
        foreach ($this->finder->findInstanceOf($classLike, Stmt\ClassMethod::class) as $method) {
            /** @var Stmt\ClassMethod $method */
            $out[] = ['name' => $method->name->toString(), 'node' => $method];
        }

        return $out;
    }

    /**
     * @param  list<Node>  $stmts
     * @return list<array{name: string, node: Node}>
     */
    private function topLevelFunctionLikes(array $stmts): array
    {
        $out = [];
        foreach ($this->finder->findInstanceOf($stmts, Stmt\Function_::class) as $function) {
            /** @var Stmt\Function_ $function */
            $out[] = ['name' => $function->name->toString(), 'node' => $function];
        }

        return $out;
    }

    /**
     * `$this->resolveStockLevelForUpdate(...)` — a same-class helper whose
     * declared return type is one of the target models. Without this hop the
     * scanner silently misses every write on a model obtained from a private
     * finder method (e.g. ReceiptReturnService::restoreStock).
     */
    private function ownHelperReturn(Expr $call): ?string
    {
        if (! $call instanceof Expr\MethodCall && ! $call instanceof Expr\NullsafeMethodCall) {
            return null;
        }
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }
        if (! $call->var instanceof Expr\Variable || $call->var->name !== 'this') {
            return null;
        }

        return $this->methodReturnTypes[$call->name->toString()] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function buildMethodReturnTypes(Node $classLike): array
    {
        $types = [];
        foreach ($this->finder->findInstanceOf($classLike, Stmt\ClassMethod::class) as $method) {
            /** @var Stmt\ClassMethod $method */
            $fqcn = $this->typeToModel($method->returnType);
            if ($fqcn !== null) {
                $types[$method->name->toString()] = $fqcn;
            }
        }

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private function buildPropertyTypes(Node $classLike): array
    {
        $types = [];

        foreach ($this->finder->findInstanceOf($classLike, Stmt\Property::class) as $property) {
            /** @var Stmt\Property $property */
            $fqcn = $this->typeToModel($property->type);
            if ($fqcn === null) {
                continue;
            }
            foreach ($property->props as $prop) {
                $types[$prop->name->toString()] = $fqcn;
            }
        }

        foreach ($this->finder->findInstanceOf($classLike, Node\Param::class) as $param) {
            /** @var Node\Param $param */
            if ($param->flags === 0) {
                continue; // not a promoted constructor property
            }
            $fqcn = $this->typeToModel($param->type);
            if ($fqcn !== null && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $types[$param->var->name] = $fqcn;
            }
        }

        return $types;
    }

    /**
     * Variable => model FQCN bindings inside one function body.
     *
     * @return array<string, string>
     */
    private function buildVarTypes(Node $fn): array
    {
        $types = [];

        foreach ($this->finder->findInstanceOf($fn, Node\Param::class) as $param) {
            /** @var Node\Param $param */
            $fqcn = $this->typeToModel($param->type);
            if ($fqcn !== null && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $types[$param->var->name] = $fqcn;
            }
        }

        // `/** @var StockLevel|null $stockLevel */` hints — the house idiom for
        // narrowing a builder result (see SupplierCreditNotePostingService).
        foreach ($this->finder->find($fn, static fn (Node $n): bool => $n->getComments() !== []) as $node) {
            foreach ($node->getComments() as $comment) {
                if (preg_match_all('/@var\s+([^\s]+)\s+\$(\w+)/', $comment->getText(), $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }
                foreach ($matches as $match) {
                    foreach (explode('|', $match[1]) as $candidate) {
                        $candidate = trim($candidate, '?\\ ');
                        if ($candidate === '' || strtolower($candidate) === 'null') {
                            continue;
                        }
                        $fqcn = $this->resolveNameWith(new Node\Name($candidate), $this->namespace, $this->useMap);
                        if ($this->tableForModel($fqcn) !== null) {
                            $types[$match[2]] = $fqcn;
                        }
                    }
                }
            }
        }

        // Two passes: an assignment may reference a variable typed by a later
        // pass (e.g. `$b = $a;` before `$a` is seen in source order is not
        // possible, but chained aliasing is — two passes settle it cheaply).
        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($this->finder->findInstanceOf($fn, Expr\Assign::class) as $assign) {
                /** @var Expr\Assign $assign */
                if (! $assign->var instanceof Expr\Variable || ! is_string($assign->var->name)) {
                    continue;
                }
                $fqcn = $this->expressionModel($assign->expr, $types);
                if ($fqcn !== null) {
                    $types[$assign->var->name] = $fqcn;
                }
            }

            foreach ($this->finder->findInstanceOf($fn, Stmt\Foreach_::class) as $foreach) {
                /** @var Stmt\Foreach_ $foreach */
                if (! $foreach->valueVar instanceof Expr\Variable || ! is_string($foreach->valueVar->name)) {
                    continue;
                }
                $fqcn = $this->expressionModel($foreach->expr, $types);
                if ($fqcn !== null) {
                    $types[$foreach->valueVar->name] = $fqcn;
                }
            }
        }

        return $types;
    }

    /**
     * The model an expression yields, when statically knowable.
     *
     * @param  array<string, string>  $known
     */
    private function expressionModel(Expr $expr, array $known): ?string
    {
        $cursor = $expr;
        while ($cursor !== null) {
            if ($cursor instanceof Expr\New_ && $cursor->class instanceof Node\Name) {
                $fqcn = $this->resolveName($cursor->class);

                return $this->tableForModel($fqcn) === null ? null : $fqcn;
            }
            if ($cursor instanceof Expr\StaticCall) {
                if (! $cursor->class instanceof Node\Name) {
                    return null;
                }
                $fqcn = $this->resolveName($cursor->class);

                return $this->tableForModel($fqcn) === null ? null : $fqcn;
            }
            if ($cursor instanceof Expr\MethodCall || $cursor instanceof Expr\NullsafeMethodCall) {
                if ($cursor->name instanceof Node\Identifier) {
                    $relation = $this->relationMap[$cursor->name->toString()] ?? null;
                    if ($relation !== null) {
                        return $relation;
                    }
                    $ownHelper = $this->ownHelperReturn($cursor);
                    if ($ownHelper !== null) {
                        return $ownHelper;
                    }
                }
                $cursor = $cursor->var;

                continue;
            }
            if ($cursor instanceof Expr\Variable && is_string($cursor->name)) {
                return $known[$cursor->name] ?? null;
            }
            if ($cursor instanceof Expr\PropertyFetch && $cursor->name instanceof Node\Identifier) {
                return $this->propertyTypes[$cursor->name->toString()] ?? null;
            }

            return null;
        }

        return null;
    }

    private function typeToModel(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return $this->typeToModel($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $inner) {
                $fqcn = $this->typeToModel($inner);
                if ($fqcn !== null) {
                    return $fqcn;
                }
            }

            return null;
        }
        if (! $type instanceof Node\Name) {
            return null;
        }
        $fqcn = $this->resolveName($type);

        return $this->tableForModel($fqcn) === null ? null : $fqcn;
    }

    private function resolveName(Node\Name $name): string
    {
        return $this->resolveNameWith($name, $this->namespace, $this->useMap);
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private function resolveNameWith(Node\Name $name, string $namespace, array $useMap): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        $parts = $name->getParts();
        $first = $parts[0];

        if (in_array(strtolower($first), ['self', 'static', '$this'], true)) {
            return $this->currentClass;
        }

        if (isset($useMap[$first])) {
            $resolved = $useMap[$first];
            if (count($parts) > 1) {
                $resolved .= '\\'.implode('\\', array_slice($parts, 1));
            }

            return $resolved;
        }

        return $namespace === '' ? $name->toString() : $namespace.'\\'.$name->toString();
    }

    private function stringValue(Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Scalar\InterpolatedString) {
            $out = '';
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\InterpolatedStringPart) {
                    $out .= $part->value;
                }
            }

            return $out;
        }
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = $this->stringValue($expr->left);
            $right = $this->stringValue($expr->right);
            if ($left === null && $right === null) {
                return null;
            }

            return ($left ?? '').($right ?? '');
        }

        return null;
    }
}
