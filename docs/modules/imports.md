# AutoERP Import Module

> Complete documentation for data import functionality including legacy migration,
> bulk imports, and the staging pattern.

---

## Overview

`import_rows.data._results` uses the writer's durable flat breadcrumb map
(`opening_stock => ok`, `tax_source => company_default`). The typed source DTO
also owns the private `_placement_plan`; casts do not pass either shape through
as untyped JSON.

The Import module handles:
- Bulk import of products, customers, suppliers
- Legacy ERP migration (opening balances, historical data)
- Ongoing data feeds (price lists, catalog updates) — note that stock QUANTITY
  is not importable on an ongoing basis; see "Stock Levels — RETIRED" below
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
├── Opening stock — carried BY the Products import (quantity + purchase_price
│                   + location_code), not a separate step. See "Opening stock"
│                   below; the standalone Stock Levels import is RETIRED.
└── Opening Balances (requires customers, suppliers)
```

### 3. Idempotent Imports

Re-running an import should produce the same result:
- Products resolve within the company by SKU, then barcode, then normalized name
  only when neither SKU nor barcode was supplied.
- Partners resolve within the company by code, VAT number, then normalized name.
- Existing records use a field-level coalescing update: a non-blank source cell
  overrides, while a blank cell preserves the stored value.
- The preview records duplicate buckets and the operator chooses `override` or
  `skip`; execution resolves identity again and its decision is authoritative.

---

## Import Job Lifecycle

```
pending → validating → validated → importing → completed
              ↓                         ↓
            failed                    failed
```

### Status Definitions

| Status | Meaning |
|--------|---------|
| `pending` | Job created, file uploaded, waiting to start |
| `validating` | Running validation rules on staging data |
| `validated` | Validation finished and at least one row is pending execution |
| `importing` | Applying pending rows to production |
| `completed` | At least one row was imported or deliberately skipped |
| `failed` | No row was imported or deliberately skipped |

Rows use the durable outcomes `pending`, `imported`, `merged_line`,
`duplicate_skipped`, `duplicate_loser`, `failed`, and `opening_locked`.
Successful rows are `imported + merged_line`; skipped rows are
`duplicate_skipped + duplicate_loser`; failed rows are `failed + opening_locked`.
Those three counts sum to `total_rows`. `merged_line` means the product master
was already resolved while this confirmed row still applied its own placement or
opening-stock instruction.

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
- `name` - Product name

**Optional Fields:**
- `description`
- `barcode`
- `sku` - Product code; when blank, the current slug/barcode fallback supplies it
- `unit` - An active visible unit code, entered exactly as spelled after trimming
- `category` - Category code reference
- `brand` - Brand code reference
- `supplier` - Supplier code reference
- `purchase_price`
- `sale_price`
- `purchase_tax` - Tax code reference
- `sale_tax` - Tax code reference
- `oem_numbers` - Comma-separated list
- `cross_references` - Comma-separated list

**Identity and duplicate rules:**
- A supplied SKU is tried first; on a miss a supplied barcode is tried next.
- Name matching is allowed only when neither SKU nor barcode was supplied.
- A barcode matching multiple company products is refused as ambiguous.
- Unit names and symbols are not accepted. The accepted-code list is read live
  from the same visible active unit catalog query used by the resolver.
- On create, a blank unit defaults to `pc`; on update, a blank unit preserves the
  existing unit.
- `override` merges non-blank cells. `skip` performs no write for matched rows.
- Within one file, rows for one product coalesce master data; for the same product
  and location, the last opening/placement instruction wins.

#### Barcode collision census and write invariant (K-11)

`products.barcode` is a live, company-scoped key. It is unique for non-deleted
products by the `products_company_barcode_live_unique` partial index on both
PostgreSQL and SQLite. A soft-deleted product deliberately releases its barcode;
this differs from the lifetime SKU rule. Product-variant barcodes remain
tenant-wide unique by RUL-2.

The product census keeps barcode grouping parallel to the existing placement-key
ladder. It never folds a barcode into that ladder, so a blank-SKU barcode-only
row keeps the established resolution behaviour. Each nonblank barcode group is
classified as one of:

- `multi_location`: the same identity is repeated at different locations. The
  preview lists and counts the group, and execution is blocked until the operator
  confirms it. Once confirmed, every location line succeeds under either duplicate
  policy; later lines use `merged_line`, so opening stock reaches every location.
- `barcode_identity_conflict`: the barcode is attached to contradictory SKUs or
  names. The preview subtracts those rows from valid rows and adds them to failed
  rows. During execution each row raises a coded row exception before duplicate
  policy is evaluated, producing `failed` with the barcode, row numbers and
  differing fields in its detail.

The named preview/execution honesty mechanism is
`duplicate_census.barcode_groups.counts.barcode_identity_conflict_rows`:
`ImportController` uses it to adjust the preview summary, while final counts are
recounted from durable row outcomes. Tests pin that preview conflict count to the
execution failed-row count.

Every product create/update, including imports, checks barcode availability through
the injected product service before writing. The check is company-scoped, excludes
the current product, and permits editing a pre-existing twin without extending the
collision. A conflict returns a same-company holder `{id, sku, name}` so the form
can link to the record. The tenant migration repairs historical twins in place:
the oldest product keeps the barcode, later products keep their rows and lose only
the barcode. `products:census-barcode-twins --dry-run` previews that repair.

Spreadsheet-shaped barcodes containing `.` or `e`/`E` whose integer portion ends
in `00000` receive the non-blocking
`barcode_float_corruption_suspected` warning. Barcode input is limited to 100
characters, matching the database column.

Numeric spreadsheet cells are normalized before validation only when their excess
digits are float noise within the precision contract's relative epsilon. Money,
quantity, and percentage columns are rounded once to scales 3, 4, and 2
respectively. Each affected row receives the non-blocking `numeric_normalized`
warning. Genuine extra precision and malformed values are refused as
`invalid_number`, with the source column and raw value retained for correction.

Result workbooks always contain three honest sheets: `Imported` (`imported` and
`merged_line`), `Skipped` (the two duplicate-skip outcomes), and `Rejected`
(`failed` and validation failures), with coded reasons retained.

#### Industry baseline (benchmark-first — convention 10)

Flow: importing a product catalogue with repeated barcodes and multi-location
opening stock. The competitor documentation does not specify every collision edge;
`?` is therefore an explicit unverified hypothesis, never evidence for a decision.

| # | Guarantee the user gets | Odoo | ERPNext | Dolibarr | AutoERP implementation (`path:line`) | Decision |
|---|---|---|---|---|---|---|
| B1 | Re-import uses a stable identity rather than silently creating a second catalogue | External/Database ID updates imported records [1] | The ID column selects insert vs update [2] | Added rows carry an import key; matching updates are not documented [3][4] | Product resolution and guarded writes (`apps/api/app/Modules/Product/Application/Services/ProductService.php:126,268`) | MATCH |
| B2 | Contradictory barcode identities are visible per row before and after execution | Exact duplicate-barcode rule ? | Exact duplicate-barcode rule ? | Exact duplicate-barcode rule ? | Parallel census plus coded pre-policy refusal (`apps/api/app/Modules/Import/Services/DuplicateCensusService.php:27-50`; `ImportService.php:589-600`) | DIVERGE upward: never merge contradictory identities |
| B3 | One product may carry opening stock at several locations without losing a line | Inventory adjustments are product/location records; import collision semantics ? | Stock Reconciliation is the documented opening-stock mechanism [5]; duplicate-line semantics ? | Multi-warehouse import semantics ? | Confirmed lines apply under either policy and use `merged_line` (`ImportService.php:602-667`) | MATCH the user guarantee; explicit confirmation is AutoERP policy |
| B4 | The active product barcode has a declared business scope and deleted rows do not reserve it | Products may be company-specific or shared; barcode uniqueness scope ? [6] | Barcode uniqueness scope ? | Barcode uniqueness scope ? | Both-driver partial unique by company (`database/migrations/tenant/2026_09_01_120000_enforce_company_scoped_product_barcodes.php:12`) | DIVERGE, owner-ruled: product/company; variant/tenant |
| B5 | Failed, skipped and imported rows remain distinguishable in the downloadable result | Preview/testing is documented; three-sheet export ? [1] | Row/column warnings are documented; three-sheet export ? [2] | Simulation produces an error report [3] | Outcome-specific sheets (`apps/api/app/Modules/Import/Services/ResultWorkbookService.php:21-37`) | MATCH & EXCEED |
| B6 | Re-running and using a sibling company cannot extend a collision | Multi-company records can be scoped [6]; exact barcode rule ? | Site/company collision rule ? | Entity collision rule ? | Company-scoped guard and self exclusion (`ProductService.php:268-304`) | MATCH the scope guarantee; pinned by re-run and second-company tests |

Sources (vendor documentation, verified for the cited guarantee; `?` remains
unverified): [1] Odoo, *Export and import data*,
https://www.odoo.com/documentation/18.0/applications/essentials/export_import_data.html;
[2] ERPNext, *Data Import*, https://docs.frappe.io/erpnext/user/manual/en/data-import;
[3] Dolibarr, *Module Imports*,
https://wiki.dolibarr.org/index.php?title=Module_Imports_En; [4] Dolibarr,
*Field Import key*, https://wiki.dolibarr.org/index.php/Field_Import_key; [5]
ERPNext, *Stock Reconciliation*,
https://docs.frappe.io/erpnext/v13/user/manual/en/stock/stock-reconciliation;
[6] Odoo, *Multi-company*,
https://www.odoo.com/documentation/18.0/applications/general/companies/multi_company.html.

Second-of-everything (convention 09): K-11 pins a second location under `skip`,
a second company reusing the barcode, a second full import pass, and editing a
pre-existing twin without extending the duplicate set.

**Validation Rules:**
- Category must exist if provided
- Supplier must exist if provided
- Prices must be positive numbers
- Tax codes must exist

### Stock Levels — RETIRED (owner ruling D4, 2026-08-08)

**Do not use. There is no `stock_levels` import.** The API refuses
`POST /api/v1/imports` with `type=stock_levels` (422, validation error on
`type`), refuses the CSV template, and the migration wizard no longer offers it.
The enum case survives *only* so pre-deprecation jobs stay readable in the
import history.

It was the first import ever implemented, and it set an **absolute** quantity
through `InventoryService::upsertStockLevel` — a bare `StockLevel::updateOrCreate`
with **no stock movement, no justifying document, no WAC or GL posting**, and
`reserved` silently reset to 0. That made every quantity it wrote invisible to
the ledger-based detectors. The writer has been deleted.

> **Correction:** earlier revisions of this page claimed the import "creates a
> stock adjustment transaction". It never did — that is precisely the defect
> that retired it. No `stock_movements` row was ever written by this path.

**Use the Products import instead.** It carries the same data and routes it
through the compliant opening-stock path.

#### Opening stock (the supported path)

Add these columns to your **Products** import file:

| Column | Purpose |
|---|---|
| `quantity` | Opening quantity on hand |
| `purchase_price` | Unit cost, seeds the weighted-average cost |
| `location_code` | Where the stock sits |
| `expiry_date` | *Optional.* Expiry of the opening lot, **`YYYY-MM-DD` only** |

##### `expiry_date` — the lot's expiry (campaign W4-1)

Only meaningful for **batch-tracked** products, whose opening stock is backed by a
`DEFAULT` lot.

- **Blank means "not supplied"**, never "no expiry rule". The lot then takes the
  product's configured `default_shelf_life_days`, and if there is none it is opened
  **with no expiry at all**. Nothing invents a date. An undated lot is *not* an
  expired lot: it is sellable, and FEFO ranks it **after** every dated lot.
  (Before W4-1 the opening lot was given `cutover + 365`, which was the *earliest*
  date on every product — so the FEFO guards compelled shipping the fabricated lot
  first and refused every alternative.)
- **`YYYY-MM-DD` only.** This is the strictest rule in the Products set, and it is
  deliberate: `03/04/2027` is 3 April or 4 March depending on the reader, so an
  ambiguous cell is **refused with its row** (`expiry_unparseable`) rather than
  guessed onto a parapharmacy lot.
- **A past date is allowed**, because opening with expired stock in order to scrap
  it is legitimate. The row carries the non-blocking warning `expiry_in_past`: that
  lot is born EXPIRED and cannot be sold or transferred until it is written off.
- **XLSX date cells work.** A cell Excel typed as a Date is read as `YYYY-MM-DD`,
  not as the raw serial.
- Map the column in the wizard. It is offered as an optional target for every
  source column, and these headers auto-map without being pointed at it:
  `expiry_date`, `expiry`, `expiration`, `expiration_date`, `best_before`,
  `péremption` (and `peremption`), `date_péremption`, `DLC`, `DLUO`. Matching is
  case-insensitive and substring-based, so `Date de péremption` is caught by the
  `péremption` entry — but accents are **not** normalised, so an unaccented header
  needs the unaccented alias, which is why both forms are listed.

Row warnings you may see in the result workbook:

| Code | Meaning |
|---|---|
| `expiry_in_past` | Accepted; the lot opens EXPIRED |
| `expiry_conflict_existing_lot` | The product's `DEFAULT` lot already carried a **different** expiry. The existing date is kept — edit the lot directly to change it. There is one `DEFAULT` lot per product across **all** locations, so this is what a second row for the same SKU at another location meets. |
| `expiry_ignored_not_batch_tracked` | The product is not batch-tracked, so its stock is not held in a lot and there is nothing to date. |
| `enrichment_not_found` | The import completed, but the barcode had no platform catalogue match. |
| `enrichment_unavailable` | The platform lookup was unavailable or returned an unusable platform identifier; the import completed without a backlink. |
| `enrichment_invalid_barcode` | The barcode could not be normalized for catalogue lookup. |
| `enrichment_cap_exceeded` | The row fell beyond the per-import budget of 500 distinct normalized barcode lookups. |
| `enrichment_vertical_not_supported` | Job-level note: the tenant vertical has no platform catalogue mapping, so no row lookups ran. |
| `enrichment_barcode_missing` | The imported product row had no barcode value, so no platform catalogue lookup could run. |

Behaviour — `ProductOpeningStockPhase` → `OpeningBalancePostingService`:
- Posts a real **Opening** `stock_movement` (a document-backed action).
- Seeds the weighted-average cost from `purchase_price`.
- Writes the matching GL entry.
- Backs a batch-tracked product's opening quantity with its `DEFAULT` lot, dated by
  the rules above. A supplied expiry **fills** an existing undated lot (set-once) but
  never overwrites a date that is already there.
- **Enter-once guard:** a second opening for the same product raises
  `OpeningAlreadyExistsException` rather than silently overwriting.
- **Reset affordance:** to correct an opening, use `ResetOpeningBalanceService`,
  which reverses the original movement instead of clobbering the quantity.

To *change* stock after opening, use a document: Purchase Order → Goods Receipt
Note for increases, or a stock adjustment/count for corrections.

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
        foreach ($job->rows()->where('is_valid', true)->where('outcome', 'pending')->get() as $row) {
            try {
                DB::transaction(function () use ($job, $row): void {
                    $decision = $this->applyRow($job, $row);
                    $row->update([
                        'outcome' => $decision->value,
                        'is_imported' => in_array($decision, [
                            ImportRowOutcome::Imported,
                            ImportRowOutcome::MergedLine,
                        ], true),
                    ]);
                });
            } catch (Throwable $error) {
                // This single-row coded failure update runs only after rollback.
                $this->recordFailedRow($row, $error);
            }
        }

        $this->finalizeAndRecountFromOutcomes($job);
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
    // NOTE: 'stock_levels' was removed here — the import type is retired
    // (owner ruling D4). Opening stock rides the products import.
    private array $dependencies = [
        'products' => ['categories', 'suppliers'],
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

## Import history and the correction round trip (spec §4.10)

The surface an operator uses after an import finishes. Every noun here is registered in
[`docs/glossary.md`](../glossary.md) — **Rows to fix**, **Full report**, **Partially completed**,
**Re-import of** — and each has exactly one writer and one operator surface (convention 11).

### Terminal outcomes

`ImportStatus` has three terminal cases: `completed`, `partially_completed` and `failed`.

`partially_completed` ("Completed with errors") means the job **committed rows AND** either rejected rows
or failed to finalize — data is in the system and work is left to do. That rule is expressed exactly once,
in `App\Modules\Import\Domain\ImportJobOutcome`:

- `isPartiallyCompleted(int $successful, int $failed, ?string $errorMessage): bool` — the predicate;
- `effectiveStatus(...)` — applies it to terminal statuses only (an in-flight job is never reclassified);
- `effectiveStatusExpression(ImportStatus $status): array{string, list<string>}` — the same rule as SQL,
  with its terminal `IN` list generated from `ImportStatus::cases()`.

All three readers call it: the durable terminal CAS write (`ImportJobClaimService::terminalUpdate()`),
the detail/list read model (`ImportController::formatJob()`) and the history status filter
(`ImportController::index()`). The filter classifies in SQL rather than reading the column because jobs
written before the status existed still carry `completed`/`failed` — do not "simplify" it to a column
comparison without migrating those rows.

Counters on a terminal job are recomputed from row state, never from the loop's optimistic tally, so
"200 imported" always means 200 rows are really there.

### Failure messages are coded, never raw

`import_jobs.error_code` (`ImportErrorCode`) is the **operator** channel; `import_jobs.error_message` is the
**support** channel and holds raw server text (exception class names, file paths, full SQLSTATE strings
including key values). The web never renders `error_message` — both surfaces go through
`apps/web/src/features/import/jobErrorMessage.ts`, which translates `errors.<code>` (en/fr/ar) and degrades
an absent or unrecognised code to one generic sentence.

Consequence for any new terminal-failure path: **stamp a code**. `ImportErrorCode::InternalError` is the
honest fallback; a `CodedImportRowException` keeps its own code through the sync finalize catch. A path that
writes `error_message` without `error_code` silently turns its message into "no current code" on screen.

### Rows to fix — the correction export

```
GET /api/v1/imports/{id}/failed-rows.csv
GET /api/v1/imports/{id}/failed-rows.xlsx
```

Inside the single import route group (`['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'can:imports.manage']`),
keyed on the **job id**, so async jobs are reachable. Per-request company check (409
`IMPORT_COMPANY_MISMATCH`) and module-entitlement re-check on the job's type. An empty selection is a coded
`404 {"error":{"code":"no_rows_to_fix"}}`, which the FE renders as `correction.noRows`.

- **Selection** — `is_valid = false` **OR** `outcome IN (failed, opening_locked)` **OR** at least one warning
  (`ImportRow::scopeHasWarnings()`, the one portable warning scope also used by the history counts).
  `pending` rows are excluded.
- **Columns** — the mapped source columns in the uploaded file's order, under the operator's own header
  spelling, then `_status`, `_code`, `_message`. Unmapped source columns are omitted.
- **Cells** — the stored strings, unchanged. No float ever touches them (rule 19).
- **`_status`** — an error outranks a warning on the same row.
- **CSV** — UTF-8 BOM, comma, CRLF. **XLSX** — every cell written as explicit text, so `001` and `12.500`
  survive.
- **One writer** — `ImportRowExportService`. `FailedRowsExportService` is deleted, not shadowed.

### Retention: the correction export is ephemeral

The artefact holds the operator's raw rows — partner names and codes, tax ids, balances — so it must not
outlive the request that produced it:

- the download deletes `imports/rows/{jobId}.{format}` as soon as the bytes are captured (the response
  streams from memory);
- `DELETE /api/v1/imports/{id}` deletes any artefact of the discarded job;
- `imports:purge-expired` deletes any orphan for a job past the 90-day window.

All three go through `ImportRowExportService::deleteArtifacts()`, so the writer and the deleters cannot
drift on the path. A new artefact path under `imports/rows/` must be added to `FORMATS` there, not deleted
by hand at a call site.

### Full report — the secondary action

`ResultWorkbookService` produces the read-only Imported / Skipped / Rejected workbook for one run. It is a
**different concept** from the correction export: not shaped for re-upload, and never the primary action on
a completion or history row. Both surfaces label it "Full report" / "Rapport complet" / "التقرير الكامل".

### Re-import of

A corrected file is uploaded with `reimport_of=<original job id>`. Same tenant (otherwise 404), same company
(409 `IMPORT_COMPANY_MISMATCH`), same import type (coded 422), and the entitlement is re-checked on the
referenced job's type. If the new file's headers still contain every source of the original mapping, that
mapping is pre-applied and the operator skips straight to preview; otherwise the upload still succeeds and
carries a non-blocking `reimport_notice = 'reimport_headers_changed'`, and the operator maps again. A column
mapping that is not injective (two sources onto one destination) is refused 422 `mapping_not_injective` on
both upload and options update.

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
*Last Updated: February 2026*
