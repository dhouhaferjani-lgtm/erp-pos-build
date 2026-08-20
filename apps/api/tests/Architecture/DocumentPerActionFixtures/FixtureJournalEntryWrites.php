<?php

declare(strict_types=1);

namespace Tests\Architecture\DocumentPerActionFixtures;

use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * Tamper/liveness fixtures for the `journal_entries` row of the
 * document-per-action write matrix (P1 deliverable 6).
 *
 * ONE method per matrix cell, so each cell maps to exactly one scanner key
 * (`…::<method>::journal_entries::<mechanism>#1`). This file is PARSED, never
 * executed or autoloaded at runtime.
 *
 * Expectations live in DocumentPerActionWriteGuardTest::fixtureMatrix().
 */
final class FixtureJournalEntryWrites
{
    // --- create -----------------------------------------------------------

    public function createUnlinked(string $companyId): void
    {
        JournalEntry::create([
            'company_id' => $companyId,
            'entry_number' => 'JE-1',
            'description' => 'no source linkage at all',
        ]);
    }

    public function createLinked(string $companyId, string $documentId): void
    {
        JournalEntry::create([
            'company_id' => $companyId,
            'entry_number' => 'JE-2',
            'source_type' => 'document',
            'source_id' => $documentId,
        ]);
    }

    /**
     * A `source_type` with a null `source_id` is NOT linkage: the pair must be
     * present AND non-null (this is the shape of the live GeneralLedgerService
     * ::createPaymentEntry defect).
     */
    public function createHalfLinked(string $companyId): void
    {
        JournalEntry::create([
            'company_id' => $companyId,
            'entry_number' => 'JE-3',
            'source_type' => 'payment',
            'source_id' => null,
        ]);
    }

    /**
     * A payload the scanner cannot read must never be waved through.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createFromVariablePayload(array $attributes): void
    {
        JournalEntry::create($attributes);
    }

    // --- firstOrCreate ----------------------------------------------------

    public function firstOrCreateUnlinked(string $companyId): void
    {
        JournalEntry::firstOrCreate(
            ['company_id' => $companyId, 'entry_number' => 'JE-4'],
            ['description' => 'still no linkage'],
        );
    }

    public function firstOrCreateLinked(string $companyId, string $documentId): void
    {
        JournalEntry::firstOrCreate(
            ['company_id' => $companyId, 'entry_number' => 'JE-5'],
            ['source_type' => 'document', 'source_id' => $documentId],
        );
    }

    // --- updateOrCreate ---------------------------------------------------

    public function updateOrCreateUnlinked(string $companyId): void
    {
        JournalEntry::updateOrCreate(
            ['company_id' => $companyId, 'entry_number' => 'JE-6'],
            ['description' => 'no linkage'],
        );
    }

    public function updateOrCreateLinked(string $companyId, string $documentId): void
    {
        JournalEntry::updateOrCreate(
            ['company_id' => $companyId, 'entry_number' => 'JE-7'],
            ['source_type' => 'document', 'source_id' => $documentId],
        );
    }

    // --- update -----------------------------------------------------------

    public function updateErasesLinkage(string $entryId): void
    {
        JournalEntry::query()->whereKey($entryId)->update([
            'source_type' => null,
            'source_id' => null,
        ]);
    }

    public function updateLifecycleOnly(string $entryId): void
    {
        JournalEntry::query()->whereKey($entryId)->update([
            'status' => 'posted',
            'posted_at' => '2026-01-01 00:00:00',
        ]);
    }

    // --- save -------------------------------------------------------------

    public function saveLifecycleOnly(string $entryId): void
    {
        $entry = JournalEntry::query()->findOrFail($entryId);
        $entry->status = JournalEntryStatus::Posted;
        $entry->save();
    }

    /**
     * The erasure path a `save()` would otherwise hide: the payload of a save
     * is invisible, so the guard reads the property assignment instead.
     */
    public function saveErasesLinkage(string $entryId): void
    {
        $entry = JournalEntry::query()->findOrFail($entryId);
        $entry->source_id = null;
        $entry->save();
    }

    // --- create-by-save (M3 final gate round 9) ----------------------------

    /**
     * `save()` on a model that was never persisted is an INSERT. There is no
     * prior creation at which a justification could have been fixed, so the
     * lifecycle exemption's premise is false for exactly this shape.
     */
    public function p5_new_then_save(string $companyId): void
    {
        $entry = new JournalEntry;
        $entry->company_id = $companyId;
        $entry->entry_number = 'JE-S1';
        $entry->save();
    }

    /**
     * The same insert reached by assigning properties one at a time.
     */
    public function q1_property_assign_then_save(string $companyId, string $number): void
    {
        $entry = new JournalEntry;
        $entry->company_id = $companyId;
        $entry->entry_number = $number;
        $entry->description = 'no linkage anywhere';
        $entry->save();
    }

    /**
     * `fill()` on a fresh model, then `save()` — mass assignment into an INSERT.
     * The payload deliberately carries NO linkage column, so the erasure rule
     * does not fire and this cell isolates the create-by-save semantics.
     */
    public function q2_fill_then_save(string $companyId): void
    {
        $entry = new JournalEntry;
        $entry->fill([
            'company_id' => $companyId,
            'entry_number' => 'JE-S2',
        ]);
        $entry->save();
    }

    /**
     * `make()` builds an unpersisted model exactly as `new` does.
     */
    public function q3_make_then_save(string $companyId): void
    {
        $entry = JournalEntry::make([
            'company_id' => $companyId,
            'entry_number' => 'JE-S3',
        ]);
        $entry->save();
    }

    // --- delete -----------------------------------------------------------

    public function deleteEntry(string $entryId): void
    {
        JournalEntry::query()->whereKey($entryId)->delete();
    }

    // --- increment / decrement --------------------------------------------

    public function incrementChainSequence(string $entryId): void
    {
        JournalEntry::query()->whereKey($entryId)->increment('chain_sequence');
    }

    public function decrementChainSequence(string $entryId): void
    {
        JournalEntry::query()->whereKey($entryId)->decrement('chain_sequence');
    }

    // --- query builder ----------------------------------------------------

    public function queryBuilderInsertUnlinked(string $companyId): void
    {
        DB::table('journal_entries')->insert([
            'company_id' => $companyId,
            'entry_number' => 'JE-8',
        ]);
    }

    public function queryBuilderInsertLinked(string $companyId, string $documentId): void
    {
        DB::table('journal_entries')->insert([
            'company_id' => $companyId,
            'entry_number' => 'JE-9',
            'source_type' => 'document',
            'source_id' => $documentId,
        ]);
    }

    public function queryBuilderDelete(string $entryId): void
    {
        DB::table('journal_entries')->where('id', $entryId)->delete();
    }

    // --- raw SQL ----------------------------------------------------------

    public function rawSqlInsert(): void
    {
        DB::statement("INSERT INTO journal_entries (id, entry_number) VALUES (gen_random_uuid(), 'JE-10')");
    }

    public function rawSqlUpdate(): void
    {
        DB::statement("UPDATE journal_entries SET source_id = NULL WHERE status = 'draft'");
    }

    public function rawSqlDelete(): void
    {
        DB::statement("DELETE FROM journal_entries WHERE status = 'draft'");
    }
}
