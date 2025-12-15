# AutoERP Import Module

> Complete documentation for data import functionality including legacy migration,
> bulk imports, and the staging pattern.

---

## Overview

The Import module handles:
- Bulk import of products, customers, suppliers
- Legacy ERP migration (opening balances, historical data)
- Ongoing data feeds (price lists, inventory updates)
- Data validation and error handling

---

## Core Principles

### 1. Never Write Directly to Production

All imports go through a staging table first:

```
Upload CSV → Staging Table → Validation → Commit to Production
                               ↓
                          Error Report
```

### 2. Enforce Dependency Order

Imports must happen in the correct sequence to prevent orphaned data:

```
Level 1 (Foundation)
├── Settings / Tax Rates
└── Categories / Product Families

Level 2 (Entities)
├── Suppliers
└── Customers

Level 3 (Items)
└── Products (requires suppliers, categories)

Level 4 (State)
├── Stock Levels (requires products, locations)
└── Opening Balances (requires customers, suppliers)
```

### 3. Idempotent Imports

Re-running an import should produce the same result:
- Use external IDs for matching existing records
- Update if exists, create if not
- Never duplicate on re-import

---

## Import Job Lifecycle

```
pending → validating → processing → completed
              ↓            ↓
           failed    partially_completed
```

### Status Definitions

| Status | Meaning |
|--------|---------|
| `pending` | Job created, file uploaded, waiting to start |
| `validating` | Running validation rules on staging data |
| `processing` | Moving validated rows to production |
| `completed` | All rows successfully imported |
| `failed` | Critical error, no rows imported |
| `partially_completed` | Some rows imported, some failed |

---

## The Migration Wizard

For legacy ERP migrations, provide a guided flow:

### Step 1: Partners
```
"Let's set up your business partners"
├── Upload Customers (CSV)
├── Upload Suppliers (CSV)
└── Review and confirm
```

### Step 2: Catalog
```
"Now, let's add your products"
├── Upload Categories (optional)
├── Upload Products (CSV)
│   └── System validates supplier references
└── Review and confirm
```

### Step 3: Inventory
```
"Set your starting inventory levels"
├── Upload Stock Counts (CSV)
│   └── System validates product references
└── Review and confirm
```

### Step 4: Opening Balances
```
"Finally, set your starting financial position"
├── Upload Customer Balances
├── Upload Supplier Balances
└── System creates opening balance entries
```

---

## Import Types

### Customers / Suppliers

**Required Fields:**
- `name` - Company or person name
- `type` - customer, supplier, or both

**Optional Fields:**
- `code` - External/legacy ID
- `vat_number`
- `email`, `phone`
- `address_line_1`, `city`, `postal_code`, `country`
- `payment_terms` - Code reference
- `credit_limit`

**Validation Rules:**
- Name is required and non-empty
- VAT number format validation (by country)
- Email format validation
- Duplicate detection on `code` or `vat_number`

### Products

**Required Fields:**
- `sku` - Unique product code
- `name` - Product name

**Optional Fields:**
- `description`
- `barcode`
- `category` - Category code reference
- `brand` - Brand code reference
- `supplier` - Supplier code reference
- `purchase_price`
- `sale_price`
- `purchase_tax` - Tax code reference
- `sale_tax` - Tax code reference
- `oem_numbers` - Comma-separated list
- `cross_references` - Comma-separated list

**Validation Rules:**
- SKU is required and unique
- Category must exist if provided
- Supplier must exist if provided
- Prices must be positive numbers
- Tax codes must exist

### Stock Levels

**Required Fields:**
- `sku` - Product reference
- `location` - Location code
- `quantity` - Current quantity

**Optional Fields:**
- `average_cost`

**Validation Rules:**
- Product (SKU) must exist
- Location must exist
- Quantity must be >= 0

**Behavior:**
- Creates stock adjustment transaction
- Sets initial average cost if provided

### Opening Balances

**Required Fields:**
- `partner_code` - Customer or supplier code
- `amount` - Outstanding balance
- `type` - `receivable` or `payable`

**Optional Fields:**
- `reference` - Invoice/document reference
- `date` - Original date (defaults to cutoff date)
- `due_date`

**Behavior:**
- Creates synthetic opening balance document
- Creates journal entries for GL impact
- Does NOT create detailed invoice (just balance)

---

## Staging Pattern Implementation

### Database Schema

```sql
CREATE TABLE import_staging (
    id UUID PRIMARY KEY,
    job_id UUID NOT NULL REFERENCES import_jobs(id),
    row_number INTEGER NOT NULL,
    
    -- All data as strings (no type coercion yet)
    raw_data JSONB NOT NULL,
    
    -- Validation results
    is_valid BOOLEAN,
    validation_errors JSONB DEFAULT '[]',
    
    -- Processing results
    processed BOOLEAN DEFAULT FALSE,
    created_record_id UUID,
    error_message TEXT,
    
    created_at TIMESTAMPTZ DEFAULT NOW()
);
```

### Validation Process

```php
class ImportValidator
{
    public function validate(ImportJob $job): void
    {
        $job->update(['status' => 'validating']);
        
        $rules = $this->getRulesForType($job->import_type);
        
        ImportStaging::where('job_id', $job->id)
            ->chunk(100, function ($rows) use ($rules) {
                foreach ($rows as $row) {
                    $errors = [];
                    
                    foreach ($rules as $field => $rule) {
                        $value = $row->raw_data[$field] ?? null;
                        $error = $rule->validate($value);
                        if ($error) {
                            $errors[] = ['field' => $field, 'error' => $error];
                        }
                    }
                    
                    $row->update([
                        'is_valid' => empty($errors),
                        'validation_errors' => $errors,
                    ]);
                }
            });
        
        // Update job counts
        $job->update([
            'total_rows' => ImportStaging::where('job_id', $job->id)->count(),
            'error_rows' => ImportStaging::where('job_id', $job->id)
                ->where('is_valid', false)->count(),
        ]);
    }
}
```

### Processing (Commit to Production)

```php
class ImportProcessor
{
    public function process(ImportJob $job): void
    {
        $job->update(['status' => 'processing', 'started_at' => now()]);
        
        $transformer = $this->getTransformerForType($job->import_type);
        
        ImportStaging::where('job_id', $job->id)
            ->where('is_valid', true)
            ->where('processed', false)
            ->chunk(50, function ($rows) use ($transformer, $job) {
                DB::transaction(function () use ($rows, $transformer, $job) {
                    foreach ($rows as $row) {
                        try {
                            $record = $transformer->transform($row->raw_data);
                            $row->update([
                                'processed' => true,
                                'created_record_id' => $record->id,
                            ]);
                            $job->increment('success_rows');
                        } catch (\Exception $e) {
                            $row->update([
                                'processed' => true,
                                'error_message' => $e->getMessage(),
                            ]);
                            $job->increment('error_rows');
                        }
                    }
                });
            });
        
        $this->finalizeJob($job);
    }
}
```

---

## Smart Features

### 1. Smart Column Mapping

Use fuzzy matching to auto-suggest mappings:

```php
class SmartMapper
{
    private array $synonyms = [
        'name' => ['company', 'customer_name', 'supplier_name', 'nom'],
        'sku' => ['code', 'product_code', 'reference', 'ref', 'item_code'],
        'email' => ['e_mail', 'email_address', 'courriel'],
        'phone' => ['telephone', 'tel', 'phone_number', 'mobile'],
        'purchase_price' => ['cost', 'cost_price', 'buy_price', 'prix_achat'],
        'sale_price' => ['price', 'sell_price', 'retail_price', 'prix_vente'],
    ];
    
    public function suggestMapping(array $csvHeaders, array $targetFields): array
    {
        $suggestions = [];
        
        foreach ($csvHeaders as $header) {
            $normalized = $this->normalize($header);
            
            // Exact match
            if (in_array($normalized, $targetFields)) {
                $suggestions[$header] = [
                    'target' => $normalized,
                    'confidence' => 'high',
                ];
                continue;
            }
            
            // Synonym match
            foreach ($this->synonyms as $target => $synonyms) {
                if (in_array($normalized, $synonyms)) {
                    $suggestions[$header] = [
                        'target' => $target,
                        'confidence' => 'medium',
                    ];
                    break;
                }
            }
            
            // Fuzzy match (Levenshtein)
            // ...
        }
        
        return $suggestions;
    }
}
```

### 2. Excel Template Generator

Generate pre-formatted templates with:
- Correct headers
- Data validation (dropdowns for categories, suppliers)
- Example rows

```php
class TemplateGenerator
{
    public function generate(string $importType, Tenant $tenant): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Headers
        $headers = $this->getHeadersForType($importType);
        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header['label']);
            
            // Add comment with description
            $sheet->getComment([$col + 1, 1])
                ->getText()
                ->createTextRun($header['description']);
        }
        
        // Data validation for lookup fields
        if ($importType === 'products') {
            // Category dropdown
            $categories = Category::where('tenant_id', $tenant->id)
                ->pluck('code')
                ->implode(',');
            
            $validation = $sheet->getDataValidation('E2:E1000');
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setFormula1('"' . $categories . '"');
        }
        
        // Save and return path
        $writer = new Xlsx($spreadsheet);
        $path = storage_path("app/templates/{$importType}_template.xlsx");
        $writer->save($path);
        
        return $path;
    }
}
```

### 3. Fix-It-Here Grid

Display validation errors in an editable grid:

```typescript
// React component
function ImportValidationGrid({ jobId }) {
  const { data: rows } = useQuery(['import-staging', jobId], 
    () => fetchStagingRows(jobId));
  
  const updateRow = useMutation(updateStagingRow);
  
  return (
    <DataGrid
      rows={rows}
      columns={columns}
      getCellClassName={(params) => {
        const errors = params.row.validation_errors || [];
        const hasError = errors.some(e => e.field === params.field);
        return hasError ? 'cell-error' : '';
      }}
      onCellEditCommit={async (params) => {
        await updateRow.mutateAsync({
          id: params.id,
          field: params.field,
          value: params.value,
        });
        // Re-validate this row
        await revalidateRow(params.id);
      }}
    />
  );
}
```

### 4. Dependency Enforcement

```php
class ImportDependencyChecker
{
    private array $dependencies = [
        'products' => ['categories', 'suppliers'],
        'stock_levels' => ['products', 'locations'],
        'opening_balances' => ['customers', 'suppliers'],
    ];
    
    public function canImport(string $importType, Tenant $tenant): array
    {
        $missing = [];
        
        foreach ($this->dependencies[$importType] ?? [] as $dependency) {
            if (!$this->hasData($dependency, $tenant)) {
                $missing[] = $dependency;
            }
        }
        
        return [
            'allowed' => empty($missing),
            'missing' => $missing,
            'message' => empty($missing) 
                ? null 
                : "Please import " . implode(', ', $missing) . " first.",
        ];
    }
}
```

---

## Opening Balance Strategy

### For Inventory

Creates a "Stock Adjustment" transaction:

```php
class OpeningStockImporter
{
    public function import(array $data, Tenant $tenant): void
    {
        // Create adjustment document
        $adjustment = Document::create([
            'type' => 'stock_adjustment',
            'number' => 'ADJ-OPENING-' . now()->format('Ymd'),
            'date' => $tenant->opening_date,
            'status' => 'posted',
            'notes' => 'Opening inventory from legacy system',
        ]);
        
        foreach ($data as $row) {
            $product = Product::where('sku', $row['sku'])->first();
            $location = Location::where('code', $row['location'])->first();
            
            // Create stock level
            StockLevel::updateOrCreate(
                ['product_id' => $product->id, 'location_id' => $location->id],
                [
                    'quantity' => $row['quantity'],
                    'average_cost' => $row['average_cost'] ?? 0,
                ]
            );
            
            // Create movement record
            StockMovement::create([
                'product_id' => $product->id,
                'to_location_id' => $location->id,
                'type' => 'adjustment',
                'quantity' => $row['quantity'],
                'unit_cost' => $row['average_cost'] ?? 0,
                'document_id' => $adjustment->id,
                'reference' => 'Opening stock',
            ]);
        }
    }
}
```

### For Customer/Supplier Balances

Creates synthetic opening balance document:

```php
class OpeningBalanceImporter
{
    public function import(array $data, Tenant $tenant): void
    {
        foreach ($data as $row) {
            $partner = Partner::where('code', $row['partner_code'])->first();
            $isReceivable = $row['type'] === 'receivable';
            
            // Create opening balance document
            $doc = Document::create([
                'type' => 'opening_balance',
                'number' => 'OB-' . $partner->code,
                'partner_id' => $partner->id,
                'date' => $tenant->opening_date,
                'due_date' => $row['due_date'] ?? $tenant->opening_date,
                'total_ttc' => $row['amount'],
                'amount_due' => $row['amount'],
                'status' => 'posted',
                'notes' => 'Opening balance from legacy system',
                'payload' => [
                    'legacy_reference' => $row['reference'] ?? null,
                ],
            ]);
            
            // Create journal entry
            $entry = JournalEntry::create([
                'document_id' => $doc->id,
                'date' => $tenant->opening_date,
                'state' => 'posted',
                'auto_generated' => true,
            ]);
            
            JournalLine::create([
                'entry_id' => $entry->id,
                'account_id' => $isReceivable 
                    ? $partner->receivable_account_id 
                    : $partner->payable_account_id,
                'partner_id' => $partner->id,
                'debit' => $isReceivable ? $row['amount'] : 0,
                'credit' => $isReceivable ? 0 : $row['amount'],
            ]);
            
            JournalLine::create([
                'entry_id' => $entry->id,
                'account_id' => $tenant->opening_balance_account_id,
                'debit' => $isReceivable ? 0 : $row['amount'],
                'credit' => $isReceivable ? $row['amount'] : 0,
            ]);
        }
    }
}
```

---

## Legacy Archive Import

For historical documents (reference only, no GL impact):

```php
class LegacyArchiveImporter
{
    public function import(UploadedFile $zipFile, Partner $partner): void
    {
        $zip = new ZipArchive();
        $zip->open($zipFile->path());
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $content = $zip->getFromIndex($i);
            
            // Store as media attachment
            $media = Media::create([
                'type' => 'legacy_archive',
                'filename' => $filename,
                'mime_type' => $this->getMimeType($filename),
                'size' => strlen($content),
                'metadata' => [
                    'partner_id' => $partner->id,
                    'imported_at' => now(),
                    'source' => 'legacy_migration',
                ],
            ]);
            
            Storage::put("media/{$media->id}", $content);
        }
    }
}
```

---

## API Endpoints

```
# Import Jobs
GET    /api/v1/imports                     # List jobs
POST   /api/v1/imports                     # Create job (upload file)
GET    /api/v1/imports/{id}                # Job details
POST   /api/v1/imports/{id}/validate       # Start validation
POST   /api/v1/imports/{id}/process        # Commit to production
DELETE /api/v1/imports/{id}                # Cancel/delete job

# Staging Data
GET    /api/v1/imports/{id}/staging        # Get staging rows
PATCH  /api/v1/imports/{id}/staging/{rowId} # Update staging row
POST   /api/v1/imports/{id}/staging/{rowId}/revalidate

# Column Mapping
GET    /api/v1/imports/{id}/mapping/suggest # Get suggested mappings
POST   /api/v1/imports/{id}/mapping         # Save mapping

# Templates
GET    /api/v1/imports/templates/{type}     # Download template

# Dependency Check
GET    /api/v1/imports/can-import/{type}    # Check dependencies
```

---

## Error Handling

### Error Report Generation

```php
class ErrorReportGenerator
{
    public function generate(ImportJob $job): string
    {
        $errors = ImportStaging::where('job_id', $job->id)
            ->where('is_valid', false)
            ->orWhere('error_message', '!=', null)
            ->get();
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Headers
        $sheet->setCellValue('A1', 'Row');
        $sheet->setCellValue('B1', 'Field');
        $sheet->setCellValue('C1', 'Value');
        $sheet->setCellValue('D1', 'Error');
        
        $row = 2;
        foreach ($errors as $error) {
            foreach ($error->validation_errors as $fieldError) {
                $sheet->setCellValue("A{$row}", $error->row_number);
                $sheet->setCellValue("B{$row}", $fieldError['field']);
                $sheet->setCellValue("C{$row}", $error->raw_data[$fieldError['field']] ?? '');
                $sheet->setCellValue("D{$row}", $fieldError['error']);
                $row++;
            }
            
            if ($error->error_message) {
                $sheet->setCellValue("A{$row}", $error->row_number);
                $sheet->setCellValue("D{$row}", $error->error_message);
                $row++;
            }
        }
        
        $path = storage_path("app/exports/error_report_{$job->id}.xlsx");
        (new Xlsx($spreadsheet))->save($path);
        
        return $path;
    }
}
```

---

## Best Practices

1. **Always preview before commit** - Show users what will be created/updated
2. **Log everything** - Keep audit trail of imports
3. **Allow rollback** - For recent imports, provide undo capability
4. **Handle duplicates gracefully** - Match on external ID, update if exists
5. **Validate references** - Ensure foreign keys exist before import
6. **Chunk large files** - Process in batches to avoid memory issues
7. **Progress feedback** - Show real-time progress for large imports

---

*Module Version: 1.0*
*Last Updated: November 2025*
