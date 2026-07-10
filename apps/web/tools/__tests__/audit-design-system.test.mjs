// @ts-check
import { describe, expect, it } from 'vitest'

import {
  partitionViolationsByBaseline,
  scanCode,
  violationBaselineKey,
} from '../audit-design-system.mjs'

describe('design-system audit scanner', () => {
  it('flags bespoke page h1 headers', () => {
    const violations = scanCode(`
      export function ExamplePage() {
        return <h1 className="text-2xl font-bold">Title</h1>
      }
    `, 'src/features/example/ExamplePage.tsx')

    expect(violations).toHaveLength(1)
    expect(violations[0].category).toBe('C1')
  })

  it('flags arbitrary-size page h1 headers that evade text-2xl detection', () => {
    const violations = scanCode(`
      export function ExamplePage() {
        return (
          <>
            <h1 className="text-[1.875rem] leading-9 font-bold">Title</h1>
            <h1 className="text-[2rem] leading-10 font-bold">Other title</h1>
          </>
        )
      }
    `, 'src/features/example/ExamplePage.tsx')

    expect(violations).toHaveLength(2)
    expect(violations.every((violation) => violation.category === 'C1')).toBe(true)
  })

  it('flags raw form controls that use design token classes', () => {
    const violations = scanCode(`
      export function ExampleForm() {
        return <input className={tokens.input.base} />
      }
    `, 'src/features/example/ExampleForm.tsx')

    expect(violations).toHaveLength(1)
    expect(violations[0].category).toBe('C2')
  })

  it('does not truncate JSX tags at arrow functions inside attributes', () => {
    const violations = scanCode(`
      export function ExampleForm() {
        return (
          <input
            onChange={(event) => setValue(event.target.value)}
            className={tokens.input.base}
          />
        )
      }
    `, 'src/features/example/ExampleForm.tsx')

    expect(violations).toHaveLength(1)
    expect(violations[0].category).toBe('C2')
  })

  it('flags feature form files without react-hook-form', () => {
    const violations = scanCode(`
      export function ExampleCreatePage() {
        return <form onSubmit={handleSubmit}><button type="submit">Save</button></form>
      }
    `, 'src/features/example/ExampleCreatePage.tsx')

    expect(violations.some((violation) => violation.category === 'C4')).toBe(true)
  })

  it('separates baselined and new design-system violations', () => {
    const violations = scanCode(`
      export function ExamplePage() {
        return <h1 className="text-2xl font-bold">Title</h1>
      }
    `, 'src/features/example/ExamplePage.tsx')
    const baseline = new Set([violationBaselineKey(violations[0])])

    const result = partitionViolationsByBaseline([
      ...violations,
      ...scanCode(`
        export function OtherPage() {
          return <table><tbody /></table>
        }
      `, 'src/features/example/OtherPage.tsx'),
    ], baseline)

    expect(result.baselined).toHaveLength(1)
    expect(result.newViolations).toHaveLength(1)
    expect(result.staleBaselineEntries).toEqual([])
  })

  it('disambiguates duplicate violations with per-file ordinals', () => {
    const violations = scanCode(`
      export function ExampleForm() {
        return (
          <>
            <input className={tokens.input.base} />
            <input className={tokens.input.base} />
          </>
        )
      }
    `, 'src/features/example/ExampleForm.tsx')

    expect(violations).toHaveLength(2)
    expect(violationBaselineKey(violations[0])).toContain('|#1')
    expect(violationBaselineKey(violations[1])).toContain('|#2')
    expect(violationBaselineKey(violations[0])).not.toBe(violationBaselineKey(violations[1]))
  })

  it('flags untyped status map constants as local status maps', () => {
    const violations = scanCode(`
      const statusMap = {
        draft: 'bg-gray-100 text-gray-800',
      }

      export function ExamplePage() {
        return <StatusBadge className={statusMap.draft}>Draft</StatusBadge>
      }
    `, 'src/features/example/ExamplePage.tsx')

    expect(violations.some((violation) => violation.category === 'C6')).toBe(true)
  })
})
