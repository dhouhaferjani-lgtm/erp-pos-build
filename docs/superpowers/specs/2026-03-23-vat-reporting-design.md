# VAT Reporting Module — Design Specification

> **Status:** Draft
> **Date:** 2026-03-23
> **Scope:** Multi-country VAT reporting with period management, summary reports, and country-specific exports

---

## 1. Overview

Add VAT reporting capabilities to the Taxation module. The system aggregates tax data from existing `document_tax_details` and `pos_receipt_vat_details` into manageable filing periods, provides summary reports with rate-level breakdowns, and exports in country-specific formats.

**Countries in scope:** Tunisia (TVA), France (TVA/CA3), United Kingdom (VAT/MTD).

**Design principles:**
- Strategy pattern per country — structural differences handled in code, not configuration
- Persisted periods with status lifecycle — supports credit carry-forward and audit trail
- Zero migrations for new countries — JSONB absorbs country-specific variance
- Reuse existing shared components — atomic design hierarchy preserved

---

## 2. Architecture

### 2.1 Module Placement

Extends the existing `Taxation` module. No new module needed.

### 2.2 Backend Structure (Hexagonal)

```
app/Modules/Taxation/
├── Domain/
│   ├── Entities/
│   │   ├── VatPeriod.php                    # extends Model, HasUuids
│   │   └── VatPeriodBreakdown.php           # extends Model, HasUuids, UPDATED_AT=null
│   ├── Enums/
│   │   ├── VatPeriodStatus.php              # OPEN, CLOSED, FILED
│   │   ├── VatPeriodType.php                # MONTHLY, QUARTERLY, ANNUAL
│   │   ├── VatDirection.php                 # OUTPUT, INPUT
│   │   └── VatExportFormat.php              # PDF, CSV, FEC, MTD_JSON, TEIF_XML
│   ├── Contracts/
│   │   ├── VatReportStrategyInterface.php
│   │   └── VatExporterInterface.php
│   ├── DTOs/
│   │   ├── VatAggregation.php               # readonly, per-rate aggregation result
│   │   └── VatSummary.php                   # readonly, full period summary
│   ├── Repositories/
│   │   ├── VatPeriodRepositoryInterface.php
│   │   └── VatDataRepositoryInterface.php   # abstracts tax detail queries (docs + POS)
│   ├── Services/
│   │   └── VatCreditService.php             # carry-forward calculations (pure bcmath)
│   └── Events/
│       ├── VatPeriodClosed.php
│       └── VatPeriodFiled.php
│
├── Application/
│   ├── DTOs/
│   │   ├── VatPeriodData.php                # readonly, fromEntity() + toArray()
│   │   ├── VatSummaryData.php               # readonly, fromEntity() + toArray()
│   │   └── VatDeclarationData.php           # readonly, country-mapped fields
│   └── Services/
│       ├── VatPeriodManagementService.php   # CRUD + status transitions + DB::transaction
│       ├── VatReportGenerationService.php   # orchestrates aggregation + strategy
│       └── VatExportService.php             # resolves exporter + generates file
│
├── Infrastructure/
│   ├── Repositories/
│   │   ├── EloquentVatPeriodRepository.php
│   │   └── EloquentVatDataRepository.php    # queries document_tax_details + pos_receipt_vat_details
│   ├── Strategies/
│   │   ├── TunisiaVatStrategy.php           # monthly, 19/13/7%, timbre, retenue
│   │   ├── FranceVatStrategy.php            # monthly CA3, 20/10/5.5/2.1%, credit TVA
│   │   └── UkVatStrategy.php               # quarterly, 20/5/0%, 9-box model
│   └── Exporters/
│       ├── PdfVatExporter.php               # all countries
│       ├── CsvVatExporter.php               # all countries
│       ├── FecExporter.php                  # France — 18-field flat file
│       ├── MtdJsonExporter.php              # UK — 9-box JSON
│       └── TeifXmlExporter.php              # Tunisia — El Fatoora XML
│
└── Presentation/
    ├── Controllers/
    │   ├── VatPeriodController.php          # index, show, generate, close, reopen, file
    │   └── VatReportController.php          # summary, periodSummary, exportFormats, export
    ├── Requests/
    │   ├── GenerateVatPeriodsRequest.php
    │   ├── VatReportRequest.php
    │   └── VatExportRequest.php
    └── Resources/
        ├── VatPeriodResource.php
        └── VatSummaryResource.php
```

### 2.3 Layer Responsibilities

| Layer | Responsibility | Dependencies |
|-------|---------------|-------------|
| **Domain/Services** | Pure bcmath credit carry-forward calculations | None (no repos, no DB) |
| **Domain/Repositories** | Interfaces for period persistence + tax data queries | Domain DTOs |
| **Domain/Contracts** | Strategy + exporter interfaces | Domain DTOs, enums |
| **Application/Services** | Orchestration, `DB::transaction`, event dispatch | Domain services, repos, strategies |
| **Infrastructure/Strategies** | Country-specific period generation, declaration mapping, special items | Domain contracts, DB queries for country data |
| **Infrastructure/Exporters** | File generation (PDF, CSV, FEC, JSON, XML) | Domain DTOs |
| **Presentation** | Validation, authorization, resource serialization | Application services |

### 2.4 Strategy Interface

```php
interface VatReportStrategyInterface
{
    public function getDefaultPeriodType(): VatPeriodType;
    public function generatePeriods(int $fiscalYearStartMonth, int $year): array;
    public function mapToDeclaration(VatSummary $summary): VatDeclarationData;
    public function getSupportedExportFormats(): array;
    public function getSpecialLineItems(string $companyId, Carbon $from, Carbon $to): array;
    public function getExpectedRates(): array;
}
```

### 2.5 Exporter Interface

```php
interface VatExporterInterface
{
    public function supports(VatExportFormat $format): bool;
    public function export(VatSummaryData $summary, VatDeclarationData $declaration): StreamedResponse;
    public function getContentType(): string;
    public function getFilename(VatPeriod $period): string;
}
```

### 2.6 Strategy Resolution

The `VatReportGenerationService` resolves the correct strategy based on `$company->country_code`:

```php
public function __construct(
    private readonly TunisiaVatStrategy $tunisiaStrategy,
    private readonly FranceVatStrategy $franceStrategy,
    private readonly UkVatStrategy $ukStrategy,
) {}

public function resolveStrategy(string $countryCode): VatReportStrategyInterface
{
    return match ($countryCode) {
        'TN' => $this->tunisiaStrategy,
        'FR' => $this->franceStrategy,
        'GB' => $this->ukStrategy,
        default => throw new UnsupportedCountryException($countryCode),
    };
}
```

New countries: add a strategy class + one line in the match statement. No migrations.

### 2.7 Service Provider Bindings

Add to `TaxationServiceProvider::register()`:

```php
// Domain services (singleton, stateless)
$this->app->singleton(VatCreditService::class);

// Application services (singleton)
$this->app->singleton(VatPeriodManagementService::class);
$this->app->singleton(VatReportGenerationService::class);
$this->app->singleton(VatExportService::class);

// Repository bindings
$this->app->bind(VatPeriodRepositoryInterface::class, EloquentVatPeriodRepository::class);
$this->app->bind(VatDataRepositoryInterface::class, EloquentVatDataRepository::class);
```

---

## 3. Database Schema

### 3.1 Table: `vat_periods`

| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | HasUuids |
| company_id | uuid FK | → companies.id |
| country_code | char(2) FK | → countries.code |
| period_type | varchar | VatPeriodType enum |
| label | varchar(50) | "January 2026", "Q1 2026" |
| period_start | date | First day of period |
| period_end | date | Last day of period |
| status | varchar | VatPeriodStatus enum |
| total_output_vat | decimal(15,3) nullable | Populated on close |
| total_input_vat | decimal(15,3) nullable | Populated on close |
| net_vat | decimal(15,3) nullable | output - input |
| credit_brought_forward | decimal(15,3) default 0 | From previous period |
| credit_carried_forward | decimal(15,3) default 0 | To next period |
| amount_payable | decimal(15,3) default 0 | Final amount due |
| special_items | jsonb nullable | Country-specific (timbre, withholding, EU acquisitions) |
| declaration_data | jsonb nullable | Country-mapped fields (CA3 lines, 9-box, DGI form) |
| closed_at | timestamp nullable | |
| closed_by | uuid FK nullable | → users.id |
| filed_at | timestamp nullable | |
| filed_by | uuid FK nullable | → users.id |
| filing_reference | varchar nullable | External ref from tax authority |
| notes | text nullable | Accountant notes |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- `UNIQUE(company_id, period_start, period_end)` — no overlapping periods
- `INDEX(company_id, status)` — filter by status
- `INDEX(company_id, country_code, period_start)` — country-specific queries

### 3.2 Table: `vat_period_breakdowns`

| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | HasUuids |
| vat_period_id | uuid FK | → vat_periods.id (cascade delete) |
| direction | varchar | VatDirection enum: OUTPUT, INPUT |
| tax_rate | decimal(5,2) | 19.00, 20.00, 5.50, 0.00 |
| tax_configuration_id | uuid FK nullable | → tax_configurations.id (traceability) |
| base_amount | decimal(15,3) | Total HT for this rate+direction |
| vat_amount | decimal(15,3) | Total VAT for this rate+direction |
| document_count | integer | Number of documents in this bucket |
| is_recoverable | boolean default true | Is this VAT recoverable? |
| created_at | timestamp | UPDATED_AT = null (immutable) |

**Indexes:**
- `INDEX(vat_period_id)` — load all breakdowns for a period
- `INDEX(vat_period_id, direction)` — filter output vs input
- `UNIQUE(vat_period_id, direction, tax_rate, is_recoverable)` — one row per rate+direction+recoverability

### 3.3 Design Rationale

- **Totals on `vat_periods`**: Live-computed while OPEN, persisted on CLOSE. Gives real-time accuracy for active periods and immutable snapshots for closed ones.
- **JSONB for `special_items` and `declaration_data`**: Each country has structurally different fields. JSONB absorbs this variance. Each strategy knows its own format. Adding a new country requires zero migrations.
- **`vat_period_breakdowns` is immutable**: Matches the pattern of `document_tax_details` and `pos_receipt_vat_details`. Once closed, breakdown is a historical fact. Reopening deletes and recalculates.
- **`decimal(15,3)`**: Matches TND precision used throughout the codebase.

---

## 4. API Endpoints

### 4.1 VAT Period Management

All routes use middleware: `['api', 'auth:sanctum', SetPermissionsTeam::class]`

| Method | Path | Permission | Description |
|--------|------|-----------|-------------|
| GET | `/api/v1/vat/periods` | `reports.view` | List periods. Query: `?year=2026&status=OPEN` |
| POST | `/api/v1/vat/periods/generate` | `reports.manage` | Auto-generate periods for a year. Body: `{ "year": 2026 }` |
| GET | `/api/v1/vat/periods/{id}` | `reports.view` | Single period with nested breakdowns |
| POST | `/api/v1/vat/periods/{id}/close` | `reports.manage` | Close period: snapshot totals + breakdowns, calculate carry-forward. Body: `{ "notes": "..." }` |
| POST | `/api/v1/vat/periods/{id}/reopen` | `reports.manage` | Reopen CLOSED period. Deletes breakdown snapshot. Cannot reopen FILED. |
| POST | `/api/v1/vat/periods/{id}/file` | `reports.manage` | Mark as filed. Permanently locks. Body: `{ "filing_reference": "..." }` |

### 4.2 VAT Reports & Summary

| Method | Path | Permission | Description |
|--------|------|-----------|-------------|
| GET | `/api/v1/vat/reports/{periodId}/summary` | `reports.financial` | Full summary. Live for OPEN, snapshot for CLOSED/FILED. |
| GET | `/api/v1/vat/reports/summary` | `reports.financial` | Ad-hoc custom date range. Query: `?date_from=&date_to=` |

### 4.3 Exports

| Method | Path | Permission | Description |
|--------|------|-----------|-------------|
| GET | `/api/v1/vat/reports/{periodId}/export-formats` | `reports.financial` | Available formats for company's country |
| GET | `/api/v1/vat/reports/{periodId}/export/{format}` | `reports.financial` | Download file. Format: `pdf`, `csv`, `fec`, `mtd-json`, `teif-xml`. Returns `StreamedResponse`. |

### 4.4 Summary Response Shape

```json
{
  "period": { "id", "label", "status", "period_start", "period_end" },
  "output_vat": {
    "total_base": "50000.000",
    "total_vat": "9500.000",
    "breakdowns": [
      { "tax_rate": "19.00", "base_amount": "45000.000", "vat_amount": "8550.000", "document_count": 42 }
    ]
  },
  "input_vat": {
    "total_base": "30000.000",
    "total_vat": "5700.000",
    "breakdowns": [
      { "tax_rate": "19.00", "base_amount": "28000.000", "vat_amount": "5320.000", "document_count": 22, "is_recoverable": true }
    ]
  },
  "net_vat": "3800.000",
  "credit_brought_forward": "0.000",
  "credit_carried_forward": "0.000",
  "amount_payable": "3800.000",
  "special_items": { },
  "declaration": { }
}
```

All amounts are strings with 3 decimal places. Enums are string values.

---

## 5. Country Strategies

### 5.1 Tunisia (`TunisiaVatStrategy`)

| Aspect | Value |
|--------|-------|
| Default period | Monthly |
| Rates | 19% (standard), 13% (reduced), 7% (super-reduced), 0% (exempt) |
| Special items | `timbre_fiscal_count`, `timbre_fiscal_total` (count × 0.600 TND), `retenue_source_amount` (25% VAT withholding by state clients) |
| Supported exports | PDF, CSV, TEIF XML |
| Currency precision | 3 decimals (TND) |

**Special items query:** Count stamp duty from `document_tax_details` where `is_stamp_duty = true` (documents only — POS receipts do not have a separate stamp duty flag; POS stamp duty is identified via `tax_category = 'stamp_duty'` on `pos_receipt_vat_details`, or by matching the configured stamp duty rate). Sum retenue from `withholding_certificates` where `direction = 'sales'` in period.

### 5.2 France (`FranceVatStrategy`)

| Aspect | Value |
|--------|-------|
| Default period | Monthly (régime réel normal) |
| Rates | 20% (standard), 10% (intermediate), 5.5% (reduced), 2.1% (super-reduced) |
| Special items | `credit_tva_previous` (credit from prior period), `credit_tva_refund_requested` |
| Declaration mapping | CA3 line numbers (Line 08 = 20% base+TVA, Line 09 = 5.5%, Line 9B = 10%, Line 11 = 2.1%, Lines 19-21 = deductible) |
| Supported exports | PDF, CSV, FEC |
| Currency precision | 2 decimals (EUR) |

**FEC export:** The full FEC (Fichier des Ecritures Comptables) is a general ledger audit file that belongs in the Accounting module. The VAT-scoped FEC export here generates a **filtered subset**: only journal entries related to VAT accounts (44x accounts in the PCG) for the selected period. This is not a substitute for the full FEC (which should be implemented separately in Accounting). Format: 18-field tab-delimited flat file. Fields: JournalCode, JournalLib, EcritureNum, EcritureDate (AAAAMMJJ), CompteNum, CompteLib, CompAuxNum, CompAuxLib, PieceRef, PieceDate, EcritureLib, Debit, Credit, EcritureLet, DateLet, ValidDate, Montantdevise, Idevise. Filename format: `SIRENFECAAAAMMJJ`.

### 5.3 United Kingdom (`UkVatStrategy`)

| Aspect | Value |
|--------|-------|
| Default period | Quarterly |
| Rates | 20% (standard), 5% (reduced), 0% (zero-rated, distinct from exempt) |
| Special items | `eu_acquisitions_vat` (NI protocol), `eu_goods_supplied` |
| Declaration mapping | 9-box model: Box 1 (vatDueSales), Box 2 (vatDueAcquisitions), Box 3 (totalVatDue), Box 4 (vatReclaimedCurrPeriod), Box 5 (netVatDue, always positive), Box 6 (totalValueSalesExVAT, whole pounds), Box 7 (totalValuePurchasesExVAT, whole pounds), Box 8 (totalValueGoodsSuppliedExVAT), Box 9 (totalAcquisitionsExVAT) |
| Supported exports | PDF, CSV, MTD JSON |
| Currency precision | 2 decimals (GBP), Boxes 6-9 whole pounds |

**MTD JSON payload:**
```json
{
  "periodKey": "A001",
  "vatDueSales": 1234.56,
  "vatDueAcquisitions": 0.00,
  "totalVatDue": 1234.56,
  "vatReclaimedCurrPeriod": 567.89,
  "netVatDue": 666.67,
  "totalValueSalesExVAT": 12345,
  "totalValuePurchasesExVAT": 5678,
  "totalValueGoodsSuppliedExVAT": 0,
  "totalAcquisitionsExVAT": 0,
  "finalised": true
}
```

---

## 6. Data Flow

### 6.1 Aggregation Pipeline

```
1. Invoices / Credit Notes / Expenses / POS Receipts
   ↓ (already captured at transaction time)
2. document_tax_details + pos_receipt_vat_details (immutable snapshots)
   ↓ (queried by EloquentVatDataRepository via VatDataRepositoryInterface)
3. VatReportGenerationService.generateSummary(companyId, dateFrom, dateTo)
   - Calls VatDataRepositoryInterface.aggregateByRateAndDirection(companyId, dateFrom, dateTo)
   - Repository queries document_tax_details joined with documents
     (direction determined by document.type: Invoice/CreditNote → OUTPUT, Expense → INPUT)
   - Repository queries pos_receipt_vat_details joined with receipts (OUTPUT)
   - Groups by tax_rate + direction, sums base + vat amounts, counts documents
   - Recoverability determined by joining tax_configurations.is_recoverable
     (matched via tax_rate + country_code, since document_tax_details lacks is_recoverable)
   - Returns VatSummary DTO
   ↓
4. CountryStrategy.mapToDeclaration(summary)
   - Add country-specific special items
   - Map to declaration format (CA3 lines, 9-box, DGI form)
   - Return VatDeclarationData DTO
   ↓
5. VatPeriodBreakdown rows (persisted on close, immutable)
   ↓
6. Exporters generate files from persisted data
```

**Cross-module boundary for POS data:** The `EloquentVatDataRepository` queries `pos_receipt_vat_details` directly via DB query builder (not importing POS domain models). This is acceptable because the repository is an infrastructure concern querying raw data. If a `Shared/Contracts` approach is preferred, a `PosVatDataProviderInterface` can be introduced later.

### 6.2 Document Type → Direction Mapping

Based on the actual `DocumentType` enum (`Document\Domain\Enums\DocumentType`):

| DocumentType Enum | VAT Direction | Notes |
|-------------------|---------------|-------|
| `Invoice` (`'invoice'`) | OUTPUT | Sales invoices |
| `CreditNote` (`'credit_note'`) | OUTPUT | Negative amounts (reduces output VAT) |
| `Expense` (`'expense'`) | INPUT | Purchase expenses with recoverable VAT |
| `PurchaseOrder` (`'purchase_order'`) | INPUT | Only if VAT is tracked on POs (verify at implementation) |
| POS `ReceiptVatDetail` | OUTPUT | Queried separately from `pos_receipt_vat_details` |

**Excluded from VAT aggregation:** `Quote`, `SalesOrder`, `DeliveryNote`, `ReturnNote` — these do not represent VAT-liable transactions.

**Note on CreditNote direction:** The `documents` table is unified. Credit notes are always OUTPUT (negative). Purchase-side credit notes are not currently modeled as a separate type. If this changes, a `direction` column or additional enum cases would be needed.

### 6.3 Credit Carry-Forward Logic

```
credit_brought_forward = previous_period.credit_carried_forward

if net_vat < 0:
    # Input exceeds output — credit situation
    credit_carried_forward = abs(net_vat) + credit_brought_forward
    amount_payable = 0
else:
    if credit_brought_forward >= net_vat:
        # Credit fully covers liability
        credit_carried_forward = credit_brought_forward - net_vat
        amount_payable = 0
    else:
        # Partial credit offset
        credit_carried_forward = 0
        amount_payable = net_vat - credit_brought_forward
```

### 6.4 Period Status Lifecycle

```
OPEN → CLOSED → FILED
  ↑       |
  └───────┘ (reopen — only if not FILED)
```

| Transition | Action | Side Effects |
|-----------|--------|-------------|
| OPEN → CLOSED | `close()` | Snapshot totals + breakdowns, calculate carry-forward, dispatch `VatPeriodClosed` |
| CLOSED → OPEN | `reopen()` | Delete breakdown rows, clear totals, clear carry-forward |
| CLOSED → FILED | `file()` | Set `filed_at`, `filed_by`, `filing_reference`, dispatch `VatPeriodFiled` |
| FILED → * | blocked | Cannot be undone |

**Reopen chain protection:** A period can only be reopened if no subsequent period (by `period_start`) is CLOSED or FILED. This prevents breaking the credit carry-forward chain. If Period N is reopened, any subsequent OPEN periods will recalculate their `credit_brought_forward` on next close. The `reopen()` method must validate this constraint and return a 422 with the blocking period's label if violated.

---

## 7. Frontend Design

### 7.1 Feature Structure (Atomic Design)

```
apps/web/src/features/vat-reporting/
├── pages/
│   ├── VatPeriodsPage.tsx          # Thin container — delegates to components
│   └── VatReportPage.tsx           # Thin container — delegates to components
├── components/
│   ├── VatSummaryCards.tsx          # Molecule: 4 stat cards (output, input, credit, payable)
│   ├── VatBreakdownTable.tsx       # Molecule: rate-by-rate table (reused for output + input)
│   ├── VatPeriodList.tsx           # Organism: period table with status badges + actions
│   ├── VatSpecialItems.tsx         # Molecule: country-specific items grid
│   ├── VatExportMenu.tsx           # Molecule: country-aware format dropdown
│   └── VatPeriodStatusBadge.tsx    # Atom: status badge (Open/Closed/Filed)
├── hooks/
│   ├── useVatPeriods.ts            # useQuery(['vat-periods', filters])
│   ├── useVatReport.ts             # useQuery(['vat-report', periodId])
│   ├── useVatPeriodActions.ts      # useMutation: generate, close, reopen, file
│   └── useVatExport.ts            # useMutation: download export
├── api.ts                          # All API functions (apiGet, apiPost)
└── types.ts                        # TypeScript interfaces (amounts as string)
```

### 7.2 Reused Shared Components

| Component | Location | Usage |
|-----------|----------|-------|
| `StatCard` | `components/ui/StatCard.tsx` | YTD summary cards, period detail cards |
| `DateRangeFilter` | `components/ui/filters/DateRangeFilter.tsx` | Custom date range override |
| `QueryError` | `components/ui/QueryError.tsx` | Error boundary with retry on all pages |
| `Badge` | `components/atoms/Badge/Badge.tsx` | Base for `VatPeriodStatusBadge` |
| `Pagination` | `components/ui/Pagination.tsx` | Period list pagination (if many periods) |
| `Input` | `components/atoms/Input/Input.tsx` | Year selector, notes field |
| `Button` | `components/atoms/Button/Button.tsx` | All action buttons |
| `Modal` | `components/organisms/Modal/Modal.tsx` | Close/file confirmation dialogs |
| `ConfirmDialog` | `components/ui/ConfirmDialog.tsx` | Destructive action confirmation (file) |

### 7.3 New Feature Components (Atomic Classification)

| Component | Level | Responsibility |
|-----------|-------|---------------|
| `VatPeriodStatusBadge` | Atom | Render status with color: green (Open), amber (Closed), blue (Filed) |
| `VatSummaryCards` | Molecule | Compose 4 `StatCard` atoms with VAT-specific labels and formatting |
| `VatBreakdownTable` | Molecule | Rate-by-rate table. Props: `breakdowns[]`, `direction`, `showRecoverable`. No data fetching. |
| `VatSpecialItems` | Molecule | Grid of country-specific items. Props: `specialItems`, `countryCode`. Renders different layout per country. |
| `VatExportMenu` | Molecule | Dropdown button. Calls `useVatExport` hook. Shows only formats from `export-formats` endpoint. |
| `VatPeriodList` | Organism | Full period table. Composes `VatPeriodStatusBadge`, action `Button`s, `ConfirmDialog`. Handles close/file/reopen actions via `useVatPeriodActions`. |

### 7.4 Page Composition

**VatPeriodsPage (container):**
```
VatPeriodsPage
├── Header (title + year selector + generate button)
├── VatSummaryCards (YTD totals)
├── VatPeriodList
│   ├── VatPeriodStatusBadge (per row)
│   ├── Button (View, Close, File, Export)
│   └── ConfirmDialog (close/file confirmations)
└── QueryError (error state)
```

**VatReportPage (container):**
```
VatReportPage
├── Header (back link + title + status badge + export menu)
│   ├── VatPeriodStatusBadge
│   └── VatExportMenu
├── VatSummaryCards (period totals)
├── VatBreakdownTable (output VAT)
├── VatBreakdownTable (input VAT, showRecoverable=true)
├── VatSpecialItems (country-specific)
└── QueryError (error state)
```

### 7.5 Navigation Registration

**Sidebar** (`components/organisms/Sidebar/Sidebar.tsx`):
Add under `financeAndReports` group:
```typescript
{ key: 'vatReporting', href: '/finance/vat-periods', icon: Receipt, module: 'reports' }
```

**Routes** (`routes/index.tsx`):
```typescript
const VatPeriodsPage = lazy(() => import('../features/vat-reporting/pages/VatPeriodsPage').then(m => ({ default: m.VatPeriodsPage })))
const VatReportPage = lazy(() => import('../features/vat-reporting/pages/VatReportPage').then(m => ({ default: m.VatReportPage })))

<Route path="finance">
  <Route path="vat-periods" element={<RequirePermission moduleKey="reports"><SuspenseWrapper><VatPeriodsPage /></SuspenseWrapper></RequirePermission>} />
  <Route path="vat-report/:id" element={<RequirePermission moduleKey="reports"><SuspenseWrapper><VatReportPage /></SuspenseWrapper></RequirePermission>} />
</Route>
```

### 7.6 i18n

Add keys to `apps/web/src/locales/en/finance.json` under a `vatReporting` section. All user-facing text uses `t('finance:vatReporting.xxx')`.

### 7.7 TypeScript Types

Per CLAUDE.md Rule 7, domain entity types will be generated from PHP DTOs via `php artisan typescript:transform`. The types below are illustrative of the expected shape. The actual `types.ts` file will import from `packages/shared/types/generated.ts` for entity types, and define only filter/param types locally.

```typescript
// types.ts — all amounts as string, enums as string unions

type VatPeriodStatus = 'OPEN' | 'CLOSED' | 'FILED'
type VatPeriodType = 'MONTHLY' | 'QUARTERLY' | 'ANNUAL'
type VatDirection = 'OUTPUT' | 'INPUT'

interface VatPeriod {
  id: string
  label: string
  period_type: VatPeriodType
  period_start: string
  period_end: string
  status: VatPeriodStatus
  total_output_vat: string | null
  total_input_vat: string | null
  net_vat: string | null
  credit_brought_forward: string
  credit_carried_forward: string
  amount_payable: string
  closed_at: string | null
  filed_at: string | null
  filing_reference: string | null
}

interface VatRateBreakdown {
  tax_rate: string
  base_amount: string
  vat_amount: string
  document_count: number
  is_recoverable: boolean
}

interface VatDirectionSummary {
  total_base: string
  total_vat: string
  breakdowns: VatRateBreakdown[]
}

interface VatReportSummary {
  period: VatPeriod
  output_vat: VatDirectionSummary
  input_vat: VatDirectionSummary
  net_vat: string
  credit_brought_forward: string
  credit_carried_forward: string
  amount_payable: string
  special_items: Record<string, unknown>
  declaration: Record<string, unknown>
}

interface VatExportFormat {
  format: string
  label: string
}

interface VatPeriodsFilters {
  year?: number
  status?: VatPeriodStatus
}
```

---

## 8. Country-Specific Research Summary

### 8.1 Tunisia

- **Filing:** Monthly, mandatory. Electronic via DGI portal. E-invoicing (El Fatoora) via TTN platform.
- **Rates:** 19% standard, 13% reduced, 7% super-reduced, 0% exempt.
- **Timbre fiscal:** 0.600 TND per invoice/receipt. Tracked separately from TVA.
- **Retenue à la source:** 25% of invoiced VAT withheld by state clients on purchases ≥ 1,000 TND TTC.
- **Credit:** Can be carried forward or refunded upon written request to DGI.

### 8.2 France

- **Filing:** Monthly CA3 (régime réel normal). Form 3310-CA3.
- **Rates:** 20% standard, 10% intermediate, 5.5% reduced, 2.1% super-reduced.
- **FEC:** Mandatory 18-field audit file. Tab or pipe delimited. Filename: `SIRENFECAAAAMMJJ`.
- **CA3 mapping:** Lines 08-14 (output by rate), Lines 19-21 (deductible input), Line 25/28 (net due).
- **Credit de TVA:** Carried forward (Line 22) or refund requested (Line 27). Minimum: 150 EUR monthly, 760 EUR annually.

### 8.3 United Kingdom

- **Filing:** Quarterly (default). MTD mandatory since April 2022.
- **Rates:** 20% standard, 5% reduced, 0% zero-rated (can reclaim input VAT, distinct from exempt).
- **MTD API:** OAuth 2.0, JSON, HMRC endpoints. 9-box model.
- **Precision:** Boxes 1-5 in pence (2 decimals). Boxes 6-9 whole pounds (no decimals).
- **Records:** Must be kept 6 years. Digital links required between systems.

---

## 9. Testing Strategy

### 9.1 Backend Tests (PHPUnit)

| Test | What It Verifies |
|------|-----------------|
| `VatAggregationServiceTest` | bcmath aggregation from document_tax_details, grouping by rate+direction |
| `VatCreditServiceTest` | Carry-forward logic: credit > liability, partial offset, no credit |
| `VatPeriodManagementServiceTest` | Period CRUD, status transitions (OPEN→CLOSED→FILED), reopening |
| `TunisiaVatStrategyTest` | Monthly period generation, timbre fiscal calculation, retenue query |
| `FranceVatStrategyTest` | Monthly periods, CA3 line mapping, credit TVA |
| `UkVatStrategyTest` | Quarterly periods, 9-box mapping, whole-pound rounding for boxes 6-9 |
| `FecExporterTest` | 18-field format, tab delimiter, date format AAAAMMJJ |
| `MtdJsonExporterTest` | 9-box JSON structure, decimal precision |
| `CsvVatExporterTest` | Column headers, amounts, date formatting |
| `VatPeriodControllerTest` | API endpoints, auth, validation, status transition errors |

### 9.2 Frontend Tests (Vitest)

| Test | What It Verifies |
|------|-----------------|
| `VatPeriodsPage.test.tsx` | Renders periods, status badges, action buttons |
| `VatReportPage.test.tsx` | Renders summary cards, breakdown tables, special items |
| `VatBreakdownTable.test.tsx` | Renders rate rows, totals row, recoverable column |
| `VatPeriodStatusBadge.test.tsx` | Correct color per status |
| `VatExportMenu.test.tsx` | Shows only country-supported formats |
| `useVatPeriods.test.ts` | Query key includes filters, returns correct shape |

### 9.3 TDD Approach

Per CLAUDE.md Rule 2: write test first (red), write minimum code to pass (green), refactor.

Order:
1. Domain services first (pure logic, easiest to test)
2. Application services (mock repos, test orchestration)
3. Strategies (test country-specific logic in isolation)
4. Exporters (test file output format)
5. Controllers (feature tests with real DB)
6. Frontend components (render tests with mocked hooks)

---

## 10. Permissions

### 10.1 Existing Permissions (reused)

- `reports.view` — view periods, summaries (read-only endpoints)
- `reports.financial` — view detailed financial data, export reports

### 10.2 New Permissions (must be added to `RolesAndPermissionsSeeder`)

- `reports.manage` — generate periods, close, reopen, file (state-changing operations)

### 10.3 Permission Mapping

| Action | Permission |
|--------|-----------|
| List/view periods | `reports.view` |
| View VAT summary | `reports.financial` |
| Export reports | `reports.financial` |
| Generate periods | `reports.manage` |
| Close period | `reports.manage` |
| Reopen period | `reports.manage` |
| File period | `reports.manage` |

### 10.4 Role Assignment

| Role | Permissions |
|------|------------|
| Manager | `reports.view`, `reports.financial`, `reports.manage` |
| Accountant | `reports.view`, `reports.financial`, `reports.manage` |
| Viewer | `reports.view` |

---

## 11. Out of Scope (Future)

- Direct tax authority API integration (MTD submission, DGI e-filing)
- VAT reconciliation against GL accounts
- VAT dashboard widgets on main dashboard
- Automated period generation on fiscal year start
- Email notifications for upcoming filing deadlines
- Multi-currency VAT consolidation
