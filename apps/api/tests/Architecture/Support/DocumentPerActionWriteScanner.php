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
 * The engine is fail-closed ON EVERY SITE IT SEES: once a write site is
 * identified, an unresolvable payload is reported as a violation rather than
 * waved through. It is NOT, and cannot be, fail-closed about sites it cannot
 * see at all — see KNOWN BLIND SPOTS at the bottom of this docblock, which is
 * the honest list M2's baseline cross-check inherits. Sites the contract does
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
 *             updateOrCreate/updateOrInsert/insert/insertGetId/insertOrIgnore/
 *             insertUsing/upsert, DB::table() insert/upsert, raw INSERT
 *   MUTATE  — update/updateQuietly/save/saveQuietly/saveMany/
 *             push/increment/decrement/incrementEach/decrementEach/restore,
 *             DB::table()->update/increment/decrement, raw UPDATE
 *   DELETE  — delete/deleteQuietly/forceDelete/destroy/truncate,
 *             DB::table()->delete/truncate, raw DELETE
 *
 * 1. `journal_entries` — reference columns `source_type` + `source_id`
 *    (`JournalEntry::$fillable`; note `journal_entries(source_type,source_id)`
 *    is NOT globally unique, so the check is PRESENCE, never uniqueness).
 *    - CREATE : LINKED iff the payload statically carries BOTH `source_type`
 *               and `source_id` with values that are not STATICALLY KNOWN to
 *               admit null (see nullAdmitting(): a literal null, a nullable or
 *               null-defaulted parameter, a local assigned null, a nullable
 *               property or nullable-returning method on `$this`, a nullsafe
 *               read, or an array element). Values the scanner cannot decide
 *               are treated as non-null — blind spot E, stated below.
 *               Unresolvable payload (variable, spread, dynamic key) =>
 *               VIOLATION (fail closed).
 *    - DELETE : always VIOLATION. A posted journal entry is removed by a
 *               reversal document, never by a row delete (DPA lane V1).
 *    - MUTATE : VIOLATION when the payload is UNREADABLE (`update($vars)` could
 *               set the linkage columns to anything — fail closed; `save()` is
 *               excluded because it never carries an inspectable payload and is
 *               covered by the property-assignment erasure rule instead), when
 *               the payload sets either linkage column to a null-admitting value
 *               (linkage erasure), or when the mechanism is increment/decrement
 *               (numeric mutation of a fiscal row). The property-assignment
 *               erasure path (`$entry->source_id = …; $entry->save();`) is
 *               `$this`-SCOPED on the value side: it refuses the shapes
 *               nullAdmitting() can decide, so an erasure through a
 *               COLLABORATOR's nullable call (`$helper->maybe()`) is not caught
 *               — blind spot E again, stated here so the rule is not read as
 *               general. The erasure rule covers BOTH routes to a stripped
 *               reference: direct property assignment
 *               (`$e->source_id = null;`) and mass assignment
 *               (`fill()`/`forceFill()`/`setRawAttributes()` with a
 *               null-admitting linkage key OR an unreadable payload, and
 *               `setAttribute('source_id', null)`).
 *               Otherwise NOT IN CONTRACT: `journal_entries` carries no
 *               monetary amount (debit/credit live on `journal_entry_lines`,
 *               outside this package's four-table contract), so a lifecycle
 *               update (status, posted_at, reversal_*) mutates a row whose
 *               justification was fixed at creation. Recorded scope boundary,
 *               not an oversight. STATED PLAINLY so nobody mistakes it for
 *               more than it is: this exemption also covers rewrites of
 *               `fiscal_hash`, `previous_hash`, `chain_sequence`, `entry_date`
 *               and `journal_code`. This guard is a DOCUMENT-justification
 *               guard, NOT hash-chain coverage; chain integrity is enforced
 *               elsewhere and is out of P1's scope.
 *
 * 2. `stock_movements` — reference columns `reference_type` + `reference_id`,
 *    PAIRED (the S0 seam: StockAdjustmentService::recordMovement asserts
 *    `assertReferenceLinkagePaired`).
 *    - CREATE : LINKED iff the payload statically carries BOTH `reference_type`
 *               and `reference_id` with values not statically known to admit
 *               null (same predicate as rule 1); unresolvable => VIOLATION. Note the consequence, which is deliberate: the S0
 *               chokepoint's own create (`recordMovement`, whose reference
 *               parameters are `?…= null` and whose `assertReferenceLinkagePaired`
 *               explicitly permits null/null) is a BASELINE ENTRY, not a
 *               linked site. Crediting it would make this table's coverage
 *               near-vacuous, since nearly every movement flows through it.
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
 *        (a) a call that reaches the movement CHOKEPOINT itself — either
 *            `$this->recordMovement(...)` inside StockAdjustmentService, or one
 *            of that class's movement-recording entry points (derived from the
 *            AST by transitive closure over `$this->` calls, never hardcoded)
 *            invoked through a receiver DECLARED as that class; or
 *        (b) a CREATE-class write to `stock_movements` that is ITSELF classified
 *            `linked` — pairing a level write with an unlinked movement
 *            justifies nothing.
 *      Every other write site bypasses the chokepoint => VIOLATION. A local
 *      method merely NAMED `recordMovement` credits nothing (fixture-pinned).
 *    This is deliberately NOT a bypass allowlist: the one class named here is
 *    the architectural chokepoint itself, and satisfying the predicate requires
 *    the code at the site to actually reach it.
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
 * ---------------------------------------------------------------------------
 * KNOWN BLIND SPOTS (read before trusting a clean run)
 * ---------------------------------------------------------------------------
 *
 * A. RECEIVER RESOLUTION IS NOT TOTAL. A write is only classified once the
 *    scanner knows the receiver's table. It resolves: static calls on the four
 *    models (and any class extending one of them), `$this` inside those models,
 *    typed parameters and typed/promoted properties, `@var` docblock hints,
 *    variables assigned from any of the above, `foreach` values, relation
 *    methods declared with `hasMany`/`hasOne`/`morphMany`/`morphOne` on a target
 *    model, `$this->helper()` return types, `$this->collaborator->method()` /
 *    `$typedParam->method()` return types indexed tree-wide, `DB::table('…')`
 *    (static and via `connection()`), and raw SQL naming a target table. It does
 *    NOT resolve a receiver whose origin is untyped, `mixed`, an array element,
 *    a container `make()`, a closure parameter without a type, or a return type
 *    declared only in a docblock. Such a write emits NO SITE — it is invisible,
 *    not baselined. Adding a resolution path is the way to shrink this list;
 *    every path above was added because a real write was found hiding behind it.
 * B. THE PAIRING PREDICATE IS FUNCTION-SCOPED, not flow-sensitive. A movement
 *    recorded anywhere in the same function credits every level write in it,
 *    including one inside an unrelated `if` branch, and regardless of order
 *    (deliberate: both orders are legitimate inside one transaction). A function
 *    that records one movement and writes two unrelated levels is credited for
 *    both. FOR CLASS-LESS FILES THE SCOPE IS THE WHOLE FILE: top-level code is
 *    scanned as one synthetic `(top-level)` scope, so a movement in one route
 *    closure credits a level write in a DIFFERENT closure of the same file.
 *    That is a real widening of this limitation, and it is why the top-level
 *    fixtures keep their linked counterpart in a separate file.
 * C. BASELINE KEYS CARRY A POSITIONAL ORDINAL within (file, class, function,
 *    table, mechanism). Inserting a second same-mechanism write earlier in the
 *    same function renumbers the later ones, so the ratchet reports one `stale`
 *    plus one `new` instead of a clean addition. It fails SAFE (still red), but
 *    the message misleads; read the file:line in the report, not just the key.
 *    ⚠️ Since M2 added the anti-growth direction this is no longer merely
 *    noisy: a renumbered key reads as an ADDED key, which only an OWNER re-pin
 *    can authorise, so a legitimate remediation can produce a red that NO
 *    contributor-side edit clears. See the RE-PIN TRIGGER block in
 *    DocumentPerActionBaselineRatchetTest's docblock.
 *    Methods of a nested anonymous class are attributed to that anonymous class
 *    only (they used to be double-counted onto the enclosing class as well).
 *    Anonymous classes are numbered `(anonymous#N)` in FILE order, which is a
 *    second churn axis on the same footing: inserting an anonymous class
 *    earlier in a file renumbers every later one.
 * D. RAW SQL is matched by table name plus an INSERT/UPDATE/DELETE keyword in a
 *    statically-resolvable string, and only when the receiver is the `DB` FACADE
 *    (`DB::statement(...)`, `DB::update(...)`, `DB::connection(...)->update(...)`).
 *    SQL assembled from variables is not matched. Nor is raw SQL executed through
 *    an INJECTED connection — `$this->db->update('UPDATE …')`, where `$db` is a
 *    constructor-injected `ConnectionInterface`. That shape is live in the tree
 *    as of the 2026-08-20 rebase (`DeliveryNoteBillingClaimService`), though it
 *    targets `documents`/`delivery_note_billing_marks` and NOT one of the four
 *    contract tables, so it changes no classification today. Named here because
 *    an injected-connection raw write against a contract table WOULD be
 *    invisible; closing it is a scanner change and therefore a re-seed event,
 *    deliberately not taken during the final gate.
 * D2. `upsert()` can never classify as LINKED: its argument 0 is a LIST of row
 *    arrays and argument 1 is a positional column list, so payload extraction
 *    always resolves to `false` and the site fails closed to `violation` even
 *    when linkage is complete. Direction is safe; there are zero `upsert` calls
 *    on the four tables today. If one ever lands, expect a false positive and
 *    teach extractPayload() the list-of-rows shape rather than relaxing it.
 * E. LINKAGE-VALUE NULLABILITY IS REFUSED, NOT PROVEN. nullAdmitting() refuses
 *    an ENUMERATED set of shapes (literal null, nullable/null-defaulted
 *    parameter, null-assigned local, nullable `$this` property or
 *    nullable-returning `$this` method, nullsafe read, array element). Every
 *    other value — most importantly `$document->id` and any call on a
 *    collaborator — is treated as non-null WITHOUT proof. A writer that obtains
 *    its reference id from a nullable source the scanner cannot see (an
 *    untyped member, a nullable return on another class, a container call) is
 *    credited as `linked`. Inverting this default would flag the ordinary
 *    `$document->id` shape and drown the baseline; the residue is accepted and
 *    named here instead of being claimed away.
 * F. PAIRING ARM ASYMMETRY. Arm (b) (a `stock_movements` create in the same
 *    function) is credited only when that create is ITSELF `linked`. Arm (a)
 *    (reaching the chokepoint) is credited regardless of whether the movement
 *    the chokepoint writes carries a reference — and the chokepoint's own
 *    create is a baseline entry precisely because it permits null/null. So a
 *    `linked` classification on a level write means "traceable to a movement",
 *    NOT "a justifying document exists". Read it that way in the baseline
 *    cross-check.
 * G. THE SCAN ROOT IS `app/` ONLY, and that is a RULING with live consequences.
 *    `database/seeders/**`, `database/migrations/**` and `tests/**` are not
 *    scanned, so real four-table writes there are neither baselined nor
 *    guarded — e.g. `database/seeders/CoffeeShopSeeder.php` (`StockLevel::create`,
 *    and a `JournalEntry::create` with no `source_id`),
 *    `DemoPharmacySeeder.php`, `ParapharmacySeeder.php`,
 *    `StockLevelSeeder.php`. The justification is that seeders and migrations
 *    are provisioning surfaces with no justifying document by construction; the
 *    consequence is that a future DATA-BACKFILL migration writing
 *    `journal_entries` would be outside this guard by construction. Raised as a
 *    parent ticket, not decided here.
 * H. A BASELINE KEY IS A SLOT, NOT A WRITE. The key identifies
 *    (file, class, function, table, mechanism, ordinal) — not the write's
 *    content. So REMOVING a baselined violation and ADDING a different unlinked
 *    write in the same bucket of the same function is CI-green: the key set is
 *    unchanged, so growth, stale and anti-growth all pass, while the tree now
 *    contains a violation nobody reviewed. The ratchet counts slots; only code
 *    review sees which write occupies one. This is the sharpest limit of the
 *    key-set design and it is named here rather than left implicit.
 *
 * A per-site key is line-number-free and stable under reformatting:
 *   `<relative file>::<class>::<function>::<table>::<mechanism>#<ordinal>`
 * where <ordinal> disambiguates repeated identical mechanisms inside one
 * function (1-based, in source order).
 */
final class DocumentPerActionWriteScanner
{
    /**
     * Does a LINKED (or not-in-contract) form of this cell exist BY RULE?
     *
     * The liveness certificate uses this to decide which cells must pin a
     * negative control, so that knowledge lives HERE, next to the rules it
     * describes, instead of being a hardcoded map inside the test that could be
     * edited to drop a requirement.
     *
     * False for: raw SQL against any table (never credited), and every MUTATE or
     * DELETE against the append-only movement ledger; plus delete/increment/
     * decrement on `journal_entries`, which are violations by rule.
     */
    public static function linkedFormExists(string $table, string $mechanism): bool
    {
        if ($mechanism === 'raw_sql') {
            return false;
        }

        if ($table === 'stock_movements') {
            return in_array($mechanism, ['create', 'firstOrCreate', 'updateOrCreate', 'query_builder'], true);
        }

        if ($table === 'journal_entries') {
            return ! in_array($mechanism, ['delete', 'increment', 'decrement'], true);
        }

        return true;
    }

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
     * The POSITIVE allowlist of columns a level-table MUTATE may touch without
     * a justifying movement. Stated as an allowlist, never as "does not mention
     * `quantity`": a negative test silently exempts a re-keying write such as
     * `update(['variant_id' => …])`, which moves on-hand stock from one grain to
     * another with no movement at all (gate-r1 M1 finding 1).
     *
     * @var list<string>
     */
    private const SOFT_HOLD_COLUMNS = ['reserved', 'reserved_quantity', 'min_quantity', 'max_quantity'];

    /**
     * `inventory_batch_movements` — the join row whose NOT-NULL `movement_id`
     * FK ties a batch-stock change to an aggregate `stock_movements` row.
     */
    private const BATCH_MOVEMENT_MODEL = 'App\\Modules\\BatchExpiry\\Domain\\Entities\\BatchMovement';

    /**
     * The movement chokepoint — the ONE class that owns `recordMovement` and the
     * S0 reference-linkage assertion. The pairing predicate credits a
     * `recordMovement` call only when it is this class calling its own method,
     * or a caller invoking one of this class's movement-recording entry points
     * (derived from the AST, not hardcoded). A bare name match anywhere would be
     * satisfiable by an empty local stub (gate-r1 M1 finding 3).
     */
    private const MOVEMENT_CHOKEPOINT = 'App\\Modules\\Inventory\\Domain\\Services\\StockAdjustmentService';

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
        // Query\Builder::updateOrInsert() INSERTS when no row matches — it is a
        // CREATE-class write, not a MUTATE (M1 gate round 3, finding 2).
        'updateOrInsert' => ['updateOrCreate', 'CREATE'],
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

    /**
     * Repository-wide map of methods that RETURN one of the target models:
     * class FQCN => method name => model FQCN. Without it the scanner misses
     * `$this->stockAdjustmentService->issue(...)->save()` — a service hands back
     * a StockMovement and the caller mutates it (the live
     * ReturnScrapWriteOffService shape).
     *
     * @var array<string, array<string, string>>
     */
    private array $modelReturningMethods = [];

    /** @var array<string, string> property name => declared class FQCN (any class), for the file being scanned */
    private array $propertyClasses = [];

    /** @var array<string, string> variable name => declared class FQCN (any class), for the function being scanned */
    private array $varClasses = [];

    /**
     * Methods of MOVEMENT_CHOKEPOINT whose body calls `$this->recordMovement(...)`,
     * derived from the AST during the pre-pass.
     *
     * @var array<string, true>
     */
    private array $chokepointEntryPoints = [];

    /** @var array<string, true> parameter names of the function being scanned whose declared type is nullable or which default to null */
    private array $nullableVars = [];

    /** @var array<string, true> local variables assigned `null` anywhere in the function being scanned */
    private array $nullAssignedVars = [];

    /** @var array<string, true> properties of the class being scanned whose declared type is nullable */
    private array $nullableProperties = [];

    /** @var array<string, true> methods of the class being scanned whose declared return type is nullable */
    private array $nullableReturnMethods = [];

    /**
     * Subclass FQCN => target-model FQCN. A class extending one of the four
     * models writes the same table, so `$this->update([...])` inside it is the
     * same contract event.
     *
     * @var array<string, string>
     */
    private array $modelSubclasses = [];

    private NodeFinder $finder;

    private Parser $parser;

    /**
     * Parsed ASTs for the lifetime of ONE scan() call, kept ONLY for files that
     * can possibly contribute to a resolution map or a write site (see
     * mayMatter()). The scan makes five passes over the tree — relation map,
     * model-returning methods, chokepoint entry points, model inheritance, then
     * the write census — and without this cache every pass re-parses every file:
     * ~50 s instead of ~10 s, which matters for a static-only CI lane. The
     * prefilter keeps peak memory bounded (caching the whole tree exhausted a
     * 512 MB CLI limit).
     *
     * @var array<string, array<int, Node>|null>
     */
    private array $astCache = [];

    public function __construct()
    {
        $this->finder = new NodeFinder;
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Scan a set of absolute roots and return every write site found.
     *
     * @param  list<string>  $roots  absolute directory (or file) paths whose sites are REPORTED
     * @param  string  $relativeTo  absolute prefix stripped from reported paths
     * @param  list<string>  $contextRoots  extra paths read ONLY to build the
     *                                      resolution maps (relations, model
     *                                      inheritance, return types, chokepoint
     *                                      entry points). Their own write sites
     *                                      are not reported. The fixture suite
     *                                      passes the production tree here so a
     *                                      fixture resolves exactly as live code
     *                                      does.
     * @return list<array{key: string, file: string, line: int, class: string, function: string, table: string, mechanism: string, write_class: string, classification: string, reason: string}>
     */
    public function scan(array $roots, string $relativeTo, array $contextRoots = []): array
    {
        $this->astCache = [];
        $mapRoots = [...$roots, ...$contextRoots];
        $this->relationMap = $this->buildRelationMap($mapRoots);
        $this->modelReturningMethods = $this->buildModelReturningMethods($mapRoots);
        $this->chokepointEntryPoints = $this->buildChokepointEntryPoints($mapRoots);
        $this->modelSubclasses = $this->buildModelSubclasses($mapRoots);

        $sites = [];
        foreach ($this->files($roots) as $path) {
            foreach ($this->scanFile($path, $relativeTo) as $site) {
                $sites[] = $site;
            }
        }

        usort($sites, static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['key']] <=> [$b['file'], $b['line'], $b['key']]);

        $this->astCache = [];

        return $sites;
    }

    /**
     * @return list<array{key: string, file: string, line: int, class: string, function: string, table: string, mechanism: string, write_class: string, classification: string, reason: string}>
     */
    public function scanFile(string $path, string $relativeTo): array
    {
        $stmts = $this->parseFile($path);
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
            $this->propertyClasses = $this->buildPropertyClasses($classLike['node']);
            $this->nullableProperties = $this->buildNullableProperties($classLike['node']);
            $this->nullableReturnMethods = $this->buildNullableReturnMethods($classLike['node']);

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
        $this->propertyClasses = [];
        $this->nullableProperties = [];
        $this->nullableReturnMethods = [];
        foreach ($this->topLevelFunctionLikes($stmts) as $fn) {
            foreach ($this->scanFunction($fn['node'], $relative, $fn['name']) as $site) {
                $sites[] = $site;
            }
        }

        // EVERYTHING ELSE AT FILE LEVEL — bare statements and the closures they
        // carry. This is the whole `routes.php` class of file (50 files under
        // app/ carry top-level statements): `Route::post('/x', function () {
        // JournalEntry::create([...]); })` lives in no class and in no named
        // function, so before this pass it was never opened at all — a fully
        // resolvable static call on a target model that emitted no site (M1
        // gate round 4, finding 1). Class and named-function declarations are
        // excluded here because they are scanned above.
        $topLevel = $this->topLevelStatements($stmts);
        if ($topLevel !== []) {
            $synthetic = new Stmt\Function_(new Node\Identifier('top_level'));
            $synthetic->stmts = $topLevel;

            $declaredFunctions = $this->namedFunctionNodeIds($topLevel);
            foreach ($this->scanFunction($synthetic, $relative, '(top-level)', $declaredFunctions) as $site) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    /**
     * @return list<array{key: string, file: string, line: int, class: string, function: string, table: string, mechanism: string, write_class: string, classification: string, reason: string}>
     */
    /**
     * @param  array<int, true>  $additionalExclusions  node ids that belong to a scope scanned elsewhere
     * @return list<array{key: string, file: string, line: int, class: string, function: string, table: string, mechanism: string, write_class: string, classification: string, reason: string}>
     */
    private function scanFunction(Node $fn, string $relativeFile, string $functionName, array $additionalExclusions = []): array
    {
        // Nodes belonging to a nested class-like (an anonymous class declared in
        // this body) are that class's, not this scope's. Excluding them stops
        // one physical write emitting two keys — round 3 removed the third key
        // by filtering the METHOD list, but the finder still walked into the
        // body from the enclosing scope (M1 gate round 4, finding 3).
        $nestedNodes = $this->nestedClassLikeNodeIds($fn) + $additionalExclusions;
        $outside = static fn (Node $n): bool => ! isset($nestedNodes[spl_object_id($n)]);

        $this->varTypes = $this->buildVarTypes($fn);

        $pairing = $this->functionRecordsMovement($fn);
        $erasesLinkage = $this->functionErasesLinkage($fn);

        /** @var list<array{node: Node, table: string, mechanism: string, write_class: string, payload: array{resolved: bool, keys: array<string, bool>}}> $raw */
        $raw = [];

        foreach ($this->finder->find($fn, static fn (Node $n): bool => ($n instanceof Expr\MethodCall
            || $n instanceof Expr\NullsafeMethodCall
            || $n instanceof Expr\StaticCall) && $outside($n)) as $node) {
            $hit = $this->classifyCall($node);
            if ($hit !== null) {
                $raw[] = $hit;
            }
        }

        foreach ($this->finder->find($fn, static fn (Node $n): bool => ($n instanceof Expr\StaticCall || $n instanceof Expr\MethodCall) && $outside($n)) as $node) {
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
     * Every node inside a class-like nested in this scope, by object id.
     *
     * @return array<int, true>
     */
    private function nestedClassLikeNodeIds(Node $fn): array
    {
        $ids = [];

        foreach ($this->finder->findInstanceOf($fn, Stmt\ClassLike::class) as $inner) {
            foreach ($this->finder->find($inner, static fn (Node $n): bool => true) as $node) {
                $ids[spl_object_id($node)] = true;
            }
            $ids[spl_object_id($inner)] = true;
        }

        return $ids;
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
                && array_diff(array_keys($payload['keys']), self::SOFT_HOLD_COLUMNS) === []) {
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

        if (! $payload['resolved'] && $mechanism !== 'save') {
            // An update()/upsert() whose payload the scanner cannot read may set
            // the linkage columns to anything, including null. Fail closed —
            // `update($vars)` must not be the way around the erasure rule.
            // `save()` is excluded because it never carries an inspectable
            // payload by construction; its erasure path is the property-assignment
            // rule below.
            return ['violation', sprintf('%s with an unreadable payload — cannot prove %s/%s linkage survives (fail closed)', $mechanism, $typeColumn, $idColumn)];
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
        while (true) {
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
                // `$this->update([...])` / `$this->save()` INSIDE one of the four
                // models is a write to that model's own table (finding 2).
                if ($cursor->name === 'this') {
                    return $this->tableForModel($this->currentClass);
                }
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

        $inherited = $this->modelSubclasses[$fqcn] ?? null;
        if ($inherited !== null) {
            return array_search($inherited, self::TABLE_MODELS, true) ?: null;
        }

        return null;
    }

    /**
     * Transitive `extends` closure onto the four models.
     *
     * @param  list<string>  $roots
     * @return array<string, string>
     */
    private function buildModelSubclasses(array $roots): array
    {
        $parents = [];

        foreach ($this->files($roots) as $path) {
            $code = (string) file_get_contents($path);
            if (! str_contains($code, 'extends')) {
                continue;
            }
            $stmts = $this->parseFile($path);
            if ($stmts === null) {
                continue;
            }
            $namespace = $this->firstNamespace($stmts);
            $useMap = $this->buildUseMap($stmts);

            foreach ($this->finder->findInstanceOf($stmts, Stmt\Class_::class) as $class) {
                /** @var Stmt\Class_ $class */
                if ($class->name === null || $class->extends === null) {
                    continue;
                }
                $fqcn = $namespace === '' ? $class->name->toString() : $namespace.'\\'.$class->name->toString();
                $parents[$fqcn] = $this->resolveNameWith($class->extends, $namespace, $useMap);
            }
        }

        $models = array_values(self::TABLE_MODELS);
        $map = [];
        foreach ($parents as $child => $parent) {
            $seen = [];
            $cursor = $parent;
            while ($cursor !== null && ! isset($seen[$cursor])) {
                if (in_array($cursor, $models, true)) {
                    $map[$child] = $cursor;
                    break;
                }
                $seen[$cursor] = true;
                $cursor = $parents[$cursor] ?? null;
            }
        }

        return $map;
    }

    /**
     * @return array{resolved: bool, keys: array<string, bool>} key => value is non-null
     */
    private function extractPayload(Expr\CallLike $node, string $method): array
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
                    $key = $item->key === null ? null : $this->stringValue($item->key);
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
                if ($item->key === null || $item->unpack) {
                    $resolved = false;

                    continue;
                }
                $key = $this->stringValue($item->key);
                if ($key === null) {
                    $resolved = false;

                    continue;
                }
                // LATER argument wins, matching Laravel's own merge order:
                // firstOrCreate/updateOrCreate/updateOrInsert create the row via
                // array_merge($attributes, $values), so a linkage key nulled in
                // $values overrides the same key set in $attributes (M1 gate
                // round 4, finding 4).
                $keys[$key] = $this->provablyNonNull($item->value);
            }
        }

        if (! $seenArray) {
            $resolved = false;
        }

        return ['resolved' => $resolved, 'keys' => $keys];
    }

    /**
     * Is this value expression PROVABLY non-null at the write site?
     *
     * A literal `null` obviously is not. Neither is `$referenceType?->value`
     * (nullsafe short-circuits to null) nor a variable whose declared parameter
     * type is nullable or whose default is null — which is exactly the S0
     * chokepoint's own shape: `recordMovement(?StockMovementReferenceType
     * $referenceType = null, ?string $referenceId = null)` writes
     * `'reference_type' => $referenceType?->value` and
     * `assertReferenceLinkagePaired` explicitly PERMITS null/null. Crediting
     * that as linkage made the guard near-vacuous on the one path almost every
     * movement flows through (finding 4); the chokepoint now enters the
     * baseline as a deliberate, visible entry.
     */
    private function provablyNonNull(Expr $value): bool
    {
        return ! $this->nullAdmitting($value);
    }

    /**
     * Is this value expression STATICALLY KNOWN to admit null?
     *
     * The enumerated null-admitting shapes are refused; anything the scanner
     * cannot decide is treated as non-null (blind spot E in the header — this
     * is stated, not claimed away). Refused shapes:
     *   - the literal `null`;
     *   - a nullsafe read (`$enum?->value`) — it short-circuits to null;
     *   - a parameter declared nullable or defaulted to null;
     *   - a LOCAL assigned `null` anywhere in the same function;
     *   - `$this-><prop>` whose declared property type is nullable;
     *   - `$this-><method>()` whose declared return type is nullable;
     *   - an array element (`$ctx['id']`) — never statically decidable;
     *   - a ternary either of whose branches admits null;
     *   - `??` whose right-hand side admits null.
     */
    private function nullAdmitting(Expr $value): bool
    {
        if ($value instanceof Expr\ConstFetch && $value->name->toLowerString() === 'null') {
            return true;
        }
        if ($value instanceof Expr\NullsafePropertyFetch || $value instanceof Expr\NullsafeMethodCall) {
            return true;
        }
        if ($value instanceof Expr\ArrayDimFetch) {
            return true;
        }
        if ($value instanceof Expr\Variable && is_string($value->name)) {
            return isset($this->nullableVars[$value->name]) || isset($this->nullAssignedVars[$value->name]);
        }
        if ($value instanceof Expr\PropertyFetch
            && $value->var instanceof Expr\Variable
            && $value->var->name === 'this'
            && $value->name instanceof Node\Identifier) {
            return isset($this->nullableProperties[$value->name->toString()]);
        }
        if ($value instanceof Expr\MethodCall
            && $value->var instanceof Expr\Variable
            && $value->var->name === 'this'
            && $value->name instanceof Node\Identifier) {
            return isset($this->nullableReturnMethods[$value->name->toString()]);
        }
        if ($value instanceof Expr\Ternary) {
            $ifTrue = $value->if ?? $value->cond;

            return $this->nullAdmitting($ifTrue) || $this->nullAdmitting($value->else);
        }
        if ($value instanceof Expr\BinaryOp\Coalesce) {
            return $this->nullAdmitting($value->right);
        }

        return false;
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
            if ($this->isChokepointMovementCall($call)) {
                $result['movement'] = true;

                continue;
            }
            $hit = $this->classifyCall($call);
            if ($hit !== null && $hit['table'] === 'stock_movements' && $hit['write_class'] === 'CREATE') {
                // Arm (b) credits ONLY a movement create that is itself LINKED:
                // pairing a level write with an UNLINKED movement justifies
                // nothing (finding 3).
                [$movementClass] = $this->classifySite(
                    'stock_movements',
                    'CREATE',
                    $hit['mechanism'],
                    $hit['payload'],
                    ['movement' => false, 'batch_movement' => false],
                    false,
                );
                if ($movementClass === 'linked') {
                    $result['movement'] = true;
                }

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
            if (! $this->nullAdmitting($assign->expr)) {
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

        // MASS-ASSIGNMENT routes to the same erasure. `save()` is exempted from
        // the unreadable-payload rule on the stated grounds that its erasure
        // path is covered here, so `fill()` / `forceFill()` / `setAttribute()`
        // must be covered too or that exemption is false (M1 gate round 4,
        // finding 2).
        foreach ($this->finder->find($fn, static fn (Node $n): bool => $n instanceof Expr\MethodCall
            || $n instanceof Expr\NullsafeMethodCall) as $call) {
            /** @var Expr\MethodCall|Expr\NullsafeMethodCall $call */
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }
            $method = $call->name->toString();
            if (! in_array($method, ['fill', 'forceFill', 'setRawAttributes', 'setAttribute'], true)) {
                continue;
            }

            $receiver = $this->expressionModel($call->var, $this->varTypes);
            if ($receiver === null && $call->var instanceof Expr\Variable && $call->var->name === 'this') {
                $receiver = $this->currentClass;
            }
            $table = $receiver === null ? null : $this->tableForModel($receiver);
            if ($table === null) {
                continue;
            }
            $columns = self::TABLE_REFERENCE_COLUMNS[$table] ?? null;
            if ($columns === null) {
                continue;
            }

            $args = $call->getArgs();

            if ($method === 'setAttribute') {
                if (count($args) < 2) {
                    continue;
                }
                $column = $this->stringValue($args[0]->value);
                if ($column === null || ! in_array($column, $columns, true)) {
                    continue;
                }
                if ($this->nullAdmitting($args[1]->value)) {
                    $out[$table] = true;
                }

                continue;
            }

            // fill() / forceFill() / setRawAttributes(): an unreadable payload
            // cannot be shown to preserve linkage — fail closed, same standard
            // as update().
            if ($args === [] || ! $args[0]->value instanceof Expr\Array_) {
                $out[$table] = true;

                continue;
            }

            foreach ($args[0]->value->items as $item) {
                if ($item->key === null || $item->unpack) {
                    $out[$table] = true;

                    continue;
                }
                $key = $this->stringValue($item->key);
                if ($key === null) {
                    $out[$table] = true;

                    continue;
                }
                if (in_array($key, $columns, true) && $this->nullAdmitting($item->value)) {
                    $out[$table] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Arm (a) of the pairing predicate, bound to the real chokepoint:
     *   - `$this->recordMovement(...)` INSIDE StockAdjustmentService, or
     *   - `<receiver declared as StockAdjustmentService>->entryPoint(...)` where
     *     entryPoint is a method of that class whose own body calls
     *     `$this->recordMovement(...)` (derived from the AST at scan time by
     *     transitive closure, so it cannot rot into a hardcoded list).
     * An empty local `recordMovement()` stub credits nothing.
     */
    private function isChokepointMovementCall(Node $call): bool
    {
        if (! $call instanceof Expr\MethodCall && ! $call instanceof Expr\NullsafeMethodCall) {
            return false;
        }
        if (! $call->name instanceof Node\Identifier) {
            return false;
        }
        $method = $call->name->toString();

        if ($call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return $method === 'recordMovement' && $this->currentClass === self::MOVEMENT_CHOKEPOINT;
        }

        $receiverClass = null;
        if ($call->var instanceof Expr\PropertyFetch
            && $call->var->var instanceof Expr\Variable
            && $call->var->var->name === 'this'
            && $call->var->name instanceof Node\Identifier) {
            $receiverClass = $this->propertyClasses[$call->var->name->toString()] ?? null;
        } elseif ($call->var instanceof Expr\Variable && is_string($call->var->name)) {
            $receiverClass = $this->varClasses[$call->var->name] ?? null;
        }

        if ($receiverClass !== self::MOVEMENT_CHOKEPOINT) {
            return false;
        }

        return isset($this->chokepointEntryPoints[$method]);
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
     * @return array<int, Node>|null
     */
    private function parseFile(string $path): ?array
    {
        if (array_key_exists($path, $this->astCache)) {
            return $this->astCache[$path];
        }

        $code = (string) file_get_contents($path);
        $stmts = $this->parser->parse($code);

        if ($this->mayMatter($code)) {
            $this->astCache[$path] = $stmts;
        }

        return $stmts;
    }

    /**
     * Cheap textual prefilter: can this file contribute to any resolution map
     * or carry a write to one of the four tables? Files that mention none of
     * the four models, none of the four table names, no relation declaration
     * and no `extends` are parsed once and immediately discarded.
     */
    private function mayMatter(string $code): bool
    {
        foreach (self::TABLE_MODELS as $table => $model) {
            $short = substr((string) strrchr($model, '\\'), 1);
            if (str_contains($code, $short) || str_contains($code, $table)) {
                return true;
            }
        }

        return str_contains($code, 'hasMany')
            || str_contains($code, 'hasOne')
            || str_contains($code, 'morphMany')
            || str_contains($code, 'morphOne')
            || str_contains($code, 'extends');
    }

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
            if (! str_contains($code, 'hasMany') && ! str_contains($code, 'hasOne')
                && ! str_contains($code, 'morphMany') && ! str_contains($code, 'morphOne')) {
                continue;
            }
            $stmts = $this->parseFile($path);
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
     * @param  array<int, Node>  $stmts
     */
    private function firstNamespace(array $stmts): string
    {
        foreach ($this->finder->findInstanceOf($stmts, Stmt\Namespace_::class) as $ns) {
            return $ns->name === null ? '' : $ns->name->toString();
        }

        return '';
    }

    /**
     * @param  array<int, Node>  $stmts
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
     * @param  array<int, Node>  $stmts
     * @return list<array{name: string, node: Node}>
     */
    private function classLikes(array $stmts): array
    {
        $out = [];
        $anonymous = 0;
        foreach ($this->finder->findInstanceOf($stmts, Stmt\ClassLike::class) as $classLike) {
            /** @var Stmt\ClassLike $classLike */
            $name = $classLike->name?->toString();
            if ($name === null) {
                // Two anonymous classes in one file used to produce
                // BYTE-IDENTICAL keys, merging two distinct violations into one
                // baseline entry — fail-open, in the direction the ratchet does
                // not police (M1 gate round 4, finding 3). Number them in file
                // order instead.
                $anonymous++;
                $name = sprintf('(anonymous#%d)', $anonymous);
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
        // Methods declared inside a NESTED class-like (an anonymous class in a
        // method body) belong to that class, not to this one. Without this
        // filter one physical write emits three keys — the anon class's own,
        // plus two bogus ones on the outer class — and a single remediation
        // would strand three baseline entries as stale (M1 gate round 3,
        // finding 4).
        $nested = [];
        foreach ($this->finder->findInstanceOf($classLike, Stmt\ClassLike::class) as $inner) {
            if ($inner === $classLike) {
                continue;
            }
            foreach ($this->finder->findInstanceOf($inner, Stmt\ClassMethod::class) as $innerMethod) {
                $nested[spl_object_id($innerMethod)] = true;
            }
        }

        $out = [];
        foreach ($this->finder->findInstanceOf($classLike, Stmt\ClassMethod::class) as $method) {
            /** @var Stmt\ClassMethod $method */
            if (isset($nested[spl_object_id($method)])) {
                continue;
            }
            $out[] = ['name' => $method->name->toString(), 'node' => $method];
        }

        return $out;
    }

    /**
     * File-level statements, namespaces unwrapped, with class-like and named
     * function DECLARATIONS removed (they are scanned in their own scopes).
     * What remains is the executable body of a route/bootstrap/helper file.
     *
     * @param  array<int, Node>  $stmts
     * @return list<Stmt>
     */
    private function topLevelStatements(array $stmts): array
    {
        $out = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $out = [...$out, ...$this->topLevelStatements($stmt->stmts)];

                continue;
            }
            if ($stmt instanceof Stmt\ClassLike || $stmt instanceof Stmt\Function_) {
                continue;
            }
            if ($stmt instanceof Stmt\Use_ || $stmt instanceof Stmt\GroupUse || $stmt instanceof Stmt\Declare_) {
                continue;
            }
            if ($stmt instanceof Stmt) {
                $out[] = $stmt;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, Node>  $stmts
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
     * Every node inside a named function declaration, by object id. The
     * top-level pass must skip them: `topLevelFunctionLikes()` finds
     * `Stmt\Function_` at ANY depth, so a function declared inside a top-level
     * `if` would otherwise be scanned twice — once in its own scope and once as
     * part of the synthetic `(top-level)` body (M1 gate round 5, note 4).
     *
     * @param  list<Stmt>  $stmts
     * @return array<int, true>
     */
    private function namedFunctionNodeIds(array $stmts): array
    {
        $ids = [];

        foreach ($this->finder->findInstanceOf($stmts, Stmt\Function_::class) as $function) {
            foreach ($this->finder->find($function, static fn (Node $n): bool => true) as $node) {
                $ids[spl_object_id($node)] = true;
            }
            $ids[spl_object_id($function)] = true;
        }

        return $ids;
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
        $method = $call->name->toString();

        // (a) `$this->helper()` — same class.
        if ($call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return $this->methodReturnTypes[$method] ?? null;
        }

        // (b) `$this->someService->issue()` / `$service->issue()` — a collaborator
        //     whose declared class has a method returning one of the four models.
        $receiverClass = null;
        if ($call->var instanceof Expr\PropertyFetch
            && $call->var->var instanceof Expr\Variable
            && $call->var->var->name === 'this'
            && $call->var->name instanceof Node\Identifier) {
            $receiverClass = $this->propertyClasses[$call->var->name->toString()] ?? null;
        } elseif ($call->var instanceof Expr\Variable && is_string($call->var->name)) {
            $receiverClass = $this->varClasses[$call->var->name] ?? null;
        }

        if ($receiverClass === null) {
            return null;
        }

        return $this->modelReturningMethods[$receiverClass][$method] ?? null;
    }

    /**
     * The chokepoint's own movement-recording entry points: every method of
     * MOVEMENT_CHOKEPOINT whose body contains a `$this->recordMovement(...)`
     * call. Derived, never hardcoded.
     *
     * @param  list<string>  $roots
     * @return array<string, true>
     */
    private function buildChokepointEntryPoints(array $roots): array
    {
        $short = substr((string) strrchr(self::MOVEMENT_CHOKEPOINT, '\\'), 1);
        $entryPoints = [];

        foreach ($this->files($roots) as $path) {
            if (basename($path) !== $short.'.php') {
                continue;
            }
            $stmts = $this->parseFile($path);
            if ($stmts === null) {
                continue;
            }
            $namespace = $this->firstNamespace($stmts);

            foreach ($this->finder->findInstanceOf($stmts, Stmt\ClassLike::class) as $classLike) {
                /** @var Stmt\ClassLike $classLike */
                $name = $classLike->name?->toString();
                if ($name === null) {
                    continue;
                }
                if (($namespace === '' ? $name : $namespace.'\\'.$name) !== self::MOVEMENT_CHOKEPOINT) {
                    continue;
                }
                $direct = [];
                $selfCalls = [];

                foreach ($this->finder->findInstanceOf($classLike, Stmt\ClassMethod::class) as $method) {
                    /** @var Stmt\ClassMethod $method */
                    $name = $method->name->toString();
                    foreach ($this->finder->find($method, static fn (Node $n): bool => $n instanceof Expr\MethodCall) as $call) {
                        /** @var Expr\MethodCall $call */
                        if (! $call->name instanceof Node\Identifier
                            || ! $call->var instanceof Expr\Variable
                            || $call->var->name !== 'this') {
                            continue;
                        }
                        $callee = $call->name->toString();
                        if ($callee === 'recordMovement') {
                            $direct[$name] = true;
                        }
                        $selfCalls[$name][$callee] = true;
                    }
                }

                // Transitive closure INSIDE the chokepoint: `adjust()` records a
                // movement through `postAdjustmentWithinLock()`, so it is just as
                // much an entry point as `issue()`.
                $entryPoints += $direct;
                do {
                    $grew = false;
                    foreach ($selfCalls as $caller => $callees) {
                        if (isset($entryPoints[$caller])) {
                            continue;
                        }
                        foreach (array_keys($callees) as $callee) {
                            if (isset($entryPoints[$callee])) {
                                $entryPoints[$caller] = true;
                                $grew = true;
                                break;
                            }
                        }
                    }
                } while ($grew);
            }
        }

        return $entryPoints;
    }

    /**
     * class FQCN => method => target-model FQCN, over the whole scanned tree.
     *
     * @param  list<string>  $roots
     * @return array<string, array<string, string>>
     */
    private function buildModelReturningMethods(array $roots): array
    {
        $map = [];

        foreach ($this->files($roots) as $path) {
            $stmts = $this->parseFile($path);
            if ($stmts === null) {
                continue;
            }
            $namespace = $this->firstNamespace($stmts);
            $useMap = $this->buildUseMap($stmts);

            foreach ($this->finder->findInstanceOf($stmts, Stmt\ClassLike::class) as $classLike) {
                /** @var Stmt\ClassLike $classLike */
                $name = $classLike->name?->toString();
                if ($name === null) {
                    continue;
                }
                $fqcn = $namespace === '' ? $name : $namespace.'\\'.$name;

                foreach ($this->finder->findInstanceOf($classLike, Stmt\ClassMethod::class) as $method) {
                    /** @var Stmt\ClassMethod $method */
                    $returns = $this->typeToModelWith($method->returnType, $namespace, $useMap);
                    if ($returns !== null) {
                        $map[$fqcn][$method->name->toString()] = $returns;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Declared class of every property (and promoted constructor property),
     * regardless of whether it is a target model.
     *
     * @return array<string, string>
     */
    private function buildPropertyClasses(Node $classLike): array
    {
        $classes = [];

        foreach ($this->finder->findInstanceOf($classLike, Stmt\Property::class) as $property) {
            /** @var Stmt\Property $property */
            $fqcn = $this->typeToClass($property->type);
            if ($fqcn === null) {
                continue;
            }
            foreach ($property->props as $prop) {
                $classes[$prop->name->toString()] = $fqcn;
            }
        }

        foreach ($this->finder->findInstanceOf($classLike, Node\Param::class) as $param) {
            /** @var Node\Param $param */
            if ($param->flags === 0) {
                continue;
            }
            $fqcn = $this->typeToClass($param->type);
            if ($fqcn !== null && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $classes[$param->var->name] = $fqcn;
            }
        }

        return $classes;
    }

    /**
     * Properties whose declared type admits null.
     *
     * @return array<string, true>
     */
    private function buildNullableProperties(Node $classLike): array
    {
        $out = [];

        foreach ($this->finder->findInstanceOf($classLike, Stmt\Property::class) as $property) {
            /** @var Stmt\Property $property */
            if (! $this->typeAdmitsNull($property->type)) {
                continue;
            }
            foreach ($property->props as $prop) {
                $out[$prop->name->toString()] = true;
            }
        }

        foreach ($this->finder->findInstanceOf($classLike, Node\Param::class) as $param) {
            /** @var Node\Param $param */
            if ($param->flags === 0 || ! $param->var instanceof Expr\Variable || ! is_string($param->var->name)) {
                continue;
            }
            if ($this->typeAdmitsNull($param->type)
                || ($param->default instanceof Expr\ConstFetch && $param->default->name->toLowerString() === 'null')) {
                $out[$param->var->name] = true;
            }
        }

        return $out;
    }

    /**
     * Methods whose declared return type admits null.
     *
     * @return array<string, true>
     */
    private function buildNullableReturnMethods(Node $classLike): array
    {
        $out = [];
        foreach ($this->finder->findInstanceOf($classLike, Stmt\ClassMethod::class) as $method) {
            /** @var Stmt\ClassMethod $method */
            if ($this->typeAdmitsNull($method->returnType)) {
                $out[$method->name->toString()] = true;
            }
        }

        return $out;
    }

    /**
     * A missing type declaration admits null as far as this scanner can tell —
     * but only the DECLARED-nullable shapes are refused (blind spot E), so an
     * untyped member returns false here and is treated as non-null.
     */
    private function typeAdmitsNull(?Node $type): bool
    {
        if ($type instanceof Node\NullableType) {
            return true;
        }
        if ($type instanceof Node\UnionType) {
            return $this->unionAdmitsNull($type);
        }
        if ($type instanceof Node\Identifier && $type->toLowerString() === 'null') {
            return true;
        }

        return false;
    }

    /**
     * The declared class name of a type node, or null for scalars/unions with
     * no single class.
     */
    private function unionAdmitsNull(Node\UnionType $type): bool
    {
        foreach ($type->types as $inner) {
            if ($inner instanceof Node\Identifier && $inner->toLowerString() === 'null') {
                return true;
            }
        }

        return false;
    }

    private function typeToClass(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return $this->typeToClass($type->type);
        }
        if (! $type instanceof Node\Name) {
            return null;
        }
        $name = $type->toString();
        if (in_array(strtolower($name), ['string', 'int', 'float', 'bool', 'array', 'mixed', 'callable', 'iterable', 'object', 'void', 'never', 'null'], true)) {
            return null;
        }

        return $this->resolveName($type);
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

        $this->varClasses = [];
        $this->nullableVars = [];
        $this->nullAssignedVars = [];
        foreach ($this->finder->findInstanceOf($fn, Node\Param::class) as $param) {
            /** @var Node\Param $param */
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $nullable = $param->type instanceof Node\NullableType
                    || ($param->default instanceof Expr\ConstFetch && $param->default->name->toLowerString() === 'null')
                    || ($param->type instanceof Node\UnionType && $this->unionAdmitsNull($param->type));
                if ($nullable) {
                    $this->nullableVars[$param->var->name] = true;
                }
            }
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $declared = $this->typeToClass($param->type);
                if ($declared !== null) {
                    $this->varClasses[$param->var->name] = $declared;
                }
            }
            $fqcn = $this->typeToModel($param->type);
            if ($fqcn !== null && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $types[$param->var->name] = $fqcn;
            }
        }

        // Null-admittance PROPAGATES through local aliases, to a fixpoint:
        // `$id = $this->maybeId();` (nullable return) or `$b = $a;` where `$a`
        // already admits null must not launder the value into "linked" one hop
        // later (M1 gate round 3, finding 1). Bounded iteration — each pass can
        // only add variables, and there are finitely many.
        $assignments = $this->finder->findInstanceOf($fn, Expr\Assign::class);
        for ($pass = 0; $pass < 8; $pass++) {
            $grew = false;
            foreach ($assignments as $assign) {
                /** @var Expr\Assign $assign */
                if (! $assign->var instanceof Expr\Variable || ! is_string($assign->var->name)) {
                    continue;
                }
                $name = $assign->var->name;
                if (isset($this->nullAssignedVars[$name]) || isset($this->nullableVars[$name])) {
                    continue;
                }
                if ($this->nullAdmitting($assign->expr)) {
                    $this->nullAssignedVars[$name] = true;
                    $grew = true;
                }
            }

            // `[$type, $id] = ['X', null];` — a destructure whose source element
            // admits null (M1 gate round 4, finding 5). Only literal element
            // lists are decidable; a destructure from a call is undecidable and
            // falls under blind spot E.
            foreach ($assignments as $assign) {
                /** @var Expr\Assign $assign */
                $target = $assign->var;
                if (! $target instanceof Expr\List_ && ! $target instanceof Expr\Array_) {
                    continue;
                }
                $source = $assign->expr instanceof Expr\Array_ ? $assign->expr : null;
                foreach ($target->items as $index => $item) {
                    if ($item === null || ! $item->value instanceof Expr\Variable || ! is_string($item->value->name)) {
                        continue;
                    }
                    $name = $item->value->name;
                    if (isset($this->nullAssignedVars[$name])) {
                        continue;
                    }
                    $element = $source?->items[$index] ?? null;
                    $admits = $element === null || $this->nullAdmitting($element->value);
                    if ($admits) {
                        $this->nullAssignedVars[$name] = true;
                        $grew = true;
                    }
                }
            }

            if (! $grew) {
                break;
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
        while (true) {
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
                if ($cursor->name === 'this' && $this->tableForModel($this->currentClass) !== null) {
                    return $this->currentClass;
                }

                return $known[$cursor->name] ?? null;
            }
            if ($cursor instanceof Expr\PropertyFetch && $cursor->name instanceof Node\Identifier) {
                return $this->propertyTypes[$cursor->name->toString()] ?? null;
            }

            return null;
        }
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private function typeToModelWith(?Node $type, string $namespace, array $useMap): ?string
    {
        if ($type instanceof Node\NullableType) {
            return $this->typeToModelWith($type->type, $namespace, $useMap);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $inner) {
                $fqcn = $this->typeToModelWith($inner, $namespace, $useMap);
                if ($fqcn !== null) {
                    return $fqcn;
                }
            }

            return null;
        }
        if (! $type instanceof Node\Name) {
            return null;
        }
        $fqcn = $this->resolveNameWith($type, $namespace, $useMap);

        return $this->tableForModel($fqcn) === null ? null : $fqcn;
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
