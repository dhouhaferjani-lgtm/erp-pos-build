# Journal Entry Immutability Test Documentation

## Purpose

This test suite verifies that journal entries in the fiscal hash chain are immutable and cannot be modified or deleted after creation. This is critical for:

1. **Fiscal Compliance**: Many jurisdictions require immutable accounting records
2. **Chain Integrity**: Modifying an entry invalidates all subsequent hashes
3. **Audit Trust**: Auditors need confidence records haven't changed

## Test Status

**Phase**: RED (TDD Red Phase)
**Expected Outcome**: ALL tests FAIL until immutability is implemented
**Current Results**: 12 tests FAIL, 3 tests PASS (as expected)

## Test Coverage

### Tests that PASS (Expected)
These tests verify draft entries CAN be modified:

1. ✅ `test_journal_entry_without_hash_can_be_updated()` - Draft entries allow updates
2. ✅ `test_journal_entry_without_hash_can_be_deleted()` - Draft entries allow deletion
3. ✅ `test_is_chained_method_correctly_identifies_chained_entries()` - Helper method works

### Tests that FAIL (Expected - Immutability Not Implemented)

#### Entry-Level Immutability
4. ❌ `test_journal_entry_with_hash_cannot_be_updated()` - Should prevent updates to chained entries
5. ❌ `test_journal_entry_with_hash_cannot_be_deleted()` - Should prevent deletion of chained entries
6. ❌ `test_mass_update_of_chained_entries_fails()` - Should prevent query builder updates
7. ❌ `test_updating_any_field_of_chained_entry_throws_exception()` - Should prevent any field modification

#### Line-Level Immutability
8. ❌ `test_journal_lines_cannot_be_modified_if_entry_has_hash()` - Should prevent line updates
9. ❌ `test_journal_lines_cannot_be_deleted_if_entry_has_hash()` - Should prevent line deletion
10. ❌ `test_creating_new_lines_on_chained_entry_fails()` - Should prevent adding lines to chained entry

#### Critical Field Protection
11. ❌ `test_chain_sequence_cannot_be_modified()` - Should protect chain_sequence field
12. ❌ `test_hash_fields_cannot_be_modified()` - Should protect fiscal_hash and previous_hash

#### Exception Quality
13. ❌ `test_exception_message_is_clear()` - Should provide helpful error messages

#### Advanced Cases
14. ❌ `test_multiple_field_updates_throw_exception()` - Should prevent batch updates
15. ❌ `test_entry_with_hash_cannot_be_updated_via_query_builder()` - Should prevent direct SQL updates

## Expected Behavior After Implementation

### When Immutability is Implemented

**Entry Updates/Deletes:**
```php
$entry = JournalEntry::find($id); // Has fiscal_hash

try {
    $entry->update(['description' => 'Modified']);
} catch (ImmutableJournalEntryException $e) {
    // Expected: "Cannot update journal entry GL-2025-0001 because it is
    //            part of an immutable hash chain."
}
```

**Line Updates/Deletes:**
```php
$line = JournalLine::find($id); // Belongs to chained entry

try {
    $line->delete();
} catch (ImmutableJournalEntryException $e) {
    // Expected: "Cannot delete journal lines for entry GL-2025-0001
    //            because the entry is part of an immutable hash chain."
}
```

**Query Builder Updates:**
```php
try {
    JournalEntry::where('id', $id)->update(['description' => 'Modified']);
} catch (ImmutableJournalEntryException $e) {
    // Expected: Should also throw exception
}
```

## Implementation Strategy Recommendations

### Option 1: Model Observer (Recommended)

**Pros:**
- Laravel convention
- Centralized logic
- Easy to test
- Works for both model and query builder updates

**Implementation:**
```php
// Create: app/Modules/Accounting/Observers/JournalEntryObserver.php

class JournalEntryObserver
{
    public function updating(JournalEntry $entry): void
    {
        if ($entry->isChained()) {
            throw ImmutableJournalEntryException::cannotUpdate($entry->entry_number);
        }
    }

    public function deleting(JournalEntry $entry): void
    {
        if ($entry->isChained()) {
            throw ImmutableJournalEntryException::cannotDelete($entry->entry_number);
        }
    }
}

// Register in AppServiceProvider:
JournalEntry::observe(JournalEntryObserver::class);
```

### Option 2: Database Trigger (Most Robust)

**Pros:**
- Enforced at database level
- Prevents all modifications (even outside Laravel)
- Most secure

**Cons:**
- Database-specific
- Harder to test
- Less flexible error messages

**Implementation:**
```sql
CREATE OR REPLACE FUNCTION prevent_immutable_journal_entry_modification()
RETURNS TRIGGER AS $$
BEGIN
    IF OLD.fiscal_hash IS NOT NULL THEN
        RAISE EXCEPTION 'Cannot modify journal entry % because it is part of an immutable hash chain',
            OLD.entry_number;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER journal_entry_immutability_check
    BEFORE UPDATE OR DELETE ON journal_entries
    FOR EACH ROW
    EXECUTE FUNCTION prevent_immutable_journal_entry_modification();
```

### Option 3: Model Override (Not Recommended)

**Pros:**
- Simple

**Cons:**
- Doesn't catch query builder updates
- Easy to bypass
- Not comprehensive

## Test Execution

### Run All Immutability Tests
```bash
php artisan test --filter=JournalEntryImmutabilityTest
```

### Run Specific Test
```bash
php artisan test --filter=test_journal_entry_with_hash_cannot_be_updated
```

### Expected Output (RED Phase)
```
Tests:    12 failed, 3 passed (16 assertions)
Duration: ~1s
```

### Expected Output (GREEN Phase - After Implementation)
```
Tests:    15 passed (30+ assertions)
Duration: ~1s
```

## Key Assertions

### Test Structure Pattern
```php
public function test_feature(): void
{
    // Arrange: Create test data
    $entry = $this->createJournalEntry([
        'fiscal_hash' => hash('sha256', 'data'),
        'chain_sequence' => 1,
    ]);

    // Act & Assert: Expect exception
    $this->expectException(ImmutableJournalEntryException::class);

    $entry->update(['description' => 'Modified']);
}
```

## Integration with Hash Chain

### Relationship to Other Milestones

**P1-B Milestone 1**: GL hash chain schema ✅ Complete
**P1-B Milestone 2**: GeneralLedgerHashService ✅ Complete
**P1-B Milestone 3**: AccountingService integration ✅ Complete
**P1-B Milestone 4**: Immutability enforcement ⏳ This milestone (RED phase)

### Workflow
```
1. Invoice posted
   ↓
2. AccountingService creates GL entry
   ↓
3. GeneralLedgerHashService calculates hash
   ↓
4. GL entry saved with fiscal_hash ← BECOMES IMMUTABLE HERE
   ↓
5. Any modification attempt → ImmutableJournalEntryException
```

## Edge Cases Covered

1. ✅ Draft entries (no hash) remain mutable
2. ✅ Chained entries (with hash) become immutable
3. ✅ Line modifications are prevented
4. ✅ Mass updates are prevented
5. ✅ Critical fields (hash, sequence) are protected
6. ✅ Exception messages are clear and helpful
7. ✅ Both model and query builder updates are caught

## Next Steps for Agent 4B (Implementation Phase)

1. Choose implementation strategy (recommend Model Observer)
2. Create JournalEntryObserver class
3. Create JournalLineObserver class
4. Register observers in service provider
5. Run tests → verify all 15 pass (GREEN phase)
6. Add integration tests for real-world scenarios
7. Update documentation with implementation details

## Success Criteria

✅ All 15 tests pass
✅ Draft entries can still be modified
✅ Chained entries cannot be modified
✅ Chained entries cannot be deleted
✅ Lines of chained entries are protected
✅ Exception messages are helpful
✅ Performance impact is minimal (<10ms per operation)

## Files Created

1. `app/Modules/Accounting/Domain/Exceptions/ImmutableJournalEntryException.php` - Custom exception
2. `tests/Feature/Accounting/JournalEntryImmutabilityTest.php` - Test suite (15 tests)
3. `tests/Feature/Accounting/JournalEntryImmutabilityTest.md` - This documentation

## Additional Notes

- Tests use `RefreshDatabase` trait for isolation
- Each test is independent and can run in any order
- Test data is created in setUp() method for efficiency
- Helper methods reduce duplication
- Clear test names describe expected behavior
- Comments explain WHY each test matters

---

**Document Version**: 1.0
**Created**: 2025-12-26
**Agent**: Agent 4A (Test Writer)
**Phase**: TDD RED
**Status**: Ready for Agent 4B implementation
