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
})
