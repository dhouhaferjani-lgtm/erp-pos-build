# Phase 1: Schema Hardening

**Duration:** 6 hours  
**Priority:** HIGH - Foundation for all subsequent phases  
**Prerequisites:** Phase 0 complete, baseline tests pass

---

## Overview

This phase adds database-level fiscal compliance hardening to the documents table through:
1. Core columns (`fiscal_category`, `status`)
2. CHECK constraints for mandatory fields
3. Extension tables for country-specific metadata
4. Immutability triggers for sealed documents
5. Model and enum updates

---

## Step 1.1: Add Core Columns (1 hour)

### Migration 1: Add fiscal_category and status

```bash
# Create migration
php artisan make:migration add_fiscal_fields_to_documents_table --table=documents
```

**File:** `database/migrations/2024_12_10_100001_add_fiscal_fields_to_documents_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Add fiscal_category column
            $table->string('fiscal_category', 20)
                ->default('NON_FISCAL')
                ->after('document_type');
            
            // Add status column
            $table->string('status', 20)
                ->default('DRAFT')
                ->after('fiscal_category');
            
            // Add index for common queries
            $table->index('status');
            $table->index(['fiscal_category', 'status']);
        });
        
        // Backfill fiscal_category based on document_type
        DB::statement("
            UPDATE documents 
            SET fiscal_category = CASE 
                WHEN document_type = 'invoice' THEN 'TAX_INVOICE'
                WHEN document_type = 'credit_note' THEN 'CREDIT_NOTE'
                WHEN document_type = 'receipt' THEN 'FISCAL_RECEIPT'
                ELSE 'NON_FISCAL'
            END
            WHERE fiscal_category = 'NON_FISCAL'
        ");
        
        // Backfill status based on whether document is posted
        // Assuming documents with document_number are posted/sealed
        DB::statement("
            UPDATE documents 
            SET status = CASE 
                WHEN document_number IS NOT NULL AND document_date IS NOT NULL THEN 'SEALED'
                ELSE 'DRAFT'
            END
            WHERE status = 'DRAFT'
        ");
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['documents_status_index']);
            $table->dropIndex(['documents_fiscal_category_status_index']);
            $table->dropColumn(['fiscal_category', 'status']);
        });
    }
};
```

### Run Migration

```bash
# Run migration
php artisan migrate --step

# Verify columns added
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
echo 'fiscal_category: ' . (Schema::hasColumn('documents', 'fiscal_category') ? '✓' : '✗') . PHP_EOL;
echo 'status: ' . (Schema::hasColumn('documents', 'status') ? '✓' : '✗') . PHP_EOL;
"

# Verify backfill
php artisan tinker --execute="
\$counts = DB::table('documents')
    ->select('fiscal_category', 'status', DB::raw('COUNT(*) as count'))
    ->groupBy('fiscal_category', 'status')
    ->get();

echo 'Document counts by category and status:' . PHP_EOL;
foreach (\$counts as \$row) {
    echo sprintf('  %s / %s: %d%s', \$row->fiscal_category, \$row->status, \$row->count, PHP_EOL);
}
"

# Expected output:
# fiscal_category: ✓
# status: ✓
# Document counts by category and status:
#   TAX_INVOICE / SEALED: 150
#   CREDIT_NOTE / SEALED: 20
#   NON_FISCAL / DRAFT: 5
```

### Commit Point 1

```bash
git add database/migrations/*add_fiscal_fields*
git commit -m "feat(accounting): add fiscal_category and status columns to documents

- Add fiscal_category enum column (NON_FISCAL, FISCAL_RECEIPT, TAX_INVOICE, CREDIT_NOTE)
- Add status enum column (DRAFT, SEALED, VOIDED)
- Backfill fiscal_category based on document_type
- Backfill status based on document_number presence
- Add indexes for common queries

Relates to schema hardening Phase 1.1"
```

---

## Step 1.2: Add CHECK Constraint (1 hour)

### Migration 2: Add fiscal mandatory fields constraint

```bash
# Create migration
php artisan make:migration add_fiscal_mandatory_constraint_to_documents --table=documents
```

**File:** `database/migrations/2024_12_10_100002_add_fiscal_mandatory_constraint_to_documents.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add CHECK constraint for fiscal documents
        DB::statement("
            ALTER TABLE documents
            ADD CONSTRAINT chk_fiscal_mandatory_core
            CHECK (
                fiscal_category = 'NON_FISCAL'
                OR (
                    document_date IS NOT NULL
                    AND document_number IS NOT NULL
                    AND total_amount IS NOT NULL
                    AND currency_code IS NOT NULL
                    AND hash IS NOT NULL
                    AND previous_hash IS NOT NULL
                )
            )
        ");
        
        // Add CHECK constraint for fiscal_category values
        DB::statement("
            ALTER TABLE documents
            ADD CONSTRAINT chk_fiscal_category_enum
            CHECK (fiscal_category IN ('NON_FISCAL', 'FISCAL_RECEIPT', 'TAX_INVOICE', 'CREDIT_NOTE'))
        ");
        
        // Add CHECK constraint for status values
        DB::statement("
            ALTER TABLE documents
            ADD CONSTRAINT chk_status_enum
            CHECK (status IN ('DRAFT', 'SEALED', 'VOIDED'))
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_mandatory_core");
        DB::statement("ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_category_enum");
        DB::statement("ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_status_enum");
    }
};
```

### Run Migration

```bash
# Run migration
php artisan migrate --step

# Verify constraints exist
php artisan tinker --execute="
\$constraints = DB::select(\"
    SELECT constraint_name, constraint_type
    FROM information_schema.table_constraints
    WHERE table_name = 'documents'
    AND constraint_type = 'CHECK'
\");

echo 'CHECK constraints on documents:' . PHP_EOL;
foreach (\$constraints as \$c) {
    echo '  - ' . \$c->constraint_name . PHP_EOL;
}
"

# Expected output:
# CHECK constraints on documents:
#   - chk_fiscal_mandatory_core
#   - chk_fiscal_category_enum
#   - chk_status_enum
```

### Test Constraint Enforcement

```bash
# Create test file
cat > tests/Feature/FiscalConstraintTest.php << 'EOF'
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;

class FiscalConstraintTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function fiscal_document_requires_mandatory_fields(): void
    {
        $this->expectException(QueryException::class);
        
        Document::create([
            'tenant_id' => 'tenant-uuid',
            'company_id' => 'company-uuid',
            'fiscal_category' => 'TAX_INVOICE',
            'document_type' => 'invoice',
            // Missing: document_number, document_date, total_amount, etc.
        ]);
    }

    /** @test */
    public function non_fiscal_document_allows_null_fields(): void
    {
        $doc = Document::create([
            'tenant_id' => 'tenant-uuid',
            'company_id' => 'company-uuid',
            'fiscal_category' => 'NON_FISCAL',
            'document_type' => 'quote',
            'status' => 'DRAFT',
            // No document_number, document_date, etc. - should be OK
        ]);

        $this->assertDatabaseHas('documents', [
            'id' => $doc->id,
            'fiscal_category' => 'NON_FISCAL',
        ]);
    }

    /** @test */
    public function fiscal_document_with_all_fields_succeeds(): void
    {
        $doc = Document::create([
            'tenant_id' => 'tenant-uuid',
            'company_id' => 'company-uuid',
            'fiscal_category' => 'TAX_INVOICE',
            'document_type' => 'invoice',
            'document_number' => 'INV-001',
            'document_date' => now(),
            'total_amount' => '100.00',
            'currency_code' => 'TND',
            'hash' => 'abc123',
            'previous_hash' => 'def456',
            'status' => 'SEALED',
        ]);

        $this->assertDatabaseHas('documents', [
            'id' => $doc->id,
            'fiscal_category' => 'TAX_INVOICE',
        ]);
    }

    /** @test */
    public function invalid_fiscal_category_rejected(): void
    {
        $this->expectException(QueryException::class);
        
        Document::create([
            'tenant_id' => 'tenant-uuid',
            'company_id' => 'company-uuid',
            'fiscal_category' => 'INVALID_CATEGORY',
            'document_type' => 'invoice',
        ]);
    }

    /** @test */
    public function invalid_status_rejected(): void
    {
        $this->expectException(QueryException::class);
        
        Document::create([
            'tenant_id' => 'tenant-uuid',
            'company_id' => 'company-uuid',
            'fiscal_category' => 'NON_FISCAL',
            'status' => 'INVALID_STATUS',
        ]);
    }
}
EOF

# Run constraint tests
php artisan test tests/Feature/FiscalConstraintTest.php --stop-on-failure

# Expected output:
# FiscalConstraintTest
#   ✓ fiscal document requires mandatory fields
#   ✓ non fiscal document allows null fields
#   ✓ fiscal document with all fields succeeds
#   ✓ invalid fiscal category rejected
#   ✓ invalid status rejected
# Tests: 5 passed
```

### Commit Point 2

```bash
git add database/migrations/*add_fiscal_mandatory_constraint*
git add tests/Feature/FiscalConstraintTest.php
git commit -m "feat(accounting): add CHECK constraints for fiscal compliance

- Add chk_fiscal_mandatory_core: enforce mandatory fields for fiscal docs
- Add chk_fiscal_category_enum: restrict fiscal_category values
- Add chk_status_enum: restrict status values
- Add tests validating constraint enforcement

Relates to schema hardening Phase 1.2"
```

---

## Step 1.3: Create Extension Tables (1 hour)

### Migration 3: Create fiscal metadata extension tables

```bash
# Create migration
php artisan make:migration create_fiscal_metadata_tables
```

**File:** `database/migrations/2024_12_10_100003_create_fiscal_metadata_tables.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // France NF525 metadata
        Schema::create('fr_fiscal_metadata', function (Blueprint $table) {
            $table->uuid('document_id')->primary();
            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');
            
            $table->integer('nf525_sequence_no')->unique();
            $table->uuid('z_report_id')->nullable();
            $table->uuid('period_closure_id')->nullable();
            $table->text('signed_xml_snapshot');
            $table->binary('signature');
            
            $table->timestampTz('created_at')->useCurrent();
            
            $table->index('z_report_id');
            $table->index('period_closure_id');
            $table->index('nf525_sequence_no');
        });
        
        // Saudi Arabia ZATCA metadata
        Schema::create('sa_fiscal_metadata', function (Blueprint $table) {
            $table->uuid('document_id')->primary();
            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');
            
            $table->uuid('zatca_uuid')->unique();
            $table->text('cryptographic_stamp');
            $table->text('qr_code_data');
            $table->text('xml_ubl_snapshot');
            $table->jsonb('clearance_response')->nullable();
            
            $table->timestampTz('created_at')->useCurrent();
            
            $table->index('zatca_uuid');
        });
        
        // Germany TSE metadata
        Schema::create('de_fiscal_metadata', function (Blueprint $table) {
            $table->uuid('document_id')->primary();
            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');
            
            $table->bigInteger('tse_transaction_id');
            $table->string('tse_serial_number', 50);
            $table->binary('tse_signature');
            $table->boolean('dsfinvk_export_ready')->default(false);
            
            $table->timestampTz('created_at')->useCurrent();
            
            $table->index('tse_transaction_id');
            $table->index('tse_serial_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('de_fiscal_metadata');
        Schema::dropIfExists('sa_fiscal_metadata');
        Schema::dropIfExists('fr_fiscal_metadata');
    }
};
```

### Run Migration

```bash
# Run migration
php artisan migrate --step

# Verify tables created
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
\$tables = ['fr_fiscal_metadata', 'sa_fiscal_metadata', 'de_fiscal_metadata'];
foreach (\$tables as \$table) {
    echo \$table . ': ' . (Schema::hasTable(\$table) ? '✓' : '✗') . PHP_EOL;
}
"

# Expected output:
# fr_fiscal_metadata: ✓
# sa_fiscal_metadata: ✓
# de_fiscal_metadata: ✓

# Verify foreign keys
php artisan tinker --execute="
\$fks = DB::select(\"
    SELECT 
        tc.table_name, 
        kcu.column_name, 
        ccu.table_name AS foreign_table_name
    FROM information_schema.table_constraints AS tc 
    JOIN information_schema.key_column_usage AS kcu
        ON tc.constraint_name = kcu.constraint_name
    JOIN information_schema.constraint_column_usage AS ccu
        ON ccu.constraint_name = tc.constraint_name
    WHERE tc.constraint_type = 'FOREIGN KEY'
    AND tc.table_name LIKE '%_fiscal_metadata'
\");

echo 'Foreign keys:' . PHP_EOL;
foreach (\$fks as \$fk) {
    echo sprintf('  %s.%s -> %s%s', \$fk->table_name, \$fk->column_name, \$fk->foreign_table_name, PHP_EOL);
}
"

# Expected output:
# Foreign keys:
#   fr_fiscal_metadata.document_id -> documents
#   sa_fiscal_metadata.document_id -> documents
#   de_fiscal_metadata.document_id -> documents
```

### Commit Point 3

```bash
git add database/migrations/*create_fiscal_metadata_tables*
git commit -m "feat(accounting): create fiscal metadata extension tables

- Add fr_fiscal_metadata for France NF525 compliance
- Add sa_fiscal_metadata for Saudi Arabia ZATCA compliance  
- Add de_fiscal_metadata for Germany TSE/KassenSichV compliance
- Add foreign key constraints to documents table
- Add indexes for common queries

Relates to schema hardening Phase 1.3"
```

---

## Step 1.4: Add Immutability Triggers (2 hours)

### Migration 4: Add immutability triggers

```bash
# Create migration
php artisan make:migration add_immutability_triggers_to_documents
```

**File:** `database/migrations/2024_12_10_100004_add_immutability_triggers_to_documents.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Trigger 1: Prevent modification of sealed documents
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_sealed_modification()
            RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.status = 'SEALED' THEN
                    -- Check if any immutable field changed
                    IF (OLD.document_date IS DISTINCT FROM NEW.document_date OR
                        OLD.document_number IS DISTINCT FROM NEW.document_number OR
                        OLD.total_amount IS DISTINCT FROM NEW.total_amount OR
                        OLD.tax_amount IS DISTINCT FROM NEW.tax_amount OR
                        OLD.hash IS DISTINCT FROM NEW.hash OR
                        OLD.previous_hash IS DISTINCT FROM NEW.previous_hash OR
                        OLD.fiscal_category IS DISTINCT FROM NEW.fiscal_category OR
                        OLD.partner_id IS DISTINCT FROM NEW.partner_id OR
                        OLD.currency_code IS DISTINCT FROM NEW.currency_code) THEN
                        
                        RAISE EXCEPTION 'Cannot modify sealed fiscal document %. Immutable fields: date, number, amount, tax, hash, category, partner, currency', 
                            OLD.document_number
                            USING HINT = 'Use credit notes or voiding to correct sealed documents',
                                  ERRCODE = '23514'; -- check_violation
                    END IF;
                    
                    -- Allow these fields to change even on sealed documents:
                    -- - balance_due (updated by payments)
                    -- - status (can transition from SEALED to VOIDED)
                    -- - updated_at (automatically maintained)
                END IF;
                
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_prevent_sealed_modification
            BEFORE UPDATE ON documents
            FOR EACH ROW
            EXECUTE FUNCTION prevent_sealed_modification();
        ");

        // Trigger 2: Prevent deletion of fiscal documents
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_fiscal_deletion()
            RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.fiscal_category <> 'NON_FISCAL' THEN
                    RAISE EXCEPTION 'Cannot delete fiscal document % (%). Use voiding instead.', 
                        OLD.document_number,
                        OLD.fiscal_category
                        USING HINT = 'Set status = VOIDED to cancel fiscal documents',
                              ERRCODE = '23514'; -- check_violation
                END IF;
                
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_prevent_fiscal_deletion
            BEFORE DELETE ON documents
            FOR EACH ROW
            EXECUTE FUNCTION prevent_fiscal_deletion();
        ");
    }

    public function down(): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS trg_prevent_fiscal_deletion ON documents");
        DB::unprepared("DROP FUNCTION IF EXISTS prevent_fiscal_deletion");
        
        DB::unprepared("DROP TRIGGER IF EXISTS trg_prevent_sealed_modification ON documents");
        DB::unprepared("DROP FUNCTION IF EXISTS prevent_sealed_modification");
    }
};
```

### Run Migration

```bash
# Run migration
php artisan migrate --step

# Verify triggers created
php artisan tinker --execute="
\$triggers = DB::select(\"
    SELECT trigger_name, event_manipulation, event_object_table
    FROM information_schema.triggers
    WHERE event_object_table = 'documents'
\");

echo 'Triggers on documents:' . PHP_EOL;
foreach (\$triggers as \$t) {
    echo sprintf('  %s (%s)%s', \$t->trigger_name, \$t->event_manipulation, PHP_EOL);
}
"

# Expected output:
# Triggers on documents:
#   trg_prevent_sealed_modification (UPDATE)
#   trg_prevent_fiscal_deletion (DELETE)
```

### Test Trigger Enforcement

```bash
# Create test file
cat > tests/Feature/ImmutabilityTriggerTest.php << 'EOF'
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;

class ImmutabilityTriggerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function cannot_modify_sealed_document_immutable_fields(): void
    {
        $doc = Document::factory()->create([
            'status' => 'SEALED',
            'fiscal_category' => 'TAX_INVOICE',
            'document_number' => 'INV-001',
            'total_amount' => '100.00',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot modify sealed fiscal document');

        $doc->total_amount = '999.99';
        $doc->save();
    }

    /** @test */
    public function can_update_balance_due_on_sealed_document(): void
    {
        $doc = Document::factory()->create([
            'status' => 'SEALED',
            'fiscal_category' => 'TAX_INVOICE',
            'balance_due' => '100.00',
        ]);

        $doc->balance_due = '50.00';
        $doc->save(); // Should succeed

        $this->assertEquals('50.00', $doc->fresh()->balance_due);
    }

    /** @test */
    public function can_void_sealed_document(): void
    {
        $doc = Document::factory()->create([
            'status' => 'SEALED',
            'fiscal_category' => 'TAX_INVOICE',
        ]);

        $doc->status = 'VOIDED';
        $doc->save(); // Should succeed

        $this->assertEquals('VOIDED', $doc->fresh()->status);
    }

    /** @test */
    public function can_modify_draft_document(): void
    {
        $doc = Document::factory()->create([
            'status' => 'DRAFT',
            'total_amount' => '100.00',
        ]);

        $doc->total_amount = '200.00';
        $doc->save(); // Should succeed

        $this->assertEquals('200.00', $doc->fresh()->total_amount);
    }

    /** @test */
    public function cannot_delete_fiscal_document(): void
    {
        $doc = Document::factory()->create([
            'fiscal_category' => 'TAX_INVOICE',
            'document_number' => 'INV-001',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot delete fiscal document');

        $doc->delete();
    }

    /** @test */
    public function can_delete_non_fiscal_document(): void
    {
        $doc = Document::factory()->create([
            'fiscal_category' => 'NON_FISCAL',
        ]);

        $doc->delete(); // Should succeed

        $this->assertDatabaseMissing('documents', [
            'id' => $doc->id,
        ]);
    }
}
EOF

# Run trigger tests
php artisan test tests/Feature/ImmutabilityTriggerTest.php --stop-on-failure

# Expected output:
# ImmutabilityTriggerTest
#   ✓ cannot modify sealed document immutable fields
#   ✓ can update balance due on sealed document
#   ✓ can void sealed document
#   ✓ can modify draft document
#   ✓ cannot delete fiscal document
#   ✓ can delete non fiscal document
# Tests: 6 passed
```

### Commit Point 4

```bash
git add database/migrations/*add_immutability_triggers*
git add tests/Feature/ImmutabilityTriggerTest.php
git commit -m "feat(accounting): add immutability triggers for sealed documents

- Add prevent_sealed_modification trigger: blocks changes to immutable fields
- Add prevent_fiscal_deletion trigger: blocks deletion of fiscal documents
- Allow balance_due updates on sealed documents (for payments)
- Allow status transitions (SEALED -> VOIDED)
- Add tests validating trigger enforcement

Relates to schema hardening Phase 1.4"
```

---

## Step 1.5: Update Models and Enums (1 hour)

### Create Enums

```bash
# Create FiscalCategory enum
cat > app/Modules/Accounting/Domain/Enums/FiscalCategory.php << 'EOF'
<?php

namespace App\Modules\Accounting\Domain\Enums;

enum FiscalCategory: string
{
    case NON_FISCAL = 'NON_FISCAL';
    case FISCAL_RECEIPT = 'FISCAL_RECEIPT';
    case TAX_INVOICE = 'TAX_INVOICE';
    case CREDIT_NOTE = 'CREDIT_NOTE';

    public function isFiscal(): bool
    {
        return $this !== self::NON_FISCAL;
    }

    public function requiresMandatoryFields(): bool
    {
        return $this->isFiscal();
    }
}
EOF

# Create DocumentStatus enum
cat > app/Modules/Accounting/Domain/Enums/DocumentStatus.php << 'EOF'
<?php

namespace App\Modules\Accounting\Domain\Enums;

enum DocumentStatus: string
{
    case DRAFT = 'DRAFT';
    case SEALED = 'SEALED';
    case VOIDED = 'VOIDED';

    public function isModifiable(): bool
    {
        return $this === self::DRAFT;
    }

    public function canReceivePayment(): bool
    {
        return $this === self::SEALED;
    }

    public function isSealed(): bool
    {
        return $this === self::SEALED;
    }

    public function isVoided(): bool
    {
        return $this === self::VOIDED;
    }
}
EOF
```

### Update Document Model

```bash
# Update app/Models/Document.php
cat >> app/Models/Document.php << 'EOF'

use App\Modules\Accounting\Domain\Enums\FiscalCategory;
use App\Modules\Accounting\Domain\Enums\DocumentStatus;

class Document extends Model
{
    protected $fillable = [
        // ... existing fields ...
        'fiscal_category',
        'status',
    ];

    protected $casts = [
        // ... existing casts ...
        'fiscal_category' => FiscalCategory::class,
        'status' => DocumentStatus::class,
    ];

    // Relationships to extension tables
    public function frFiscalMetadata(): HasOne
    {
        return $this->hasOne(FrFiscalMetadata::class);
    }

    public function saFiscalMetadata(): HasOne
    {
        return $this->hasOne(SaFiscalMetadata::class);
    }

    public function deFiscalMetadata(): HasOne
    {
        return $this->hasOne(DeFiscalMetadata::class);
    }

    // Scopes
    public function scopeSealed($query)
    {
        return $query->where('status', DocumentStatus::SEALED);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', DocumentStatus::DRAFT);
    }

    public function scopeFiscal($query)
    {
        return $query->where('fiscal_category', '!=', FiscalCategory::NON_FISCAL);
    }

    public function scopeNonFiscal($query)
    {
        return $query->where('fiscal_category', FiscalCategory::NON_FISCAL);
    }

    // Computed attributes
    public function getIsSealedAttribute(): bool
    {
        return $this->status === DocumentStatus::SEALED;
    }

    public function getCanReceivePaymentAttribute(): bool
    {
        return $this->status->canReceivePayment();
    }

    public function getIsModifiableAttribute(): bool
    {
        return $this->status->isModifiable();
    }
}
EOF
```

### Create Metadata Models

```bash
# Create FrFiscalMetadata model
cat > app/Models/FrFiscalMetadata.php << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FrFiscalMetadata extends Model
{
    protected $table = 'fr_fiscal_metadata';
    protected $primaryKey = 'document_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'nf525_sequence_no',
        'z_report_id',
        'period_closure_id',
        'signed_xml_snapshot',
        'signature',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
EOF

# Create SaFiscalMetadata model
cat > app/Models/SaFiscalMetadata.php << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaFiscalMetadata extends Model
{
    protected $table = 'sa_fiscal_metadata';
    protected $primaryKey = 'document_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'zatca_uuid',
        'cryptographic_stamp',
        'qr_code_data',
        'xml_ubl_snapshot',
        'clearance_response',
    ];

    protected $casts = [
        'clearance_response' => 'array',
        'created_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
EOF

# Create DeFiscalMetadata model
cat > app/Models/DeFiscalMetadata.php << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeFiscalMetadata extends Model
{
    protected $table = 'de_fiscal_metadata';
    protected $primaryKey = 'document_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'tse_transaction_id',
        'tse_serial_number',
        'tse_signature',
        'dsfinvk_export_ready',
    ];

    protected $casts = [
        'dsfinvk_export_ready' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
EOF
```

### Run PHPStan

```bash
# Analyze new code
./vendor/bin/phpstan analyse app/Modules/Accounting/Domain/Enums --level=8
./vendor/bin/phpstan analyse app/Models/FrFiscalMetadata.php --level=8
./vendor/bin/phpstan analyse app/Models/SaFiscalMetadata.php --level=8
./vendor/bin/phpstan analyse app/Models/DeFiscalMetadata.php --level=8

# Expected: No errors
```

### Commit Point 5

```bash
git add app/Modules/Accounting/Domain/Enums/FiscalCategory.php
git add app/Modules/Accounting/Domain/Enums/DocumentStatus.php
git add app/Models/Document.php
git add app/Models/FrFiscalMetadata.php
git add app/Models/SaFiscalMetadata.php
git add app/Models/DeFiscalMetadata.php
git commit -m "feat(accounting): add enums and models for fiscal hardening

- Add FiscalCategory enum with fiscal validation methods
- Add DocumentStatus enum with state transition logic
- Update Document model with new fields and relationships
- Add FrFiscalMetadata, SaFiscalMetadata, DeFiscalMetadata models
- Add scopes for querying sealed/fiscal documents
- Add computed attributes for UI

Relates to schema hardening Phase 1.5"
```

---

## Phase 1 Final Verification

### Run Complete Test Suite

```bash
echo "=== PHASE 1 FINAL VERIFICATION ==="

# 1. Run all tests
php artisan test --stop-on-failure

# 2. Run baseline tests (from Phase 0) to confirm no breaking changes
php artisan test tests/Feature/PaymentSystemBaselineTest.php

# 3. Run new tests
php artisan test tests/Feature/FiscalConstraintTest.php
php artisan test tests/Feature/ImmutabilityTriggerTest.php

# 4. Verify all migrations applied
php artisan migrate:status

# 5. Verify database schema
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;

echo '=== Documents Table ===' . PHP_EOL;
echo 'fiscal_category: ' . (Schema::hasColumn('documents', 'fiscal_category') ? '✓' : '✗') . PHP_EOL;
echo 'status: ' . (Schema::hasColumn('documents', 'status') ? '✓' : '✗') . PHP_EOL;

echo PHP_EOL . '=== Extension Tables ===' . PHP_EOL;
echo 'fr_fiscal_metadata: ' . (Schema::hasTable('fr_fiscal_metadata') ? '✓' : '✗') . PHP_EOL;
echo 'sa_fiscal_metadata: ' . (Schema::hasTable('sa_fiscal_metadata') ? '✓' : '✗') . PHP_EOL;
echo 'de_fiscal_metadata: ' . (Schema::hasTable('de_fiscal_metadata') ? '✓' : '✗') . PHP_EOL;
"

# 6. Verify constraints
php artisan tinker --execute="
\$constraints = DB::select(\"
    SELECT constraint_name
    FROM information_schema.table_constraints
    WHERE table_name = 'documents'
    AND constraint_type = 'CHECK'
\");

echo '=== CHECK Constraints ===' . PHP_EOL;
foreach (\$constraints as \$c) {
    echo '✓ ' . \$c->constraint_name . PHP_EOL;
}
"

# 7. Verify triggers
php artisan tinker --execute="
\$triggers = DB::select(\"
    SELECT trigger_name
    FROM information_schema.triggers
    WHERE event_object_table = 'documents'
\");

echo '=== Triggers ===' . PHP_EOL;
foreach (\$triggers as \$t) {
    echo '✓ ' . \$t->trigger_name . PHP_EOL;
}
"

# 8. Run PHPStan
./vendor/bin/phpstan analyse app/Modules/Accounting --level=8
./vendor/bin/phpstan analyse app/Models --level=8

echo ""
echo "✓ Phase 1 Complete - Schema Hardening Successful"
```

### Expected Output

```
=== PHASE 1 FINAL VERIFICATION ===

Tests: 20 passed
Time: 00:05.123

PaymentSystemBaselineTest
  ✓ can fetch open invoices for partner
  ✓ can record payment and update balance due
  ✓ payment preview shows allocation

FiscalConstraintTest
  ✓ fiscal document requires mandatory fields
  ✓ non fiscal document allows null fields
  ✓ fiscal document with all fields succeeds
  ✓ invalid fiscal category rejected
  ✓ invalid status rejected

ImmutabilityTriggerTest
  ✓ cannot modify sealed document immutable fields
  ✓ can update balance due on sealed document
  ✓ can void sealed document
  ✓ can modify draft document
  ✓ cannot delete fiscal document
  ✓ can delete non fiscal document

=== Documents Table ===
fiscal_category: ✓
status: ✓

=== Extension Tables ===
fr_fiscal_metadata: ✓
sa_fiscal_metadata: ✓
de_fiscal_metadata: ✓

=== CHECK Constraints ===
✓ chk_fiscal_mandatory_core
✓ chk_fiscal_category_enum
✓ chk_status_enum

=== Triggers ===
✓ trg_prevent_sealed_modification
✓ trg_prevent_fiscal_deletion

PHPStan: [OK] No errors

✓ Phase 1 Complete - Schema Hardening Successful
```

---

## Phase 1 Completion Checklist

- [ ] Migration 1: fiscal_category and status columns added
- [ ] Migration 2: CHECK constraints added
- [ ] Migration 3: Extension tables created
- [ ] Migration 4: Immutability triggers added
- [ ] Enums created (FiscalCategory, DocumentStatus)
- [ ] Document model updated
- [ ] Metadata models created
- [ ] Constraint tests pass
- [ ] Trigger tests pass
- [ ] Baseline tests pass (no breaking changes)
- [ ] PHPStan clean
- [ ] All commits pushed

---

**Phase 1 Status:** ✅ Complete

**Next Phase:** Phase 2 - API Contract Updates

**Estimated Duration:** 3 hours
