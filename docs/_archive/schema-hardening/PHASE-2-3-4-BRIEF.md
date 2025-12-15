# Phases 2-4 Implementation Briefs

## Phase 2: API Contract Updates (3 hours)

### Objective
Update API resources, validation rules, and error handling to support new fiscal fields while maintaining backward compatibility.

### Key Tasks
1. **Update DocumentResource** - Add fiscal_category, status, computed fields to API responses
2. **Update Validation Rules** - Add conditional validation for fiscal documents
3. **Update Error Handling** - Map CHECK constraint and trigger exceptions to user-friendly errors

### Files to Modify
- `app/Http/Resources/DocumentResource.php`
- `app/Http/Requests/StoreDocumentRequest.php`
- `app/Http/Requests/UpdateDocumentRequest.php`
- `app/Exceptions/Handler.php`

### Success Criteria
- API responses include new fields (additive, non-breaking)
- Validation enforces fiscal document requirements
- Error messages are user-friendly
- Backward compatibility maintained

---

## Phase 3: Frontend Integration (2 hours)

### Objective
Coordinate with smart payments frontend to ensure compatibility with new fields and error codes.

### Key Tasks
1. **TypeScript Interfaces** - Update Document interface with new fields
2. **UI Components** - Add DocumentStatusBadge component
3. **Error Handling** - Handle new error codes (DOCUMENT_SEALED, FISCAL_MANDATORY_FIELDS_MISSING)
4. **Validation** - Run frontend tests to confirm integration

### Files to Create/Modify
- `frontend/src/types/Document.ts`
- `frontend/src/components/DocumentStatusBadge.tsx`
- `frontend/src/api/errorHandling.ts`

### Success Criteria
- TypeScript compilation succeeds
- Smart payments frontend functional with new fields
- New error codes handled gracefully
- Visual regression tests pass

---

## Phase 4: GL Posting Implementation (1 week)

### Objective
Implement DocumentPostingService that seals documents, creates GL entries, and populates country-specific metadata.

### Key Components

#### 4.1 DocumentPostingService
**Purpose:** Orchestrates document posting process
**Key Methods:**
- `post(Document $document): void` - Main posting method
- `validatePostable(Document $document): void` - Pre-posting validation
- `determineFiscalCategory(Document $document): FiscalCategory` - Determine fiscal category
- `sealDocument(Document $document): void` - Set status to SEALED

#### 4.2 FiscalMetadataService
**Purpose:** Create country-specific fiscal metadata
**Key Methods:**
- `createMetadata(Document $document): void` - Route to country-specific handler
- `createFrenchMetadata(Document $document): void` - NF525 metadata
- `createSaudiMetadata(Document $document): void` - ZATCA metadata
- `createGermanMetadata(Document $document): void` - TSE metadata

#### 4.3 HashChainService (if not exists)
**Purpose:** Calculate and verify hash chains
**Key Methods:**
- `calculateHash(Document $document): string` - Generate document hash
- `getPreviousHash(Document $document): string` - Get previous document hash
- `verifyHashChain(Document $document): bool` - Verify integrity

### Files to Create
- `app/Modules/Accounting/Application/Services/DocumentPostingService.php`
- `app/Modules/Accounting/Application/Services/FiscalMetadataService.php`
- `app/Modules/Accounting/Domain/Services/HashChainService.php` (if needed)
- `app/Modules/Accounting/Domain/Events/FiscalDocumentPosted.php`
- `app/Modules/Accounting/Domain/Exceptions/DocumentAlreadySealedException.php`
- `app/Modules/Accounting/Domain/Exceptions/VoidedDocumentCannotBePostedException.php`
- `tests/Feature/Accounting/DocumentPostingTest.php`
- `tests/Unit/Accounting/FiscalMetadataServiceTest.php`

### Implementation Flow

```php
// DocumentPostingService::post()
DB::transaction(function() use ($document) {
    // 1. Validate postable
    $this->validatePostable($document);
    
    // 2. Set fiscal category
    $document->fiscal_category = $this->determineFiscalCategory($document);
    
    // 3. Generate hash chain
    $document->hash = $this->hashService->calculateHash($document);
    $document->previous_hash = $this->hashService->getPreviousHash($document);
    
    // 4. SEAL document
    $document->status = DocumentStatus::SEALED;
    $document->save(); // Triggers prevent future modification
    
    // 5. Create GL entries
    $this->glService->createJournalEntriesForDocument($document);
    
    // 6. Dispatch event
    event(new FiscalDocumentPosted($document));
    
    // 7. Create country metadata
    $this->fiscalMetadataService->createMetadata($document);
});
```

### Test Coverage

#### Unit Tests
- FiscalCategory enum methods
- DocumentStatus enum methods
- HashChainService calculations
- FiscalMetadataService routing

#### Integration Tests
- Post invoice → sealed + GL entries created
- Post credit note → sealed + reversal GL entries
- French company → fr_fiscal_metadata created
- Saudi company → sa_fiscal_metadata created
- German company → de_fiscal_metadata created
- Cannot modify sealed document
- Can update balance_due on sealed
- Cannot delete fiscal document
- Hash chain integrity maintained

#### Edge Cases
- Post already sealed document → exception
- Post voided document → exception
- Post with missing fields → CHECK constraint error
- Concurrent posting → pessimistic locking

### Success Criteria
- Documents sealed correctly
- GL entries created atomically
- Hash chains computed and verified
- Country metadata populated based on company country
- Events dispatched
- All tests pass
- PHPStan level 8 clean

---

## Integration Testing (Phase 5)

### End-to-End Scenarios

#### Scenario 1: Create Invoice → Post → Pay
```bash
1. Create draft invoice (status=DRAFT)
2. Post invoice (status=SEALED, fiscal_category=TAX_INVOICE)
3. Verify GL entries created
4. Verify hash chain valid
5. Create payment allocation
6. Verify balance_due updated
7. Verify payment recorded correctly
```

#### Scenario 2: Try Modifying Sealed Document
```bash
1. Create and post invoice (status=SEALED)
2. Try to update total_amount → Should fail with trigger error
3. Verify error message user-friendly
4. Verify frontend handles error gracefully
```

#### Scenario 3: French Company Full Flow
```bash
1. Create French company (country_code='FR')
2. Create and post invoice
3. Verify fr_fiscal_metadata created
4. Verify NF525 sequence incremented
5. Verify signed XML snapshot present
```

#### Scenario 4: Smart Payment with FIFO
```bash
1. Create 3 open invoices (all SEALED)
2. Record payment with use_fifo=true
3. Verify oldest invoices paid first
4. Verify balance_due updated correctly
5. Verify payment allocations created
```

### Performance Testing
- Measure posting time with hash calculation
- Measure trigger execution overhead
- Benchmark query performance with new indexes
- Load test concurrent posting

### Regression Testing
- All existing tests pass
- No API contract breaking changes
- Frontend compatibility maintained
- Payment system functional

---

## Timeline Summary

| Phase | Duration | Key Deliverables |
|-------|----------|------------------|
| Phase 0 | 2h | Compatibility report, baseline tests |
| Phase 1 | 6h | Schema hardened, constraints, triggers |
| Phase 2 | 3h | API contracts updated |
| Phase 3 | 2h | Frontend integration validated |
| Phase 4 | 1 week | GL posting service implemented |
| Phase 5 | 2h | End-to-end testing complete |
| **Total** | **~9 days** | Production-ready fiscal compliance |

---

**Status:** Phase 1 specifications complete, Phases 2-4 briefs provided

**Next Action:** Claude Code Opus reviews all documentation, validates approach, then implements sequentially
