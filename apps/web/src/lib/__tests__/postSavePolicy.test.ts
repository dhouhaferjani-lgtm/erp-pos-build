import { describe, it, expect } from 'vitest'
import { isListReturnException, LIST_RETURN_EXCEPTIONS } from '../postSavePolicy'

describe('postSavePolicy', () => {
  it('lists the known batch/reference-data exceptions', () => {
    expect(LIST_RETURN_EXCEPTIONS).toEqual([
      'menu', 'promotion', 'coupon',
      'parapharmacy.ingredient', 'parapharmacy.certification',
      'parapharmacy.healthClaim', 'parapharmacy.keyComponent',
    ])
  })

  it('treats stay-on-record editors as non-exceptions', () => {
    expect(isListReturnException('product')).toBe(false)
    expect(isListReturnException('loyalty.program')).toBe(false)
    expect(isListReturnException('document')).toBe(false)
  })

  it('recognizes every declared exception', () => {
    for (const key of LIST_RETURN_EXCEPTIONS) {
      expect(isListReturnException(key)).toBe(true)
    }
  })
})
