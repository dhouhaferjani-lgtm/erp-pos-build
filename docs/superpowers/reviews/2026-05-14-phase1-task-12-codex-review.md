# Task 12 Adversarial Review — payments origin + fiscal_event_id

**Verdict**: APPROVE-WITH-MINOR-EDITS
**Counts**: 0 BLOCKER / 0 P1 / 1 P2 / 0 P3 / 11 CLEAN

---

## Findings

### [CLEAN] FK direction is payments to fiscal_events
**File**: apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:50
**Evidence**:
```php
ALTER TABLE payments
ADD CONSTRAINT payments_fiscal_event_id_fk
FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)
```
**Finding**: The FK is declared on `payments.fiscal_event_id` and references `fiscal_events.id`. This matches Task 12's required direction and does not introduce a fiscal engine dependency on Treasury.
**Recommendation**: No change.

### [CLEAN] PaymentOrigin case order has no positional production usage
**File**: apps/api/app/Modules/Treasury/Domain/Enums/PaymentOrigin.php:33
**Evidence**:
```php
case Pos = 'pos';
case WebAdmin = 'web_admin';
case Mobile = 'mobile';
case Api = 'api';
case UnknownLegacy = 'unknown_legacy';
```
`grep -rn 'PaymentOrigin::cases()\[' apps/api` returned no matches.
**Finding**: The committed enum order matches the Task 12 assertion, and no production or test code currently indexes `PaymentOrigin::cases()` by ordinal.
**Recommendation**: No change.

### [CLEAN] No origin CHECK constraint, and Task 12 did not require one
**File**: docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:936
**Evidence**:
```text
Migration: `payments.origin VARCHAR(32) NULL`, `payments.fiscal_event_id UUID NULL` with FK → `fiscal_events(id)`.
```
**Finding**: The migration adds `origin` as `string('origin', 32)->nullable()` but does not add a database CHECK whitelist. Unlike Task 7's fiscal event type constraint, the Task 12 plan only calls for `VARCHAR(32)` plus the PHP backed enum, so the absence of a CHECK is not a confirmed plan violation.
**Recommendation**: No change for Task 12. If later tasks rely on raw SQL writes to `payments.origin`, consider adding a PostgreSQL CHECK in that task.

### [CLEAN] No current request mass-assignment path can set origin or fiscal_event_id
**File**: apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:103
**Evidence**:
```php
$validated = $request->validate([
    'partner_id' => [
```
and:
```php
$payment = Payment::create([
    'tenant_id' => $tenantId,
    'company_id' => $companyId,
```
**Finding**: `Payment::$fillable` now includes `origin` and `fiscal_event_id`, but the reviewed Treasury request paths validate fixed allowlists and then construct explicit `Payment::create([...])` arrays. I did not find `Payment::create($validated)`, `Payment::create($request->all())`, or `Payment->update($request->all())` against the Treasury `Payment` model.
**Recommendation**: No change in this commit. Task 22 should stamp `origin` explicitly in every writer listed by the plan.

### [CLEAN] No DB write-once trigger for payments.fiscal_event_id, but no Task 12 invariant requires one
**File**: apps/api/app/Modules/Treasury/Domain/Payment.php:103
**Evidence**:
```php
'origin',
'fiscal_event_id',
```
Task 8's analogous write-once guard is explicitly scoped to `fiscal_events.integrity_exception_class`:
```php
// Step 1b (round-2 BLOCKER fix): integrity_exception_class is write-once.
```
**Finding**: `payments.fiscal_event_id` is fillable and there is no DB trigger preventing later updates. That differs from Task 8's fiscal-events immutability trigger, but `payments` is a Treasury projection table, not immutable fiscal chain truth, and neither the Task 12 plan nor the Phase 1 spec requires a write-once DB invariant here. I also did not confirm a current writer that overwrites this field.
**Recommendation**: No change for Task 12. If Task 22 introduces update/replay code that can mutate existing `Payment` rows, add a focused guard there.

### [CLEAN] No UNIQUE on payments.fiscal_event_id is intentional and documented
**File**: apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:33
**Evidence**:
```php
* No UNIQUE on `fiscal_event_id` here: unlike `pos_receipts` (where the
* UNIQUE is the projection idempotency anchor for the POS-core
* projector), a single fiscal event can have multiple Treasury
* `Payment` rows (one per payment line — `ReceiptPayment` becomes one
* Payment per tender).
```
**Finding**: The migration explains why Task 12 intentionally differs from Task 11: one fiscal event can project to multiple Treasury payment rows.
**Recommendation**: No change.

### [CLEAN] TreasuryReceiptBridge idempotency is specified outside the payments table
**File**: docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:447
**Evidence**:
```text
UNIQUE (fiscal_event_id, projector_name)
```
and:
```text
Apply: each projection job runs `projector.apply(fiscalEvent)` in its own transaction, idempotently (keyed on `(fiscal_event_id, projector_name)`).
```
Task 22 repeats the bridge contract:
```text
`apply(FiscalEvent $event)` — idempotent, keyed on `(fiscal_event.id, 'treasury_receipt_bridge')`.
```
**Finding**: There is no DB UNIQUE on `payments.fiscal_event_id`, but the spec and Task 22 plan document the idempotency guarantee at the projector/projection-row level. That satisfies the architectural guarantee the migration comment points at, even though the durable DB uniqueness lives in `fiscal_event_projections`, not `payments`.
**Recommendation**: No change.

### [CLEAN] Partial index predicate is compatible with fiscal_event_id equality lookups
**File**: apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:61
**Evidence**:
```sql
CREATE INDEX payments_fiscal_event_id_idx
    ON payments (fiscal_event_id)
    WHERE fiscal_event_id IS NOT NULL
```
**Finding**: A Task 22 lookup shaped as `WHERE fiscal_event_id = ?` implies `fiscal_event_id IS NOT NULL`, so PostgreSQL can use this partial index. A composite index is not required by the documented Task 22 idempotency check, which is keyed by fiscal event/projector rather than a broader payment search.
**Recommendation**: No change.

### [CLEAN] CI PostgreSQL gate includes PaymentsOriginColumnsTest
**File**: .github/workflows/ci.yml:337
**Evidence**:
```yaml
#   - PaymentsOriginColumnsTest (Feature, Task 12): PG-only FK
```
and:
```yaml
--filter="VoucherLedgerTest|VoucherLedgerAppendOnlyTest|VoucherSchemaTest|FiscalHardeningE2ETest|FiscalEventsTableTest|FiscalEventsImmutabilityTest|FiscalEventProjectionsTableTest|FiscalEventQuarantineTableTest|PosReceiptsCanonicalBytesTest|PaymentsOriginColumnsTest"
```
**Finding**: The new Task 12 feature test is included in the PostgreSQL CI merge gate.
**Recommendation**: No change.

### [CLEAN] Factory and fillable-test isolation risk is not confirmed
**File**: apps/api/database/factories/PaymentFactory.php:71
**Evidence**:
```php
return [
    'id' => Str::uuid()->toString(),
    'tenant_id' => $tenant->id,
```
Existing `Payment` fillable tests assert contains, not an exact list:
```php
$this->assertContains('tenant_id', $fillable);
```
**Finding**: `PaymentFactory` does not define `origin` or `fiscal_event_id`, but both new columns are nullable in the migration. Existing fillable tests do not assert an exact array length or exact ordered list, and the new Task 12 test explicitly asserts the new fillable entries.
**Recommendation**: No change.

### [P2] Test does not enforce origin VARCHAR(32) length
**File**: apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php:83
**Evidence**:
```php
foreach (['origin' => 'character varying', 'fiscal_event_id' => 'uuid'] as $column => $expectedType) {
```
and:
```php
$this->assertSame($expectedType, $row->data_type, "{$column} data_type drift");
$this->assertSame('YES', $row->is_nullable, "{$column} must be nullable for legacy rows");
```
**Finding**: The migration creates `origin` as `VARCHAR(32)`, but the PostgreSQL test only checks `data_type = 'character varying'` and nullability. A drift to `VARCHAR(64)` would still pass.
**Recommendation**: Add `character_maximum_length` to the `information_schema.columns` query and assert `origin` has length `32`.

### [CLEAN] Rollback order is safe
**File**: apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:70
**Evidence**:
```php
DB::statement('DROP INDEX IF EXISTS payments_fiscal_event_id_idx');
DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_fiscal_event_id_fk');
```
and:
```php
$table->dropColumn(['origin', 'fiscal_event_id']);
```
**Finding**: `down()` drops the dependent partial index first, then the FK constraint, then the columns. That avoids dropping a constrained/indexed column before its dependent database objects.
**Recommendation**: No change.

---

## Summary
Task 12's schema/model changes match the requested dependency direction and nullable additive shape: `payments.fiscal_event_id` points to `fiscal_events.id`, no UNIQUE is added, and the partial index is compatible with equality lookups. The existing Treasury writers do not expose the newly fillable fields through request mass assignment. The only confirmed issue is test coverage: `PaymentsOriginColumnsTest` should assert `origin.character_maximum_length = 32` so the declared `VARCHAR(32)` contract is actually locked.
