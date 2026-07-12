import { describe, expect, it } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useBankAccountValidation } from '../useBankAccountValidation'

/**
 * The hook is pure logic wrapped in useMemo, so renderHook exercises the same
 * code path a form row uses. These pins mirror the backend BankAccountValidator
 * suite so the two layers cannot silently drift apart.
 */
describe('useBankAccountValidation', () => {
  it('validates the worked Tunisian RIB and derives its IBAN', () => {
    const { result } = renderHook(() =>
      useBankAccountValidation('07040005810111129653', 'TN', 'rib'),
    )

    expect(result.current.status).toBe('valid')
    expect(result.current.normalized).toBe('07040005810111129653')
    expect(result.current.derivedIban).toBe('TN5907040005810111129653')
    expect(result.current.errors).toEqual([])
  })

  it('rejects a corrupted-key RIB without deriving an IBAN', () => {
    const { result } = renderHook(() =>
      useBankAccountValidation('07040005810111129654', 'TN', 'rib'),
    )

    expect(result.current.status).toBe('invalid')
    expect(result.current.derivedIban).toBeNull()
    expect(result.current.errors).toContain('invalid_checksum')
  })

  it('validates a 20-digit RIB past 2^53 without Number() precision loss', () => {
    // 09999999999999999983 > Number.MAX_SAFE_INTEGER; a naive Number() checksum
    // would silently round it. The digit-string mod-97 must still accept it.
    const { result } = renderHook(() =>
      useBankAccountValidation('09999999999999999983', 'TN', 'rib'),
    )

    expect(result.current.status).toBe('valid')
    expect(result.current.normalized).toBe('09999999999999999983')
  })

  it('normalizes spaces and hyphens in the RIB, preserving the digit string', () => {
    const { result } = renderHook(() =>
      useBankAccountValidation('0704-0005 8101 1112-9653', 'TN', 'rib'),
    )

    expect(result.current.status).toBe('valid')
    expect(result.current.normalized).toBe('07040005810111129653')
  })

  it('accepts the normalized Tunisian IBAN (with spaces)', () => {
    const { result } = renderHook(() =>
      useBankAccountValidation('TN59 0704 0005 8101 1112 9653', 'TN', 'iban'),
    )

    expect(result.current.status).toBe('valid')
    expect(result.current.normalized).toBe('TN5907040005810111129653')
  })

  it.each([
    ['00 (alias of true 97)', 'TN0000000000000000000092'],
    ['01 (alias of true 98)', 'TN0100000000000000000074'],
    ['99 (alias of true 02)', 'TN9900000000000000000056'],
  ])('rejects ISO-forbidden IBAN check digits %s', (_label, iban) => {
    const { result } = renderHook(() => useBankAccountValidation(iban, 'TN', 'iban'))

    expect(result.current.status).toBe('invalid')
    expect(result.current.errors).toContain('invalid_check_digits')
  })

  it('marks a foreign IBAN as unsupported', () => {
    const { result } = renderHook(() =>
      useBankAccountValidation('FR7630004001230000123456725', 'TN', 'iban'),
    )

    expect(result.current.status).toBe('unsupported')
    expect(result.current.errors).toContain('unsupported_country')
  })
})
