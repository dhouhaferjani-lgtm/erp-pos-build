/**
 * Cross-language module-vocabulary drift gate.
 *
 * Guards against the frontend `BACKEND_MODULES` union (src/lib/modules.ts,
 * used for sidebar/feature gating) silently drifting from the backend truth
 * in `apps/api/config/verticals.php` (`default_modules` + `compatible_extras`
 * arrays). The backend has its own sibling guard —
 * `apps/api/tests/Unit/Enums/ModuleNameTest.php` asserts the PHP ModuleName
 * enum equals the config union — this test closes the PHP↔TS gap.
 *
 * The PHP config is parsed as TEXT (no PHP execution): every quoted string
 * inside each `'default_modules' => [...]` / `'compatible_extras' => [...]`
 * array is extracted. Sanity assertions on the extraction counts ensure a
 * silent regex failure (e.g. after a config reformat) cannot fake a pass.
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

import { BACKEND_MODULES } from './modules'

/**
 * Module names that legitimately exist only on the frontend side. Empty
 * today; if a frontend-only module name is ever introduced, add it here so
 * the reverse-direction contract below stays green.
 */
const FRONTEND_ONLY_ALLOWLIST: readonly string[] = []

// src/lib → src → web → apps, then into api/config.
const configPath = resolve(
  dirname(fileURLToPath(import.meta.url)),
  '../../../api/config/verticals.php',
)

const configText = readFileSync(configPath, 'utf8')

interface Extraction {
  readonly names: ReadonlySet<string>
  readonly arrayKeyOccurrences: number
}

function extractModuleNames(phpText: string): Extraction {
  // Matches both inline `['A', 'B']` and multiline arrays (with trailing
  // commas). The arrays never nest, so "everything up to the next `]`" is a
  // safe capture.
  const arrayPattern =
    /'(?:default_modules|compatible_extras)'\s*=>\s*\[([^\]]*)\]/g
  const names = new Set<string>()
  let arrayKeyOccurrences = 0

  for (const arrayMatch of phpText.matchAll(arrayPattern)) {
    arrayKeyOccurrences += 1
    const body = arrayMatch[1]
    for (const nameMatch of body.matchAll(/'([^']+)'/g)) {
      names.add(nameMatch[1])
    }
  }

  return { names, arrayKeyOccurrences }
}

describe('module vocabulary drift gate (verticals.php ↔ modules.ts)', () => {
  const { names, arrayKeyOccurrences } = extractModuleNames(configText)

  it('extraction is plausible (regex did not silently fail)', () => {
    // verticals.php currently has 12 verticals × 2 arrays = 24 occurrences
    // and 22 distinct module names. Floors are deliberately loose so adding
    // or removing one vertical does not break this sanity check.
    expect(
      arrayKeyOccurrences,
      `Expected >= 12 default_modules/compatible_extras arrays in ${configPath} — the extraction regex may be broken`,
    ).toBeGreaterThanOrEqual(12)
    expect(
      names.size,
      `Expected >= 10 distinct module names extracted from ${configPath} — the extraction regex may be broken`,
    ).toBeGreaterThanOrEqual(10)
  })

  it('every backend module name is present in BACKEND_MODULES', () => {
    const frontendModules = new Set<string>(BACKEND_MODULES)
    const missing = [...names].filter((name) => !frontendModules.has(name))

    expect(
      missing,
      `Module name(s) [${missing.join(', ')}] exist in apps/api/config/verticals.php but are missing from BACKEND_MODULES in apps/web/src/lib/modules.ts — add them there so sidebar gating recognizes them`,
    ).toEqual([])
  })

  it('every BACKEND_MODULES entry appears in the backend config (soft contract)', () => {
    const orphaned = BACKEND_MODULES.filter(
      (name) =>
        !names.has(name) && !FRONTEND_ONLY_ALLOWLIST.includes(name),
    )

    expect(
      orphaned,
      `BACKEND_MODULES entr(y/ies) [${orphaned.join(', ')}] in apps/web/src/lib/modules.ts do not appear in any default_modules/compatible_extras array of apps/api/config/verticals.php — remove them or add them to FRONTEND_ONLY_ALLOWLIST`,
    ).toEqual([])
  })
})
