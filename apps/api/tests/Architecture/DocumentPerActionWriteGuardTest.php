<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\DocumentPerActionWriteScanner;
use Tests\TestCase;

/**
 * Document-per-action cementing guard (enforcement package P1).
 *
 * Every stock/GL mutation needs its own justifying document. This gate makes
 * that principle a static, merge-blocking contract over the four tables the DPA
 * audit named: `journal_entries`, `stock_movements`, `stock_levels`,
 * `inventory_batch_stock`.
 *
 * The operative per-table rules live in the scanner's class docblock
 * (Tests\Architecture\Support\DocumentPerActionWriteScanner) — read them before
 * changing anything here.
 *
 * This file is the guard's LIVENESS CERTIFICATE: the fixture matrix pins one
 * positive (violation) and one negative (linked / not-in-contract) case per
 * write mechanism per applicable table, so a scanner that silently stops
 * detecting a mechanism fails here instead of quietly certifying the tree
 * clean. Cells with no LINKED form by rule (raw SQL anywhere; every MUTATE and
 * DELETE against the append-only movement ledger) are pinned as positive-only
 * and say so.
 *
 * No database, no container — pure AST analysis (house style, see
 * tests/Architecture/TenantScopedFindCallsTest.php).
 */
final class DocumentPerActionWriteGuardTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tests\Architecture\DocumentPerActionFixtures';

    /**
     * The mechanism vocabulary the guard must keep detecting (brief §2
     * deliverables 1 + 6). Every entry must appear in the fixture matrix.
     *
     * @var list<string>
     */
    private const MECHANISMS = [
        'create',
        'firstOrCreate',
        'updateOrCreate',
        'update',
        'save',
        'delete',
        'increment',
        'decrement',
        'query_builder',
        'raw_sql',
    ];

    /**
     * mechanism => [table => [fixture class short name, method, expected classification]]
     *
     * `violation` = the guard reports it. `linked` = a justifying reference is
     * present. `not_applicable` = the contract does not govern the write (and
     * the guard must NOT report it — a false positive is as much a defect as a
     * false negative, because it pollutes the shrink-only baseline).
     *
     * @return list<array{mechanism: string, table: string, class: string, method: string, expected: string, note: string}>
     */
    public static function fixtureMatrix(): array
    {
        return [
            // ---------------- journal_entries ----------------
            ['mechanism' => 'create', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'createUnlinked', 'expected' => 'violation', 'note' => 'no source_type/source_id'],
            ['mechanism' => 'create', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'createLinked', 'expected' => 'linked', 'note' => 'paired source linkage'],
            ['mechanism' => 'create', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'createHalfLinked', 'expected' => 'violation', 'note' => 'source_type present, source_id null'],
            ['mechanism' => 'create', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'createFromVariablePayload', 'expected' => 'violation', 'note' => 'unresolvable payload — fail closed'],
            ['mechanism' => 'firstOrCreate', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'firstOrCreateUnlinked', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'firstOrCreate', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'firstOrCreateLinked', 'expected' => 'linked', 'note' => 'linkage in the values array'],
            ['mechanism' => 'updateOrCreate', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'updateOrCreateUnlinked', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'updateOrCreate', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'updateOrCreateLinked', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'update', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'updateErasesLinkage', 'expected' => 'violation', 'note' => 'nulls source_type/source_id'],
            ['mechanism' => 'update', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'updateLifecycleOnly', 'expected' => 'not_applicable', 'note' => 'status/posted_at only; no amount on this table'],
            ['mechanism' => 'save', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'saveLifecycleOnly', 'expected' => 'not_applicable', 'note' => 'lifecycle save; justification fixed at creation'],
            ['mechanism' => 'save', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'saveErasesLinkage', 'expected' => 'violation', 'note' => 'property assignment nulls source_id before save()'],
            ['mechanism' => 'delete', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'deleteEntry', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule: a JE is reversed, never deleted'],
            ['mechanism' => 'increment', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'incrementChainSequence', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule'],
            ['mechanism' => 'decrement', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'decrementChainSequence', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule'],
            ['mechanism' => 'query_builder', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'queryBuilderInsertUnlinked', 'expected' => 'violation', 'note' => 'DB::table insert without linkage'],
            ['mechanism' => 'query_builder', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'queryBuilderInsertLinked', 'expected' => 'linked', 'note' => 'DB::table insert with linkage'],
            ['mechanism' => 'query_builder', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'queryBuilderDelete', 'expected' => 'violation', 'note' => 'builder delete'],
            ['mechanism' => 'raw_sql', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'rawSqlInsert', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule: raw SQL is never credited'],
            ['mechanism' => 'raw_sql', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'rawSqlUpdate', 'expected' => 'violation', 'note' => 'raw UPDATE can erase linkage invisibly'],
            ['mechanism' => 'raw_sql', 'table' => 'journal_entries', 'class' => 'FixtureJournalEntryWrites', 'method' => 'rawSqlDelete', 'expected' => 'violation', 'note' => ''],

            // ---------------- stock_movements ----------------
            ['mechanism' => 'create', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'createUnlinked', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'create', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'createLinked', 'expected' => 'linked', 'note' => 'paired reference_type/reference_id'],
            ['mechanism' => 'create', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'createHalfLinked', 'expected' => 'violation', 'note' => 'unpaired reference — the S0 seam refuses it at runtime too'],
            ['mechanism' => 'firstOrCreate', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'firstOrCreateUnlinked', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'firstOrCreate', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'firstOrCreateLinked', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'updateOrCreate', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'updateOrCreateUnlinked', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'updateOrCreate', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'updateOrCreateLinked', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'update', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'updateExistingMovement', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule: append-only ledger'],
            ['mechanism' => 'save', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'saveExistingMovement', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule'],
            ['mechanism' => 'delete', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'deleteMovement', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule'],
            ['mechanism' => 'increment', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'incrementMovementQuantity', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule'],
            ['mechanism' => 'decrement', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'decrementMovementQuantity', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule'],
            ['mechanism' => 'query_builder', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'queryBuilderInsertUnlinked', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'query_builder', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'queryBuilderInsertLinked', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'raw_sql', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'rawSqlInsert', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule'],
            ['mechanism' => 'raw_sql', 'table' => 'stock_movements', 'class' => 'FixtureStockMovementWrites', 'method' => 'rawSqlDelete', 'expected' => 'violation', 'note' => ''],

            // ---------------- stock_levels ----------------
            ['mechanism' => 'create', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'createBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'create', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'createWithMovement', 'expected' => 'linked', 'note' => 'paired with a linked movement create'],
            ['mechanism' => 'firstOrCreate', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'firstOrCreateBypassingMovement', 'expected' => 'violation', 'note' => 'the live StockAdjustmentService::getOrCreateStockLevel shape'],
            ['mechanism' => 'firstOrCreate', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'firstOrCreateWithMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'updateOrCreate', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'updateOrCreateBypassingMovement', 'expected' => 'violation', 'note' => 'the retired upsertStockLevel shape (DPA V6)'],
            ['mechanism' => 'updateOrCreate', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'updateOrCreateWithMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'update', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'updateQuantityBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'update', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'updateQuantityWithRecordedMovement', 'expected' => 'linked', 'note' => 'recordMovement arm of the pairing predicate'],
            ['mechanism' => 'update', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'updateReservedOnly', 'expected' => 'not_applicable', 'note' => 'soft hold, no on-hand change'],
            ['mechanism' => 'save', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'saveBypassingMovement', 'expected' => 'violation', 'note' => 'unresolvable payload — fail closed'],
            ['mechanism' => 'save', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'saveWithMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'delete', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'deleteBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'delete', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'deleteWithMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'increment', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'incrementQuantityBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'increment', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'incrementQuantityWithRecordedMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'decrement', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'decrementQuantityBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'decrement', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'decrementQuantityWithRecordedMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'decrement', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'decrementReservedOnly', 'expected' => 'not_applicable', 'note' => 'the live StockReservationService shape'],
            ['mechanism' => 'query_builder', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'queryBuilderUpdateBypassingMovement', 'expected' => 'violation', 'note' => 'the live StockThresholdService/FEFO shape'],
            ['mechanism' => 'query_builder', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'queryBuilderUpdateWithMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'raw_sql', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'rawSqlUpdate', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule'],
            ['mechanism' => 'update', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'updateVariantGrainOnly', 'expected' => 'violation', 'note' => 'finding 1: re-keying a level row is NOT a soft hold — the exemption is a positive allowlist'],
            ['mechanism' => 'update', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelWrites', 'method' => 'updateQuantityWithLocalRecordMovementStub', 'expected' => 'violation', 'note' => 'finding 3: a locally-named recordMovement() stub credits nothing'],

            // ---------------- proven-vs-nullable linkage (finding 4) --------
            ['mechanism' => 'create', 'table' => 'journal_entries', 'class' => 'FixtureLinkageProofWrites', 'method' => 'journalEntryWithProvenLinkage', 'expected' => 'linked', 'note' => 'non-nullable parameters'],
            ['mechanism' => 'create', 'table' => 'journal_entries', 'class' => 'FixtureLinkageProofWrites', 'method' => 'journalEntryWithNullableLinkage', 'expected' => 'violation', 'note' => 'keys present, values nullable — linkage is not proven'],
            ['mechanism' => 'create', 'table' => 'stock_movements', 'class' => 'FixtureLinkageProofWrites', 'method' => 'movementWithNullsafeLinkage', 'expected' => 'violation', 'note' => 'the S0 chokepoint shape: nullsafe read off a nullable enum'],
            ['mechanism' => 'create', 'table' => 'stock_movements', 'class' => 'FixtureLinkageProofWrites', 'method' => 'movementWithCoalescedLinkage', 'expected' => 'linked', 'note' => 'null-coalesced onto a non-nullable fallback IS proven'],
            ['mechanism' => 'create', 'table' => 'journal_entries', 'class' => 'FixtureLinkageProofWrites', 'method' => 'journalEntryWithNullAssignedLocal', 'expected' => 'violation', 'note' => 'round 2 finding 1: local assigned null'],
            ['mechanism' => 'create', 'table' => 'journal_entries', 'class' => 'FixtureLinkageProofWrites', 'method' => 'journalEntryWithNullableProperty', 'expected' => 'violation', 'note' => 'round 2 finding 1: nullable $this property'],
            ['mechanism' => 'create', 'table' => 'stock_movements', 'class' => 'FixtureLinkageProofWrites', 'method' => 'movementWithNullableReturnLinkage', 'expected' => 'violation', 'note' => 'round 2 finding 1: nullable-returning $this method'],
            ['mechanism' => 'create', 'table' => 'stock_movements', 'class' => 'FixtureLinkageProofWrites', 'method' => 'movementWithArrayElementLinkage', 'expected' => 'violation', 'note' => 'round 2 finding 1: array element'],
            ['mechanism' => 'create', 'table' => 'journal_entries', 'class' => 'FixtureLinkageProofWrites', 'method' => 'journalEntryWithNonNullableProperty', 'expected' => 'linked', 'note' => 'non-nullable promoted property is credited (blind spot E boundary)'],
            ['mechanism' => 'update', 'table' => 'journal_entries', 'class' => 'FixtureLinkageProofWrites', 'method' => 'journalEntryUpdateWithUnreadablePayload', 'expected' => 'violation', 'note' => 'round 2 finding 2: unreadable MUTATE payload fails closed'],
            ['mechanism' => 'update', 'table' => 'journal_entries', 'class' => 'FixtureLinkageProofWrites', 'method' => 'journalEntryUpdateWithArrayUnionPayload', 'expected' => 'violation', 'note' => 'round 2 finding 2: array-union payload that nulls linkage'],
            ['mechanism' => 'save', 'table' => 'journal_entries', 'class' => 'FixtureLinkageProofWrites', 'method' => 'journalEntrySaveErasesViaNullableCall', 'expected' => 'violation', 'note' => 'round 2 finding 3: erasure through a null-admitting expression'],

            // ---------------- relation-mediated + model-internal (findings 2, 5)
            ['mechanism' => 'create', 'table' => 'stock_levels', 'class' => 'FixtureRelationAndInheritanceWrites', 'method' => 'relationCreateBypassingMovement', 'expected' => 'violation', 'note' => 'relation-mediated write, no movement'],
            ['mechanism' => 'create', 'table' => 'stock_levels', 'class' => 'FixtureRelationAndInheritanceWrites', 'method' => 'relationCreateWithMovement', 'expected' => 'linked', 'note' => 'relation-mediated write paired with a linked movement'],
            ['mechanism' => 'update', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelSubclass', 'method' => 'setOnHandQuantity', 'expected' => 'violation', 'note' => 'model-internal $this->update() through the extends chain'],
            ['mechanism' => 'update', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelSubclass', 'method' => 'setReservedQuantity', 'expected' => 'not_applicable', 'note' => 'model-internal soft hold'],
            ['mechanism' => 'save', 'table' => 'stock_levels', 'class' => 'FixtureStockLevelSubclass', 'method' => 'persistOnHand', 'expected' => 'violation', 'note' => 'model-internal $this->save()'],

            // ---------------- inventory_batch_stock ----------------
            ['mechanism' => 'create', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'createBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'create', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'createWithBatchMovement', 'expected' => 'linked', 'note' => 'BatchMovement carries movement_id'],
            ['mechanism' => 'firstOrCreate', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'firstOrCreateBypassingMovement', 'expected' => 'violation', 'note' => 'the live BatchStockService::ensureDefaultBatch shape'],
            ['mechanism' => 'firstOrCreate', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'firstOrCreateWithBatchMovement', 'expected' => 'linked', 'note' => 'the live StockAdjustmentService::recordBatchMovement shape'],
            ['mechanism' => 'firstOrCreate', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'firstOrCreateWithConditionalBatchMovement', 'expected' => 'violation', 'note' => 'movement_id only conditionally present — not statically guaranteed'],
            ['mechanism' => 'updateOrCreate', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'updateOrCreateBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'updateOrCreate', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'updateOrCreateWithBatchMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'update', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'updateQuantityBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'update', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'updateQuantityWithStockMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'update', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'updateReservedQuantityOnly', 'expected' => 'not_applicable', 'note' => 'soft hold'],
            ['mechanism' => 'save', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'saveBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'save', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'saveWithBatchMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'delete', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'deleteBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'delete', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'deleteWithBatchMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'increment', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'incrementQuantityBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'increment', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'incrementQuantityWithBatchMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'decrement', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'decrementQuantityBypassingMovement', 'expected' => 'violation', 'note' => ''],
            ['mechanism' => 'decrement', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'decrementQuantityWithBatchMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'decrement', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'decrementReservedQuantityOnly', 'expected' => 'not_applicable', 'note' => 'the live StockReservationService shape'],
            ['mechanism' => 'query_builder', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'queryBuilderUpdateBypassingMovement', 'expected' => 'violation', 'note' => 'the live ReceiptReturnService/FEFO shape'],
            ['mechanism' => 'query_builder', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'queryBuilderUpdateWithBatchMovement', 'expected' => 'linked', 'note' => ''],
            ['mechanism' => 'raw_sql', 'table' => 'inventory_batch_stock', 'class' => 'FixtureBatchStockWrites', 'method' => 'rawSqlUpdate', 'expected' => 'violation', 'note' => 'POSITIVE-ONLY by rule'],
        ];
    }

    /**
     * The tamper proof: every fixture cell must be classified exactly as the
     * per-table rules say. A scanner regression that stops seeing a mechanism
     * turns the cell's `violation` expectation red.
     */
    #[Test]
    public function fixture_matrix_is_classified_exactly_as_the_rules_state(): void
    {
        $sites = $this->scanFixtures();
        $failures = [];

        foreach (self::fixtureMatrix() as $case) {
            $key = $this->fixtureKey($case['class'], $case['method'], $case['table'], $case['mechanism']);
            $actual = $sites[$key]['classification'] ?? null;

            if ($actual === null) {
                $failures[] = sprintf('MISSING SITE  %s (expected %s)', $key, $case['expected']);

                continue;
            }
            if ($actual !== $case['expected']) {
                $failures[] = sprintf(
                    'WRONG CLASS   %s: expected %s, got %s (%s)',
                    $key,
                    $case['expected'],
                    $actual,
                    $sites[$key]['reason'],
                );
            }
        }

        $this->assertSame(
            [],
            $failures,
            "The document-per-action write guard no longer classifies its own fixtures correctly:\n".implode("\n", $failures),
        );
    }

    /**
     * Coverage completeness: every mechanism in the vocabulary must be pinned
     * for every one of the four tables, with a POSITIVE case always, and a
     * NEGATIVE case wherever a linked form exists by rule.
     */
    #[Test]
    public function every_mechanism_is_pinned_for_every_table(): void
    {
        $covered = [];
        foreach (self::fixtureMatrix() as $case) {
            $covered[$case['table']][$case['mechanism']][] = $case['expected'];
        }

        $missing = [];
        foreach (array_keys(DocumentPerActionWriteScanner::TABLE_MODELS) as $table) {
            foreach (self::MECHANISMS as $mechanism) {
                $expectations = $covered[$table][$mechanism] ?? [];
                if ($expectations === []) {
                    $missing[] = sprintf('%s / %s: no fixture at all', $table, $mechanism);

                    continue;
                }
                if (! in_array('violation', $expectations, true)) {
                    $missing[] = sprintf('%s / %s: no POSITIVE (violation) fixture', $table, $mechanism);
                }
            }
        }

        $this->assertSame([], $missing, "Fixture matrix is incomplete:\n".implode("\n", $missing));
    }

    /**
     * The negative half of the matrix, stated as its own gate so that deleting
     * a "linked" fixture cannot silently turn the guard into a rubber stamp.
     */
    #[Test]
    public function every_cell_with_a_linked_form_pins_a_negative_case(): void
    {
        // Which cells must pin a negative control is DERIVED from the
        // scanner's own rule surface (DocumentPerActionWriteScanner::linkedFormExists),
        // never from a map inside this test — a hardcoded map here is a knob
        // that could be edited to drop a requirement from the liveness
        // certificate itself.
        $covered = [];
        foreach (self::fixtureMatrix() as $case) {
            $covered[$case['table']][$case['mechanism']][] = $case['expected'];
        }

        $missing = [];
        foreach (array_keys(DocumentPerActionWriteScanner::TABLE_MODELS) as $table) {
            foreach (self::MECHANISMS as $mechanism) {
                if (! DocumentPerActionWriteScanner::linkedFormExists($table, $mechanism)) {
                    continue;
                }
                $expectations = $covered[$table][$mechanism] ?? [];
                $hasNegative = in_array('linked', $expectations, true) || in_array('not_applicable', $expectations, true);
                if (! $hasNegative) {
                    $missing[] = sprintf('%s / %s: no NEGATIVE (linked or not_applicable) fixture', $table, $mechanism);
                }
            }
        }

        $this->assertSame([], $missing, "Fixture matrix has no negative control for:\n".implode("\n", $missing));
    }

    /**
     * @return array<string, array{key: string, classification: string, reason: string, table: string, mechanism: string}>
     */
    private function scanFixtures(): array
    {
        $root = __DIR__.'/DocumentPerActionFixtures';
        $scanner = new DocumentPerActionWriteScanner;

        // The production tree is passed as CONTEXT (maps only, no sites
        // reported from it) so a fixture resolves through exactly the same
        // relation / inheritance / return-type / chokepoint indexes as live
        // code — a fixture that passes in a vacuum proves nothing.
        $indexed = [];
        foreach ($scanner->scan([$root], __DIR__.'/', [base_path().'/app']) as $site) {
            $indexed[$site['key']] = $site;
        }

        return $indexed;
    }

    /**
     * Fixture classes that do not live in a file of their own.
     *
     * @var array<string, string>
     */
    private const FIXTURE_FILES = [
        'FixtureRelationHost' => 'FixtureRelationAndInheritanceWrites',
        'FixtureStockLevelSubclass' => 'FixtureRelationAndInheritanceWrites',
    ];

    private function fixtureKey(string $class, string $method, string $table, string $mechanism): string
    {
        return sprintf(
            'DocumentPerActionFixtures/%s.php::%s\%s::%s::%s::%s#1',
            self::FIXTURE_FILES[$class] ?? $class,
            self::FIXTURE_NAMESPACE,
            $class,
            $method,
            $table,
            $mechanism,
        );
    }
}
