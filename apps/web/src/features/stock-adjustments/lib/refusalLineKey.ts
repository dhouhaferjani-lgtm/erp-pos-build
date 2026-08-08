/**
 * The (product, variant, lot) identity that §2's refusal payload, §2's PATCH
 * contract and the DB's four partial uniques all key on.
 *
 * Deliberately NOT `line_id`: an immediate-post refusal rolls its draft back
 * with the transaction, so there is no line id to key on — which is exactly why
 * the payload carries this tuple instead.
 *
 * Its own module rather than a component file so Fast Refresh keeps working.
 */
export function refusalLineKey(line: {
  product_id: string
  variant_id: string | null
  batch_uuid: string | null
}): string {
  return `${line.product_id}|${line.variant_id ?? ''}|${line.batch_uuid ?? ''}`
}
