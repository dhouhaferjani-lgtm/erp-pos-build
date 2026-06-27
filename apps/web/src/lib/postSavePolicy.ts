/** Editors that intentionally return to their list after save (batch /
 *  reference-data entry). Every other editor defaults to stay-on-record.
 *  Add new exceptions here explicitly — never via a scattered ad-hoc flag. */
export const LIST_RETURN_EXCEPTIONS = [
  'menu', 'promotion', 'coupon',
  'parapharmacy.ingredient', 'parapharmacy.certification',
  'parapharmacy.healthClaim', 'parapharmacy.keyComponent',
] as const

export type ListReturnException = typeof LIST_RETURN_EXCEPTIONS[number]

export function isListReturnException(key: string): key is ListReturnException {
  return LIST_RETURN_EXCEPTIONS.some((exception) => exception === key)
}
