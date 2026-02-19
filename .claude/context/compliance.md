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

## E-Invoicing (Factur-X)

For French B2B invoices:
- Generate Factur-X XML (EN 16931 compliant)
- Embed in PDF/A-3
- Submit to PDP (Plateforme de Dematérialisation Partenaire)
- Track submission status and responses
