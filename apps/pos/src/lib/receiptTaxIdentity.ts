/**
 * Display-only tax-identity helpers for the printed ticket.
 *
 * DEV-QA-092: the ESC/POS template prints `company.tax_id` and
 * `company.vat_number` as two unconditional header lines
 * (`src-tauri/src/printing/receipt_template.rs`), and every builder fills both
 * from the SAME establishment record. In Tunisia the matricule fiscal *is* the
 * VAT identifier (`CountriesSeeder.php` labels TN's tax id "Matricule
 * Fiscal"), so the customer sees the same number twice.
 *
 * The dedup is **display-only**: it applies to the printed `ReceiptData`
 * only. `lib/fiscal/sellerIdentity.ts` and everything feeding the SIGNED
 * seller block are untouched — the signed payload keeps both fields verbatim.
 */

/**
 * Canonical comparison form for a tax/VAT identifier: trimmed, upper-cased,
 * internal whitespace collapsed to a single space. Formatting-only differences
 * ("1234567/a/m/000" vs " 1234567/A/M/000 ") must not defeat the dedup.
 */
export function normalizeTaxIdentifier(value: string | null | undefined): string {
  if (value === null || value === undefined) {
    return '';
  }
  return value.trim().replace(/\s+/g, ' ').toUpperCase();
}

/**
 * Returns the VAT number to PRINT beside the tax id, or `null` when it would
 * merely repeat the tax id (or is blank). The kept value is returned verbatim
 * — normalisation is a comparison device, never a rewrite of what prints.
 */
export function dedupeVatNumber(
  taxId: string | null | undefined,
  vatNumber: string | null | undefined,
): string | null {
  const normalizedVatNumber = normalizeTaxIdentifier(vatNumber);
  if (normalizedVatNumber === '') {
    return null;
  }
  if (normalizedVatNumber === normalizeTaxIdentifier(taxId)) {
    return null;
  }
  return vatNumber ?? null;
}
