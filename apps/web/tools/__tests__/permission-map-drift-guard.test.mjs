// @ts-check
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

const root = resolve(process.cwd(), '../..')
const preflight = readFileSync(`${root}/scripts/preflight.sh`, 'utf8')
const workflow = readFileSync(`${root}/.github/workflows/ci.yml`, 'utf8')

describe('generated frontend permission map drift guard', () => {
  it('regenerates and checks the map during local preflight', () => {
    expect(preflight).toContain('php artisan permissions:export-frontend-map')
    expect(preflight).toContain('apps/web/src/hooks/permissionsMap.generated.ts')
    expect(preflight).toContain('git -C "$ROOT_DIR" diff --quiet -- "$GENERATED_PERMISSIONS"')
  })

  it('regenerates and checks the map in CI', () => {
    expect(workflow).toContain('Regenerate frontend permission map')
    expect(workflow).toContain('php artisan permissions:export-frontend-map')
    expect(workflow).toContain('git diff --quiet -- apps/web/src/hooks/permissionsMap.generated.ts')
  })
})
