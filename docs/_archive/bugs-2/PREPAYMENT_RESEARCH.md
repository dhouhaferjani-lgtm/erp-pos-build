# Prepayment/Advance Payment Research

> Research Date: 2025-12-11
> Purpose: Inform implementation of BUG-003 (Payment allocation on orders)

---

## Executive Summary

After thorough research on prepayment/advance payment treatment in France, Tunisia, and OHADA African countries, the key finding is:

**Prepayments require a specific document type ("Facture d'acompte" / "Prepayment Invoice"), NOT allocation to sales orders.**

This fundamentally changes our approach: instead of allowing payment allocation to orders, we should implement a **Prepayment Invoice** document type.

---

## 1. France (PCG - Plan Comptable Général)

### Legal Framework

**Key Regulation:** Article 289 of the French Tax Code (CGI)

> "Tout versement d'acompte avant que se produise le fait générateur doit donner lieu à l'émission d'une facture par le vendeur ou le prestataire."
> (Every prepayment before the triggering event must result in the issuance of an invoice by the seller or service provider.)

### Critical Change: January 1, 2023

Since January 1, 2023, **VAT is due immediately upon receipt of prepayment**, regardless of whether it's for goods or services. Previously, goods had different treatment than services.

Source: [TVA sur les acomptes 2023 - Eurofiscalis](https://www.eurofiscalis.com/tva-sur-les-acomptes-2023-france/)

### Document Flow

```
1. Customer Order (Bon de Commande) - NO payment
2. Prepayment Invoice (Facture d'acompte) - WITH VAT
3. Work/Delivery happens
4. Final Invoice (Facture de solde) - Deducts prepayment
```

### Accounting Treatment

**Account 4191 - Clients - Avances et acomptes reçus sur commandes**

| Event | Debit | Credit |
|-------|-------|--------|
| Receive prepayment | 512 Banque | 4191 Clients - Avances |
| Record VAT | 4191 (partial) | 44571 TVA collectée |
| Issue final invoice | 4191 Clients - Avances | 411 Clients |
| Record sale | 411 Clients | 7xx Ventes |

Source: [Compte 4191 - L'Expert Comptable](https://www.l-expert-comptable.com/plan-comptable/compte-4191-clients-avances-et-acomptes-recus-sur-commande)

### Required Invoice Mentions

A "Facture d'acompte" must include:
- The words "Facture d'acompte"
- Reference to original quote/order
- VAT breakdown (since 2023)
- Sequential invoice number (part of fiscal sequence)

Source: [Facture d'acompte mentions obligatoires - LegalPlace](https://www.legalplace.fr/guides/facture-acompte/)

---

## 2. Tunisia (Plan Comptable Tunisien)

### Framework

Tunisia uses a chart of accounts heavily influenced by the French PCG.

**Account 419 - Clients créditeurs**
- **4191**: Clients – avances et acomptes reçus sur commandes
- **4196**: Clients – dettes pour emballages et matériel consignés

Source: [Nomenclature des comptes - Alliance Tunisie](https://alliance-tunisie.com/wp-content/uploads/2019/04/Nomenclature-et-Fonctionnement-des-comptes.pdf)

### Accounting Treatment

Identical to French PCG:
- Prepayment is recorded as liability (credit 4191)
- On final invoice, 4191 is debited to clear the advance
- VAT treatment follows similar rules

Source: [Le Plan Comptable Tunisien - Legalstart.tn](https://legalstart.tn/le-plan-comptable-tunisien/)

---

## 3. OHADA Countries (West/Central Africa)

### Framework: SYSCOHADA

OHADA (Organisation pour l'Harmonisation en Afrique du Droit des Affaires) covers 17 countries:
Benin, Burkina Faso, Cameroon, Central African Republic, Chad, Comoros, Congo, DRC, Equatorial Guinea, Gabon, Guinea, Guinea-Bissau, Ivory Coast, Mali, Niger, Senegal, Togo.

The revised SYSCOHADA (effective January 1, 2018) uses 9 account classes.

**Class 4 - Comptes de tiers** handles customer advances.

Source: [OHADA Accounting Plan - ResearchGate](https://www.researchgate.net/publication/332556761_OHADA_ACCOUNTING_PLAN)

### Account Structure

Similar to PCG/Tunisia:
- **419x**: Customer credit accounts (advances received)
- Treatment as liability until delivery/service completion

---

## 4. ERP Best Practices (SAP, Oracle)

### SAP S/4HANA

SAP distinguishes between:
1. **Down Payment Request** - Internal document for tracking expected prepayment
2. **Down Payment** - Actual receipt of money (creates FI document)
3. **Sales Order** - Operational document, separate from financial
4. **Invoice** - Final billing document that clears down payment

Key point: SAP uses **Special G/L Indicator 'A'** for down payments to track them separately from regular receivables.

Source: [SAP Prepayment Help](https://help.sap.com/docs/SAP_S4HANA_ON-PREMISE/af9ef57f504840d2b81be8667206d485/d6a8b9537cceb44ce10000000a174cb4.html)

### Oracle Order Management

Oracle supports "prepayments (commitments)" as a payment type on orders, but:
- The prepayment must be recorded as a separate transaction
- It's tracked through the order lifecycle
- Final settlement happens at invoicing

Source: [Oracle Order Management Guide](https://docs.oracle.com/cd/E18727_01/doc.121/e13408/T335476T429682.htm)

### JD Edwards EnterpriseOne

> "Prepayment of an order takes place when a seller receives a form of payment from the customer at the time of order entry."

However, the prepayment creates specific transaction records and is settled against the final invoice.

Source: [JD Edwards Prepayment Processing](https://docs.oracle.com/en/applications/jd-edwards/supply-chain-manufacturing/9.2/eoaso/understanding-prepayment-processing.html)

---

## 5. Key Distinction: Prepayment vs Payment Allocation

| Concept | Prepayment (Acompte) | Payment Allocation |
|---------|---------------------|-------------------|
| **When** | Before delivery/service | After invoice posted |
| **Document** | Prepayment Invoice | Regular Payment |
| **Accounting** | Liability (4191) | Reduces AR (411) |
| **VAT** | Due immediately (FR) | Already on invoice |
| **Clears against** | Final invoice | Existing invoice |

**Critical insight:** A prepayment is NOT a payment against an order. It's a payment against a **Prepayment Invoice** (Facture d'acompte), which is a fiscal document.

---

## 6. Recommended Implementation for AutoERP

### Option A: Prepayment Invoice Document Type (RECOMMENDED)

Create a new document type: `DocumentType::PrepaymentInvoice` (or `facture_acompte`)

**Flow:**
```
1. Quote → Order (no payment yet)
2. Customer pays deposit → System creates Prepayment Invoice
   - Generates fiscal number (INV-XXXX or ACP-XXXX)
   - Records VAT
   - Creates GL entry: Dr 512, Cr 4191
3. Order fulfilled → Final Invoice created
   - References Prepayment Invoice
   - Deducts prepayment from total
   - Settles 4191 account
```

**Benefits:**
- Fiscally compliant (France, Tunisia, OHADA)
- Clear audit trail
- Proper VAT handling
- Works with existing invoice numbering

**Implementation:**
1. Add `PrepaymentInvoice` to DocumentType enum
2. Add `source_prepayment_ids` to track linked prepayments
3. Modify final invoice creation to deduct prepayments
4. Add "Create Prepayment Invoice" action on confirmed orders

### Option B: Customer Deposit Account (Simpler, Less Compliant)

Record prepayments as customer deposits without creating fiscal documents.

**Issues:**
- May not satisfy French VAT requirements (facture d'acompte mandatory)
- No fiscal trail for the advance
- Less clarity in financial reporting

### Option C: Keep Current Restriction (Status Quo)

Don't allow any prepayments on orders. User must wait for invoice.

**Issues:**
- Poor UX for businesses that take deposits
- Doesn't match industry practice
- Loses potential customers to competitors

---

## 7. Conclusion & Recommendation

**Recommendation: Implement Option A - Prepayment Invoice**

This approach:
1. Satisfies French legal requirements (Article 289 CGI)
2. Works with Tunisian PCG
3. Compatible with OHADA SYSCOHADA
4. Aligns with SAP/Oracle best practices
5. Provides proper fiscal trail

**The current bug (BUG-003) is NOT about allocating payments to orders.** It's about missing the Prepayment Invoice document type entirely.

---

## Sources

### French Accounting
- [Compte 4191 - L'Expert Comptable](https://www.l-expert-comptable.com/plan-comptable/compte-4191-clients-avances-et-acomptes-recus-sur-commande)
- [Facture d'acompte - Compta-Online](https://www.compta-online.com/essentiel-savoir-sur-la-facture-acompte-ao2824)
- [TVA sur les acomptes 2023 - Eurofiscalis](https://www.eurofiscalis.com/tva-sur-les-acomptes-2023-france/)
- [Comptabilisation avance acompte - Compta-Facile](https://www.compta-facile.com/comptabilisation-avance-acompte/)

### Tunisia
- [Le Plan Comptable Tunisien - Legalstart.tn](https://legalstart.tn/le-plan-comptable-tunisien/)
- [Nomenclature des comptes - Alliance Tunisie](https://alliance-tunisie.com/wp-content/uploads/2019/04/Nomenclature-et-Fonctionnement-des-comptes.pdf)

### OHADA
- [OHADA Accounting Plan - ResearchGate](https://www.researchgate.net/publication/332556761_OHADA_ACCOUNTING_PLAN)
- [SYSCOHADA Implementation - OHADA.org](https://www.ohada.org/en/publication-of-a-new-uniform-act-on-accounting-law-and-financial-reporting-uaafr/)

### ERP Systems
- [SAP Prepayment Processing](https://help.sap.com/docs/SAP_S4HANA_ON-PREMISE/af9ef57f504840d2b81be8667206d485/d6a8b9537cceb44ce10000000a174cb4.html)
- [Oracle Order Management](https://docs.oracle.com/cd/E18727_01/doc.121/e13408/T335476T429682.htm)
- [JD Edwards Prepayment Processing](https://docs.oracle.com/en/applications/jd-edwards/supply-chain-manufacturing/9.2/eoaso/understanding-prepayment-processing.html)

### General Accounting
- [Customer Advance Payments - AccountingTools](https://www.accountingtools.com/articles/how-to-account-for-customer-advance-payments.html)
- [Customer Prepayments - Microsoft Dynamics 365](https://learn.microsoft.com/en-us/dynamics365/finance/accounts-receivable/customer-prepayments)
