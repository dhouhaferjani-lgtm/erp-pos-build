/**
 * The tokens SPEC §2.4 (F-95) forbids on a PROFORMA rendering, and a translator
 * `t` that resolves REAL copy so the scan means something — C-F0w.
 *
 * The list is the front-end half of `ProformaOutputTest::FORBIDDEN_TOKENS`. A web
 * component test that mocks `t` into an identity function proves nothing here: the
 * rendered HTML would contain `documents.subtotal`, not `Subtotal`, and every
 * forbidden token would be invisible to the scan. So `translateFrom()` resolves
 * against the authored `en` bundles the app actually ships.
 *
 * `\bHT\b` is anchored and case-SENSITIVE for the reason the backend gives: an
 * unanchored `ht` matches `height`, `right` and `white`.
 */
export const FORBIDDEN_PROFORMA_TOKENS: readonly RegExp[] = [
  // — the TAX half: `ProformaOutputTest::FORBIDDEN_TOKENS`, verbatim —
  /VAT/i,
  /TVA/i,
  /\btax/i,
  /TTC/i,
  /\bHT\b/,
  /fiscal_hash/i,
  /chain/i,
  /\bQR\b/i,
  /comptabilis/i,
  // — the SEAL AND SETTLEMENT half, which the web needs and the PDF does not —
  //
  // A blade has no status chip to render; a detail PAGE does, and gate r1 W-1
  // caught exactly that: an unsealed invoice wearing a green `Posted` chip and an
  // `Unpaid` chip beside a banner saying the sale has not been entered in the
  // accounts. `Paid` / `Unpaid` are here for the reason
  // `components/proforma_totals_rows.blade.php` drops the settlement rows — "a
  // proforma is not a statement of account" — which the backend never has to state
  // as a token because no blade could emit one.
  /sealed/i,
  /posted/i,
  /\bunpaid\b/i,
  /\bpaid\b/i,
]

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

export function expectNoForbiddenToken(html: string, context: string): void {
  for (const pattern of FORBIDDEN_PROFORMA_TOKENS) {
    const hits = [...html.matchAll(new RegExp(pattern.source, `${pattern.flags}g`))].map(
      (hit) => hit[0]
    )
    if (hits.length > 0) {
      throw new Error(
        `${context} must not contain ${String(pattern)} — found: ${hits.slice(0, 5).join(', ')}`
      )
    }
  }
}

/**
 * A `t()` backed by real translation bundles: `translateFrom({ sales, common })`.
 * Supports the `ns:dotted.key` form the components use and the bare dotted form
 * resolved against the first bundle.
 */
export function translateFrom(
  bundles: Record<string, Record<string, unknown>>,
  defaultNamespace: string
): (key: string, params?: Record<string, unknown>) => string {
  return (key: string, params?: Record<string, unknown>): string => {
    const [namespace, path] = key.includes(':')
      ? [key.slice(0, key.indexOf(':')), key.slice(key.indexOf(':') + 1)]
      : [defaultNamespace, key]

    let node: unknown = bundles[namespace]
    for (const segment of path.split('.')) {
      if (!isRecord(node)) {
        return key
      }
      node = node[segment]
    }

    if (typeof node !== 'string') {
      return key
    }

    let result = node
    for (const [name, value] of Object.entries(params ?? {})) {
      result = result.replace(`{{${name}}}`, String(value))
    }

    return result
  }
}
