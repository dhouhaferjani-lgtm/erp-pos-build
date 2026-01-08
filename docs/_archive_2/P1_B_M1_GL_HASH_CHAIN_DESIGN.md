# P1-B Milestone 1: GL Hash Chain Schema Design

## Overview

This document explains the design decisions for implementing the General Ledger (GL) hash chain for fiscal compliance.

## Objective

Add fiscal compliance hash chaining to journal entries, similar to how documents already have fiscal hashes, to provide:
- **Fiscal Compliance**: Immutable audit trail for accounting records
- **Fraud Prevention**: Cryptographic detection of tampering
- **Audit Trail**: Proof that GL entries haven't been modified or deleted
- **Trust**: Cryptographic integrity verification

## Design Decisions

### 1. Column Naming Convention

**Decision**: Rename `hash` to `fiscal_hash`

**Rationale**:
- Consistency with `documents` table which uses `fiscal_hash`
- Clearly indicates the purpose (fiscal compliance) vs generic hashing
- Aligns with domain language used throughout the codebase

**Implementation**:
```php
// Before
$table->string('hash', 255)->nullable();

// After
$table->string('fiscal_hash', 64)->nullable();
```

### 2. Hash Algorithm: SHA-256

**Decision**: Use SHA-256 hashing (64 character hex string)

**Rationale**:
- Industry standard for fiscal compliance
- Same algorithm used for document hash chain (consistency)
- Provides cryptographic strength without being overly complex
- 64 characters (32 bytes) is sufficient for collision resistance

**Implementation**:
```php
$table->string('fiscal_hash', 64)->nullable()->unique();
$table->string('previous_hash', 64)->nullable();
```

### 3. Chain Scope: Per-Company

**Decision**: Each company has its own independent GL hash chain

**Rationale**:
- **Accounting Books**: Each company maintains independent accounting records
- **Data Isolation**: Company A's GL chain is separate from Company B's
- **Audit Simplicity**: Easier to verify and audit per company
- **Compliance**: Each legal entity needs its own audit trail

**Implementation**:
```php
// Index for fast chain verification per company
$table->index(['company_id', 'chain_sequence'], 'idx_gl_company_chain');

// Helper method
public static function getNextChainSequence(string $companyId): int
{
    $maxSequence = self::where('company_id', $companyId)
        ->max('chain_sequence');

    return ($maxSequence ?? 0) + 1;
}
```

### 4. Nullable Columns

**Decision**: All hash chain columns are nullable

**Rationale**:
- **Historical Data**: Support existing journal entries without hashes
- **Gradual Migration**: Can be implemented without breaking existing data
- **Draft Entries**: Draft journal entries don't need to be in the chain
- **Flexibility**: Only posted entries need fiscal hashes

**Implementation**:
```php
$table->string('fiscal_hash', 64)->nullable()->unique();
$table->string('previous_hash', 64)->nullable();
$table->unsignedBigInteger('chain_sequence')->nullable();
```

### 5. Unique Constraint on fiscal_hash

**Decision**: Add unique constraint on `fiscal_hash` column

**Rationale**:
- **Data Integrity**: Prevents duplicate hashes across all entries
- **Collision Detection**: Database-level enforcement
- **Audit Validation**: Ensures each entry has a unique fingerprint
- **Performance**: Unique index allows fast hash lookups

**Implementation**:
```php
$table->unique('fiscal_hash', 'idx_gl_fiscal_hash_unique');
```

### 6. Indexes for Performance

**Decision**: Add three indexes related to hash chain

**Rationale**:
```php
// 1. Compound index on company_id + chain_sequence
$table->index(['company_id', 'chain_sequence'], 'idx_gl_company_chain');
// Purpose: Fast chain verification queries
// Query: SELECT * FROM journal_entries WHERE company_id = ? ORDER BY chain_sequence

// 2. Index on fiscal_hash
$table->index('fiscal_hash', 'idx_gl_fiscal_hash');
// Purpose: Fast hash lookup for verification
// Query: SELECT * FROM journal_entries WHERE fiscal_hash = ?

// 3. Unique constraint on fiscal_hash (also serves as index)
$table->unique('fiscal_hash', 'idx_gl_fiscal_hash_unique');
// Purpose: Integrity enforcement + fast lookups
```

## Database Schema

### Before Migration

```sql
CREATE TABLE journal_entries (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    entry_number VARCHAR(255) NOT NULL,
    entry_date DATE NOT NULL,
    description TEXT,
    status VARCHAR(255) DEFAULT 'draft',
    hash VARCHAR(255) NULL,              -- Old column name
    previous_hash VARCHAR(255) NULL,      -- Wrong size
    chain_sequence BIGINT NULL,
    ...
);
```

### After Migration

```sql
CREATE TABLE journal_entries (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    entry_number VARCHAR(255) NOT NULL,
    entry_date DATE NOT NULL,
    description TEXT,
    status VARCHAR(255) DEFAULT 'draft',
    fiscal_hash VARCHAR(64) NULL UNIQUE,     -- Renamed, resized, unique
    previous_hash VARCHAR(64) NULL,          -- Resized to SHA-256 length
    chain_sequence BIGINT NULL,
    ...

    INDEX idx_gl_company_chain (company_id, chain_sequence),
    INDEX idx_gl_fiscal_hash (fiscal_hash),
    UNIQUE INDEX idx_gl_fiscal_hash_unique (fiscal_hash)
);
```

## Model Enhancements

Added helper methods to `JournalEntry` model:

```php
/**
 * Check if journal entry is in a hash chain (fiscally sealed)
 */
public function isChained(): bool
{
    return $this->fiscal_hash !== null;
}

/**
 * Get the next expected chain sequence for a company's GL chain
 */
public static function getNextChainSequence(string $companyId): int
{
    $maxSequence = self::where('company_id', $companyId)
        ->max('chain_sequence');

    return ($maxSequence ?? 0) + 1;
}

/**
 * Get the hash of the last entry in the company's GL chain
 */
public static function getLastChainHash(string $companyId): ?string
{
    $lastEntry = self::where('company_id', $companyId)
        ->whereNotNull('fiscal_hash')
        ->orderByDesc('chain_sequence')
        ->first();

    return $lastEntry?->fiscal_hash;
}
```

## Integration with Document Hash Chain

Both systems use the same approach:

| Aspect | Documents | Journal Entries |
|--------|-----------|----------------|
| Hash Algorithm | SHA-256 (64 chars) | SHA-256 (64 chars) |
| Column Name | `fiscal_hash` | `fiscal_hash` |
| Previous Hash | `previous_hash` | `previous_hash` |
| Sequence | `chain_sequence` | `chain_sequence` |
| Scope | Per tenant + type | Per company |
| Nullable | Yes | Yes |
| Unique Constraint | Yes | Yes |

## Testing Strategy

Created comprehensive migration test covering:
- ✅ Column existence verification
- ✅ Column size validation (SHA-256 = 64 chars)
- ✅ Unique constraint on fiscal_hash
- ✅ Company + chain_sequence index
- ✅ Fiscal_hash dedicated index
- ✅ Nullable columns support
- ✅ Old 'hash' column renamed
- ✅ Database-agnostic (PostgreSQL + SQLite)

## Migration Safety

**Reversibility**: Migration is fully reversible via `down()` method

```php
public function down(): void
{
    // Drop indexes first
    $table->dropIndex('idx_gl_fiscal_hash');
    $table->dropIndex('idx_gl_company_chain');
    $table->dropUnique('idx_gl_fiscal_hash_unique');

    // Revert column changes
    $table->string('fiscal_hash', 255)->nullable()->change();
    $table->string('previous_hash', 255)->nullable()->change();

    // Rename back
    $table->renameColumn('fiscal_hash', 'hash');
}
```

**Data Safety**:
- No data loss (columns are renamed/resized, not dropped)
- Nullable columns preserve existing NULL values
- Existing data remains intact

## Next Steps (Agent 1B)

This schema is now ready for:
1. Hash calculation service implementation
2. Chain validation logic
3. Integration with journal entry posting
4. Audit/verification tools

## Files Modified

- `database/migrations/2025_12_26_111230_update_journal_entries_hash_chain_for_compliance.php` - New migration
- `app/Modules/Accounting/Domain/JournalEntry.php` - Updated model with hash chain support
- `tests/Feature/Accounting/JournalEntryHashChainMigrationTest.php` - New comprehensive test

## Verification

```bash
# Check migration status
php artisan migrate:status

# Run migration
php artisan migrate

# Run tests
php artisan test --filter=JournalEntryHashChainMigrationTest

# Verify in database
php artisan tinker
>>> Schema::hasColumn('journal_entries', 'fiscal_hash')
=> true
>>> Schema::getIndexes('journal_entries')
=> [...includes idx_gl_company_chain, idx_gl_fiscal_hash...]
```

---

**Status**: ✅ Complete - Schema design implemented and tested
**Next Agent**: Agent 1B - Hash Calculation Service Implementation
**Date**: 2025-12-26
