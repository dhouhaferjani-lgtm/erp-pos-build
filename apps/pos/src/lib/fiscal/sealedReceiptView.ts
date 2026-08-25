/**
 * Read a receipt's SEALED aggregates back out of its canonical bytes.
 *
 * ## Why this exists (D-1, owner ruling 2026-08-25)
 *
 * `offline_receipts` stores a MIXED view of a receipt on purpose: `subtotal` is
 * the GROSS (TTC) line roll-up, `total` is the ROUNDED post-remise gross, and
 * since D-1 `tax_amount` is the SEALED post-remise VAT. The per-rate
 * ventilation of the ticket-level remise is not a column at all.
 *
 * Every device-side fiscal aggregate — the Z report, SESSION_CLOSE, the
 * end-of-day preview — must report what the chain DECLARED, not a figure
 * re-derived from columns on three different bases. The canonical bytes are
 * already on the row (`offline_receipts.canonical_bytes`), so the sealed
 * `subtotal` / `vat_total` / `vat_breakdown[]` are recoverable EXACTLY, for
 * every version, with no device migration and no re-derivation:
 *
 *   - a v5 receipt yields its POST-remise base and per-rate ventilation;
 *   - a v1..v4 receipt yields the PRE-discount rows it actually declared.
 *
 * That version-faithfulness is the point. Re-ventilating an old receipt at
 * report time would make the report disagree with the immutable document it
 * summarises — the exact failure D-1 exists to remove, moved one layer up.
 *
 * Fail-soft by design: an absent or unparseable blob returns `null`, and the
 * caller falls back to its pre-D-1 column arithmetic. A report must degrade to
 * the older figure, never throw and block a shift close.
 */

/** One sealed `vat_breakdown[]` row. */
export interface SealedVatGroup {
  /** Percent at `vat_rate_scale` (e.g. `'19.00'`). */
  readonly rate: string;
  /** Taxable base — POST-remise at v5, pre-discount at v1..v4. */
  readonly netAmount: string;
  readonly vatAmount: string;
  readonly grossAmount: string;
  /** v5 only; `null` on a receipt sealed before D-1. */
  readonly discountAllocated: string | null;
}

/** The sealed aggregates of one receipt. */
export interface SealedReceiptView {
  /** The canonical `subtotal` — the taxable base the chain declares. */
  readonly subtotal: string;
  readonly vatTotal: string;
  readonly total: string;
  readonly vatBreakdown: readonly SealedVatGroup[];
}

function asString(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * Parse the sealed aggregates out of a receipt's canonical bytes.
 *
 * @returns `null` when the blob is absent, unparseable, or structurally not a
 *          SALE_RECEIPT payload — never a partially-populated view.
 */
export function readSealedReceiptView(bytes: string | null | undefined): SealedReceiptView | null {
  if (typeof bytes !== 'string' || bytes === '') return null;

  let parsed: unknown;
  try {
    parsed = JSON.parse(bytes);
  } catch {
    return null;
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) return null;

  // `offline_receipts.canonical_bytes` holds the ENVELOPE (spec §4) with the
  // payload nested under `payload`; the payload alone is what the encoder
  // returns in isolation. Accept either so the helper works against a stored
  // row AND against bytes handed straight from the builder.
  const envelope = parsed as Record<string, unknown>;
  const nested = envelope['payload'];
  const payload = (typeof nested === 'object' && nested !== null && !Array.isArray(nested))
    ? nested as Record<string, unknown>
    : envelope;

  const subtotal = asString(payload['subtotal']);
  const vatTotal = asString(payload['vat_total']);
  const total = asString(payload['total']);
  const rows = payload['vat_breakdown'];
  if (subtotal === null || vatTotal === null || total === null || !Array.isArray(rows)) {
    return null;
  }

  const vatBreakdown: SealedVatGroup[] = [];
  for (const row of rows) {
    if (typeof row !== 'object' || row === null || Array.isArray(row)) return null;
    const r = row as Record<string, unknown>;
    const rate = asString(r['rate']);
    const netAmount = asString(r['net_amount']);
    const vatAmount = asString(r['vat_amount']);
    const grossAmount = asString(r['gross_amount']);
    if (rate === null || netAmount === null || vatAmount === null || grossAmount === null) {
      return null;
    }
    vatBreakdown.push({
      rate,
      netAmount,
      vatAmount,
      grossAmount,
      discountAllocated: asString(r['discount_allocated']),
    });
  }

  return { subtotal, vatTotal, total, vatBreakdown };
}
