# Compliance Architecture

## Two-Tier Hash Chain

### Tier 1: Fiscal Chain (Compliance)

- Covers: posted invoices, credit notes, payments, fiscal closings
- SHA-256 hash chain with `previous_hash` reference
- Required for NF525/ZATCA compliance
- Separate chains per document type per tenant

```php
class FiscalHashService
{
    public function calculateHash(FiscalDocument $doc, ?string $previousHash): string
    {
        $data = $this->serializeForHashing($doc);
        $payload = $previousHash . '|' . $data;
        return hash('sha256', $payload);
    }

    public function verifyChain(string $tenantId, string $documentType): bool
    {
        // Loads all posted documents in chain_sequence order
        // Recomputes each hash and compares to stored hash
        // Returns false if any mismatch (chain broken)
    }
}
```

### Tier 2: Audit Log (Fraud Detection)

- Covers: ALL domain events (quotes, orders, drafts, stock, user actions)
- Individual event hashes (not chained to fiscal documents)
- Stored in TimescaleDB for time-series queries
- Enables anomaly detection and operational audit

## Event-First Pattern

```php
DB::transaction(function () use ($invoice) {
    // 1. Create event with hash chain
    // 2. Update read model state
    // 3. Create GL entries
});
```

**Critical:** Event creation happens FIRST inside the transaction. State updates follow.

## NF525 Readiness (POS)

Required for French POS certification:
- Z-reports (daily closings with grand totals)
- Perpetual totals (cumulative across periods)
- Receipt chaining (separate from invoice chain)
- Technical event log (JET)
- Duplicate/reprint tracking
- Digital signature (RSA 2048 or ECDSA 256)

### ANNULATION → RETOUR: correction representation after the V9 void sunset (2026-08-08)

Since DPA V9 (owner ruling D3) retired the legacy `POST /pos/receipts/{id}/void`,
**no production code writes `is_voided = true` any more** — the only assignments left
are `=> false` (`PosCoreReceiptProjection.php:372`, `ReceiptCreationService.php:603`,
`ReceiptReturnService.php:773`). The NF525 `<Annulations type="ANNULATION">` section is
built by `Nf525XmlBuilder::addVoids()` (`:193-215`) from `Nf525DataProvider.php:161-177`,
which is a **DB-driven query** (`where('is_voided', true)` + `whereBetween('voided_at', …)`),
**not** event-driven — so the section is now permanently unproducible for NEW events
while **historical voids still export correctly** through the same date-windowed query
(no historical fiscal data is lost — do NOT "fix" the empty section by resurrecting an
`is_voided` writer). The successor representation is **RETOUR by design**: a
device-authored correction resolves to `ReceiptType::Return`
(`PosCoreReceiptProjection::resolveReceiptType()` `:568-575`) and is exported as
`<Retours type="RETOUR">` (`Nf525DataProvider.php:179-198`, `Nf525XmlBuilder.php:216-247`)
carrying `TicketOriginal` + the fiscal hash — a chained, append-only correcting document,
strictly stronger than the in-place mutation it replaced. A full cancellation is therefore
expressed as a **full-quantity REFUND rendered as RETOUR**; device authoring of
`invoice_type_code = 'VOID'` is hard-refused
(`apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:139-152,237`).
**Expert-comptable / owner ratification of this representation shift is OWED** (the open
question from `docs/superpowers/reviews/2026-07-31-codex-refund-chain-spec-review.md:168`).

## E-Invoicing (Factur-X)

For French B2B invoices:
- Generate Factur-X XML (EN 16931 compliant)
- Embed in PDF/A-3
- Submit to PDP (Plateforme de Dematérialisation Partenaire)
- Track submission status and responses
