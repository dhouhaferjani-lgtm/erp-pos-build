# Tax Withholding Specification (Tunisia)

**Module:** Extends Treasury  
**Country:** Tunisia (expandable)  
**Status:** Phase 2 - Planned

---

## 1. Overview

Withholding tax (Retenue à la Source) is a tax collection mechanism where the payer deducts tax from payment and remits it to the government.

### Two Directions

**As Buyer (Primary Implementation):**
- You receive an invoice from supplier
- You withhold tax from payment
- You issue a withholding certificate
- You upload to TEJ platform

**As Seller (Secondary):**
- You issue an invoice to customer
- Customer withholds from their payment to you
- You track reduced receivable
- You collect certificate from customer

---

## 2. Tunisia Withholding Rates

### Standard Rates (to be seeded)

| Category | Rate | Threshold | Notes |
|----------|------|-----------|-------|
| Professional services (books) | 3% | None | Companies with proper accounting |
| Professional services (other) | 10% | None | Individuals, forfait regime |
| Rentals | 10% | None | Real estate, equipment |
| Performance bonuses | 15% | None | Commissions, bonuses |
| Interest | 20% | None | Bank interest, loans |
| Goods/services (CIT 25%) | 1.5% | ≥1,000 TND | Standard corporate rate |
| Goods/services (CIT 15%) | 1.0% | ≥1,000 TND | Reduced corporate rate |
| Goods/services (CIT 10%) | 0.5% | ≥1,000 TND | Lowest corporate rate |
| Non-resident payments | 15% | None | Subject to DTT |

### Rate Selection Logic
```
1. Check partner's tax regime
2. Check transaction type
3. Check amount threshold
4. Apply appropriate rate
5. Allow manual override (with reason)
```

---

## 3. Database Schema

### withholding_tax_rules
```sql
CREATE TABLE withholding_tax_rules (
    id BIGSERIAL PRIMARY KEY,
    
    -- Scope
    country_code CHAR(2) NOT NULL,        -- 'TN' for Tunisia
    
    -- Rule identity
    code VARCHAR(50) NOT NULL,            -- 'TN_PROF_SERVICES_3'
    name VARCHAR(100) NOT NULL,           -- 'Professional Services (Books)'
    description TEXT,
    
    -- Conditions
    transaction_type VARCHAR(50),         -- 'services', 'goods', 'rental', etc.
    partner_tax_regime VARCHAR(50),       -- 'corporate', 'individual', 'forfait'
    min_amount DECIMAL(15,2),             -- Threshold (e.g., 1000 TND)
    
    -- Rate
    rate DECIMAL(5,4) NOT NULL,           -- 0.0300 for 3%
    
    -- Validity
    effective_from DATE NOT NULL,
    effective_to DATE,
    
    -- Status
    is_active BOOLEAN DEFAULT true,
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(country_code, code, effective_from)
);

CREATE INDEX idx_wht_rules_country ON withholding_tax_rules(country_code, is_active);
```

### partner_tax_settings
```sql
-- Extends partners table or separate table
CREATE TABLE partner_tax_settings (
    id BIGSERIAL PRIMARY KEY,
    partner_id BIGINT NOT NULL REFERENCES partners(id),
    
    -- Tax identification
    tax_id VARCHAR(50),                   -- Matricule fiscal
    tax_regime VARCHAR(50),               -- 'corporate', 'individual', 'forfait', 'exempt'
    
    -- Withholding preferences
    is_withholding_exempt BOOLEAN DEFAULT false,
    withholding_exemption_reason VARCHAR(255),
    default_withholding_rule_id BIGINT REFERENCES withholding_tax_rules(id),
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(partner_id)
);
```

### withholding_certificates
```sql
CREATE TABLE withholding_certificates (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL UNIQUE,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    
    -- Certificate identity
    certificate_number VARCHAR(50) NOT NULL,  -- Sequential: 'WHT-2025-0001'
    year INT NOT NULL,
    
    -- Parties
    supplier_id BIGINT NOT NULL REFERENCES partners(id),
    
    -- Source documents
    document_id BIGINT REFERENCES documents(id),    -- Invoice
    payment_id BIGINT REFERENCES payments(id),
    
    -- Amounts
    gross_amount DECIMAL(15,2) NOT NULL,      -- Invoice total
    withholding_rate DECIMAL(5,4) NOT NULL,   -- 0.0300 for 3%
    withholding_amount DECIMAL(15,2) NOT NULL,-- Amount withheld
    net_amount DECIMAL(15,2) NOT NULL,        -- Amount paid to supplier
    
    -- Rule applied
    withholding_rule_id BIGINT REFERENCES withholding_tax_rules(id),
    
    -- TEJ submission
    tej_submitted BOOLEAN DEFAULT false,
    tej_submitted_at TIMESTAMP,
    tej_reference VARCHAR(100),
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft, issued, submitted, cancelled
    
    issued_at TIMESTAMP,
    issued_by BIGINT REFERENCES users(id),
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(company_id, certificate_number)
);

CREATE INDEX idx_wht_cert_supplier ON withholding_certificates(supplier_id);
CREATE INDEX idx_wht_cert_year ON withholding_certificates(company_id, year);
CREATE INDEX idx_wht_cert_tej ON withholding_certificates(tej_submitted);
```

### sales_withholding_tracking
For tracking when customers withhold from payments to us:

```sql
CREATE TABLE sales_withholding_tracking (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    
    -- Source
    document_id BIGINT NOT NULL REFERENCES documents(id),  -- Our invoice
    payment_id BIGINT REFERENCES payments(id),
    
    -- Customer
    customer_id BIGINT NOT NULL REFERENCES partners(id),
    
    -- Amounts
    invoice_amount DECIMAL(15,2) NOT NULL,
    withholding_rate DECIMAL(5,4) NOT NULL,
    withholding_amount DECIMAL(15,2) NOT NULL,
    expected_receivable DECIMAL(15,2) NOT NULL,
    
    -- Certificate from customer
    certificate_number VARCHAR(100),
    certificate_received BOOLEAN DEFAULT false,
    certificate_received_at TIMESTAMP,
    
    notes TEXT,
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
```

---

## 4. Domain Models

### WithholdingTaxRule
```php
class WithholdingTaxRule
{
    private int $id;
    private string $countryCode;
    private string $code;
    private string $name;
    private ?string $transactionType;
    private ?string $partnerTaxRegime;
    private ?Money $minAmount;
    private Percentage $rate;
    private Carbon $effectiveFrom;
    private ?Carbon $effectiveTo;
    
    public function appliesTo(
        Partner $partner,
        Money $amount,
        ?string $transactionType = null
    ): bool {
        // Check partner regime
        if ($this->partnerTaxRegime && 
            $partner->taxSettings?->taxRegime !== $this->partnerTaxRegime) {
            return false;
        }
        
        // Check transaction type
        if ($this->transactionType && 
            $transactionType !== $this->transactionType) {
            return false;
        }
        
        // Check minimum amount
        if ($this->minAmount && $amount->lessThan($this->minAmount)) {
            return false;
        }
        
        // Check date validity
        if (!$this->isEffectiveOn(now())) {
            return false;
        }
        
        return true;
    }
    
    public function calculateWithholding(Money $amount): WithholdingCalculation
    {
        $withholdingAmount = $amount->multiply($this->rate->asDecimal());
        $netAmount = $amount->subtract($withholdingAmount);
        
        return new WithholdingCalculation(
            grossAmount: $amount,
            rate: $this->rate,
            withholdingAmount: $withholdingAmount,
            netAmount: $netAmount,
            rule: $this
        );
    }
}
```

### WithholdingCertificate
```php
class WithholdingCertificate
{
    // ... properties
    
    public static function createFromPayment(
        Payment $payment,
        Document $invoice,
        WithholdingCalculation $calculation
    ): self {
        return new self(
            certificateNumber: self::generateNumber($payment->company),
            year: now()->year,
            supplierId: $invoice->partnerId,
            documentId: $invoice->id,
            paymentId: $payment->id,
            grossAmount: $calculation->grossAmount,
            withholdingRate: $calculation->rate,
            withholdingAmount: $calculation->withholdingAmount,
            netAmount: $calculation->netAmount,
            withholdingRuleId: $calculation->rule->id,
            status: CertificateStatus::DRAFT
        );
    }
    
    private static function generateNumber(Company $company): string
    {
        $year = now()->year;
        $sequence = WithholdingCertificate::query()
            ->where('company_id', $company->id)
            ->where('year', $year)
            ->count() + 1;
        
        return sprintf('WHT-%d-%04d', $year, $sequence);
    }
}
```

---

## 5. Services

### WithholdingTaxService
```php
class WithholdingTaxService
{
    public function __construct(
        private WithholdingRuleRepository $ruleRepository
    ) {}
    
    /**
     * Determine applicable withholding for a purchase
     */
    public function calculateForPurchase(
        Document $invoice,
        ?string $transactionType = null
    ): ?WithholdingCalculation {
        $partner = $invoice->partner;
        $amount = $invoice->totalAmount;
        $country = $invoice->company->countryCode;
        
        // Check if partner is exempt
        if ($partner->taxSettings?->isWithholdingExempt) {
            return null;
        }
        
        // Find applicable rule
        $rule = $this->findApplicableRule($partner, $amount, $transactionType, $country);
        
        if (!$rule) {
            return null;
        }
        
        return $rule->calculateWithholding($amount);
    }
    
    /**
     * Find the most specific applicable rule
     */
    private function findApplicableRule(
        Partner $partner,
        Money $amount,
        ?string $transactionType,
        string $countryCode
    ): ?WithholdingTaxRule {
        // Priority: Most specific first
        $rules = $this->ruleRepository->getActiveRulesForCountry($countryCode);
        
        foreach ($rules as $rule) {
            if ($rule->appliesTo($partner, $amount, $transactionType)) {
                return $rule;
            }
        }
        
        return null;
    }
}
```

### WithholdingCertificateService
```php
class WithholdingCertificateService
{
    public function __construct(
        private WithholdingTaxService $taxService,
        private WithholdingCertificateRepository $certificateRepository
    ) {}
    
    /**
     * Process payment with withholding
     */
    public function processPaymentWithWithholding(
        Payment $payment,
        Document $invoice,
        ?float $overrideRate = null,
        ?string $overrideReason = null
    ): PaymentWithholdingResult {
        $calculation = $this->taxService->calculateForPurchase($invoice);
        
        // Apply override if provided
        if ($overrideRate !== null) {
            $calculation = new WithholdingCalculation(
                grossAmount: $invoice->totalAmount,
                rate: Percentage::fromDecimal($overrideRate),
                withholdingAmount: $invoice->totalAmount->multiply($overrideRate),
                netAmount: $invoice->totalAmount->multiply(1 - $overrideRate),
                rule: null,
                overrideReason: $overrideReason
            );
        }
        
        if (!$calculation) {
            return PaymentWithholdingResult::noWithholding($payment);
        }
        
        // Create certificate
        $certificate = WithholdingCertificate::createFromPayment(
            $payment,
            $invoice,
            $calculation
        );
        
        $this->certificateRepository->save($certificate);
        
        return PaymentWithholdingResult::withCertificate($payment, $certificate);
    }
    
    /**
     * Issue certificate (changes status from draft to issued)
     */
    public function issueCertificate(WithholdingCertificate $certificate): void
    {
        $certificate->issue(auth()->user());
        $this->certificateRepository->save($certificate);
        
        event(new WithholdingCertificateIssued($certificate));
    }
}
```

---

## 6. API Endpoints

### Withholding Rules (Admin)
```
GET    /api/admin/withholding-rules                    # List rules
POST   /api/admin/withholding-rules                    # Create rule
PATCH  /api/admin/withholding-rules/{id}               # Update rule
DELETE /api/admin/withholding-rules/{id}               # Deactivate rule
```

### Purchase Withholding
```
GET    /api/documents/{id}/withholding-preview         # Preview withholding for invoice
POST   /api/payments/{id}/apply-withholding            # Apply withholding to payment
```

### Certificates
```
GET    /api/withholding-certificates                   # List certificates
GET    /api/withholding-certificates/{id}              # Get certificate
POST   /api/withholding-certificates/{id}/issue        # Issue certificate
GET    /api/withholding-certificates/{id}/pdf          # Download PDF
POST   /api/withholding-certificates/export-tej        # Export for TEJ
```

### Sales Withholding Tracking
```
POST   /api/documents/{id}/record-withholding          # Record customer withholding
GET    /api/sales-withholding                          # List tracked withholdings
PATCH  /api/sales-withholding/{id}/certificate-received # Mark certificate received
```

---

## 7. Payment Flow (Purchase)

```
┌─────────────────────────────────────────────────────────────────────┐
│                     PURCHASE PAYMENT FLOW                            │
└─────────────────────────────────────────────────────────────────────┘

1. Receive Supplier Invoice
   └─► Invoice: 1,000.000 TND

2. Initiate Payment
   └─► System checks withholding rules
       └─► Supplier tax regime: Individual (forfait)
       └─► Transaction type: Services
       └─► Rule matched: 10% withholding

3. Display Withholding Preview
   ┌───────────────────────────────────┐
   │ Invoice Total:     1,000.000 TND  │
   │ Withholding (10%):  -100.000 TND  │
   │ Net Payment:         900.000 TND  │
   │                                   │
   │ □ Override rate                   │
   └───────────────────────────────────┘

4. Confirm Payment
   └─► Payment of 900.000 TND recorded
   └─► Withholding certificate created (draft)

5. Issue Certificate
   └─► Certificate WHT-2025-0042 issued
   └─► PDF generated
   └─► Ready for TEJ export
```

---

## 8. TEJ Export

### Export Format (Placeholder)
*Note: Actual XML schema to be obtained from Tunisia Ministry of Finance*

```xml
<?xml version="1.0" encoding="UTF-8"?>
<DeclarationRetenue>
    <Declarant>
        <MatriculeFiscal>0000000ABC</MatriculeFiscal>
        <RaisonSociale>Your Company Name</RaisonSociale>
    </Declarant>
    <Periode>
        <Mois>12</Mois>
        <Annee>2025</Annee>
    </Periode>
    <ListeRetenues>
        <Retenue>
            <Beneficiaire>
                <MatriculeFiscal>1111111XYZ</MatriculeFiscal>
                <Nom>Supplier Name</Nom>
            </Beneficiaire>
            <MontantBrut>1000.000</MontantBrut>
            <TauxRetenue>10.00</TauxRetenue>
            <MontantRetenu>100.000</MontantRetenu>
            <DatePaiement>2025-12-15</DatePaiement>
            <NumeroCertificat>WHT-2025-0042</NumeroCertificat>
        </Retenue>
    </ListeRetenues>
</DeclarationRetenue>
```

### Export Service
```php
class TEJExportService
{
    public function exportPeriod(
        Company $company,
        int $year,
        int $month
    ): TEJExportResult {
        $certificates = WithholdingCertificate::query()
            ->where('company_id', $company->id)
            ->whereYear('issued_at', $year)
            ->whereMonth('issued_at', $month)
            ->where('status', CertificateStatus::ISSUED)
            ->where('tej_submitted', false)
            ->get();
        
        if ($certificates->isEmpty()) {
            return TEJExportResult::empty();
        }
        
        $xml = $this->generateXML($company, $certificates, $year, $month);
        
        return TEJExportResult::success($xml, $certificates->count());
    }
}
```

---

## 9. Sales Withholding (As Seller)

When a customer withholds from payment to you:

```
┌─────────────────────────────────────────────────────────────────────┐
│                     SALES WITHHOLDING TRACKING                       │
└─────────────────────────────────────────────────────────────────────┘

1. Issue Invoice to Customer
   └─► Invoice: 1,000.000 TND

2. Customer Pays with Withholding
   └─► Customer withholds 1.5% (15.000 TND)
   └─► You receive 985.000 TND

3. Record in System
   ┌───────────────────────────────────┐
   │ Invoice Total:     1,000.000 TND  │
   │ Customer Withholding:             │
   │   □ Yes, withholding applied      │
   │   Rate: [1.5] %                   │
   │   Amount: 15.000 TND              │
   │ Expected Receivable: 985.000 TND  │
   └───────────────────────────────────┘

4. Treasury Tracking
   └─► Gross receivable: 1,000.000 TND
   └─► Withholding: 15.000 TND (to collect certificate)
   └─► Net receivable: 985.000 TND

5. Certificate Follow-up
   └─► Remind customer for certificate
   └─► Mark received when obtained
```

---

## 10. Reports

### Withholding Summary Report
```
Withholding Tax Summary
Company: [Company Name]
Period: December 2025

PURCHASES (You as Withholder):
─────────────────────────────────────────────────────────────────────
Supplier              Gross        Rate    Withheld    Paid
─────────────────────────────────────────────────────────────────────
ABC Services        1,000.000     10%      100.000    900.000
XYZ Consulting      2,500.000      3%       75.000  2,425.000
─────────────────────────────────────────────────────────────────────
Total               3,500.000              175.000  3,325.000

SALES (Customer Withheld from You):
─────────────────────────────────────────────────────────────────────
Customer            Invoice       Rate    Withheld    Received
─────────────────────────────────────────────────────────────────────
DEF Corp            5,000.000    1.5%       75.000   4,925.000
─────────────────────────────────────────────────────────────────────
Total               5,000.000               75.000   4,925.000

Certificates Pending TEJ Upload: 2
```

---

## 11. Tunisia Seeder

```php
class TunisiaWithholdingRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'country_code' => 'TN',
                'code' => 'TN_PROF_SERVICES_BOOKS_3',
                'name' => 'Services professionnels (régime réel)',
                'transaction_type' => 'services',
                'partner_tax_regime' => 'corporate',
                'rate' => 0.03,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_PROF_SERVICES_OTHER_10',
                'name' => 'Services professionnels (forfait/individuel)',
                'transaction_type' => 'services',
                'partner_tax_regime' => 'individual',
                'rate' => 0.10,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_RENTAL_10',
                'name' => 'Loyers',
                'transaction_type' => 'rental',
                'rate' => 0.10,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_COMMISSIONS_15',
                'name' => 'Commissions et honoraires',
                'transaction_type' => 'commission',
                'rate' => 0.15,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_INTEREST_20',
                'name' => 'Intérêts',
                'transaction_type' => 'interest',
                'rate' => 0.20,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_GOODS_SERVICES_1_5',
                'name' => 'Biens et services (≥1000 TND)',
                'transaction_type' => null,
                'min_amount' => 1000,
                'partner_tax_regime' => 'corporate',
                'rate' => 0.015,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_NON_RESIDENT_15',
                'name' => 'Paiements aux non-résidents',
                'partner_tax_regime' => 'non_resident',
                'rate' => 0.15,
            ],
        ];
        
        foreach ($rules as $rule) {
            WithholdingTaxRule::updateOrCreate(
                ['country_code' => $rule['country_code'], 'code' => $rule['code']],
                array_merge($rule, [
                    'effective_from' => '2024-01-01',
                    'is_active' => true,
                ])
            );
        }
    }
}
```

---

## 12. Testing Requirements

```php
// Rule matching tests
test_matches_rule_by_transaction_type()
test_matches_rule_by_partner_regime()
test_matches_rule_by_amount_threshold()
test_most_specific_rule_takes_precedence()
test_exempt_partner_gets_no_withholding()

// Calculation tests
test_calculates_withholding_amount_correctly()
test_calculates_net_payment_correctly()
test_handles_rate_override()

// Certificate tests
test_certificate_number_sequential_per_year()
test_certificate_created_on_payment()
test_certificate_status_workflow()
test_certificate_pdf_generation()

// Treasury tests
test_payment_records_gross_and_net()
test_withholding_tracked_as_liability()

// Sales withholding tests
test_tracks_customer_withholding()
test_adjusts_expected_receivable()
test_tracks_certificate_receipt()

// Export tests
test_tej_xml_format_valid()
test_tej_export_marks_certificates_submitted()
```

---

## 13. Implementation Order

1. **Database migrations** - Create tables
2. **Tunisia seeder** - Seed withholding rules
3. **Domain models** - Rule, Certificate, Calculation
4. **WithholdingTaxService** - Rule matching, calculation
5. **Payment integration** - Apply withholding at payment
6. **Certificate generation** - Create and issue certificates
7. **PDF generation** - Certificate document
8. **Sales withholding** - Track customer withholding
9. **Reports** - Summary reports
10. **TEJ export** - XML generation (once schema confirmed)
11. **Frontend** - Payment withholding UI
12. **Frontend** - Certificate management UI
