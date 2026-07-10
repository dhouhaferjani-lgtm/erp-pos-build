#!/usr/bin/env node
// @ts-check
/**
 * Design-system ratchet gate.
 *
 * Scans production React feature/page files for the structural violations
 * catalogued in docs/superpowers/audits/2026-07-10-design-system-violation-inventory.md.
 * The checked categories intentionally mirror that manifest's verification
 * commands: C1 bespoke page h1s, C2/C3 raw tokenized form controls/buttons,
 * C4 create/edit/form files without react-hook-form, C5 raw tables, and C6
 * local status maps. Current legacy hits live in a baseline file; new hits
 * fail the gate, and stale baseline entries fail so cleaned directories stay
 * cleaned.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const WEB_ROOT = path.resolve(__dirname, '..')
const SRC_ROOT = path.join(WEB_ROOT, 'src')
const BASELINE_PATH = path.join(__dirname, 'audit-design-system-baseline.json')

const TAG_RE = {
  h1: /<h1\b(?:=>|[^>])*>/gis,
  input: /<input\b(?:=>|[^>])*\/?>/gis,
  select: /<select\b(?:=>|[^>])*>/gis,
  textarea: /<textarea\b(?:=>|[^>])*>/gis,
  button: /<button\b(?:=>|[^>])*>/gis,
  table: /<table\b(?:=>|[^>])*>/gis,
}

const STATUS_RE = [
  /(status|state)\w*(Colors?|Classes?|Map|Styles?)\s*:\s*Record</gi,
  /switch\s*\(\s*\w*[Ss]tatus\w*\s*\)/g,
  /const\s+\w*(status|state)\w*(Colors?|Classes?|Styles?|Config|Badge\w*)\s*[:=]/gi,
]

/**
 * @typedef {{
 *   category: 'C1' | 'C2' | 'C3' | 'C4' | 'C5' | 'C6',
 *   file: string,
 *   line: number,
 *   column: number,
 *   reason: string,
 *   statement_fingerprint: string,
 * }} DesignSystemViolation
 */

/**
 * @param {string} text
 * @returns {string}
 */
function normalize(text) {
  return text.replace(/\s+/g, ' ').trim()
}

/**
 * @param {string} code
 * @param {number} index
 * @returns {{line: number, column: number}}
 */
function lineColumnAt(code, index) {
  const prefix = code.slice(0, index)
  const lines = prefix.split('\n')
  return { line: lines.length, column: lines[lines.length - 1].length + 1 }
}

/**
 * @param {string} file
 * @returns {boolean}
 */
function isProductionTsx(file) {
  return (
    file.endsWith('.tsx') &&
    !/\.(test|spec)\.tsx$/.test(file) &&
    !/\.stories\.tsx$/.test(file) &&
    !file.includes('/__tests__/')
  )
}

/**
 * @param {string} file
 * @returns {boolean}
 */
function isFeatureFile(file) {
  return file.includes('/src/features/') || file.startsWith('src/features/')
}

/**
 * @param {string} file
 * @returns {boolean}
 */
function isFeatureOrPageFile(file) {
  return isFeatureFile(file) || file.includes('/src/pages/') || file.startsWith('src/pages/')
}

/**
 * @param {DesignSystemViolation} violation
 * @returns {string}
 */
export function violationBaselineKey(violation) {
  return [
    violation.category,
    violation.file,
    violation.statement_fingerprint,
  ].join('|')
}

/**
 * @param {DesignSystemViolation[]} violations
 * @param {Set<string>} baseline
 * @returns {{baselined: DesignSystemViolation[], newViolations: DesignSystemViolation[], staleBaselineEntries: string[]}}
 */
export function partitionViolationsByBaseline(violations, baseline) {
  const seen = new Set()
  const baselined = []
  const newViolations = []

  for (const violation of violations) {
    const key = violationBaselineKey(violation)
    if (baseline.has(key)) {
      seen.add(key)
      baselined.push(violation)
    } else {
      newViolations.push(violation)
    }
  }

  const staleBaselineEntries = [...baseline].filter((entry) => !seen.has(entry))
  return { baselined, newViolations, staleBaselineEntries }
}

/**
 * @param {DesignSystemViolation[]} out
 * @param {DesignSystemViolation['category']} category
 * @param {string} file
 * @param {string} code
 * @param {number} index
 * @param {string} reason
 * @param {string} fingerprintSource
 */
function pushViolation(out, category, file, code, index, reason, fingerprintSource) {
  const { line, column } = lineColumnAt(code, index)
  out.push({
    category,
    file,
    line,
    column,
    reason,
    statement_fingerprint: normalize(fingerprintSource),
  })
}

/**
 * @param {RegExp} regex
 * @param {string} code
 * @returns {Array<{text: string, index: number}>}
 */
function matches(regex, code) {
  regex.lastIndex = 0
  const out = []
  let match
  while ((match = regex.exec(code)) !== null) {
    out.push({ text: match[0], index: match.index })
  }
  return out
}

/**
 * @param {string} code
 * @param {string} filename
 * @returns {DesignSystemViolation[]}
 */
export function scanCode(code, filename) {
  const file = path.relative(WEB_ROOT, path.resolve(WEB_ROOT, filename)).replaceAll(path.sep, '/')
  if (!isProductionTsx(file)) return []

  /** @type {DesignSystemViolation[]} */
  const violations = []

  if (isFeatureOrPageFile(file)) {
    for (const match of matches(TAG_RE.h1, code)) {
      if (/\btext-(2xl|3xl)\b/.test(match.text)) {
        pushViolation(
          violations,
          'C1',
          file,
          code,
          match.index,
          'Bespoke page h1 should use PageHeader',
          match.text,
        )
      }
    }
  }

  if (!isFeatureFile(file)) {
    return violations
  }

  for (const tag of ['input', 'select', 'textarea']) {
    for (const match of matches(TAG_RE[tag], code)) {
      if (match.text.includes('tokens.')) {
        pushViolation(
          violations,
          'C2',
          file,
          code,
          match.index,
          `Raw <${tag}> with token classes should use form atoms`,
          match.text,
        )
      }
    }
  }

  for (const match of matches(TAG_RE.button, code)) {
    if (match.text.includes('tokens.button')) {
      pushViolation(
        violations,
        'C3',
        file,
        code,
        match.index,
        'Raw <button> with tokens.button should use Button atom',
        match.text,
      )
    }
  }

  if (
    /(Create|Edit|Form)/.test(path.basename(file)) &&
    (code.includes('<form') || /\bonSubmit\b|\bhandleSubmit\b/.test(code)) &&
    !code.includes('react-hook-form')
  ) {
    pushViolation(
      violations,
      'C4',
      file,
      code,
      0,
      'Create/edit/form file handles form submission without react-hook-form',
      'missing react-hook-form',
    )
  }

  for (const match of matches(TAG_RE.table, code)) {
    pushViolation(
      violations,
      'C5',
      file,
      code,
      match.index,
      'Raw <table> should use DataTable or LineItemsTable',
      match.text,
    )
  }

  for (const regex of STATUS_RE) {
    for (const match of matches(regex, code)) {
      pushViolation(
        violations,
        'C6',
        file,
        code,
        match.index,
        'Local status map or switch should use StatusBadge/statusTone',
        match.text,
      )
    }
  }

  return violations
}

/**
 * @param {string} dir
 * @returns {Promise<string[]>}
 */
async function walk(dir) {
  /** @type {string[]} */
  const out = []
  const entries = await fs.readdir(dir, { withFileTypes: true })
  for (const entry of entries) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name === '__tests__' || entry.name === '__mocks__') {
        continue
      }
      out.push(...(await walk(full)))
    } else if (entry.isFile() && isProductionTsx(full)) {
      out.push(full)
    }
  }
  return out
}

/**
 * @returns {Promise<Set<string>>}
 */
async function readBaseline() {
  try {
    const raw = await fs.readFile(BASELINE_PATH, 'utf8')
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed.entries)) {
      throw new Error('baseline entries must be an array')
    }
    return new Set(parsed.entries)
  } catch (error) {
    if (error && typeof error === 'object' && 'code' in error && error.code === 'ENOENT') {
      return new Set()
    }
    throw error
  }
}

/**
 * @param {Set<string>} baseline
 */
async function writeBaseline(baseline) {
  const payload = `${JSON.stringify({
    generated_by: 'apps/web/tools/audit-design-system.mjs --write-baseline',
    entries: [...baseline].sort(),
  }, null, 2)}\n`
  await fs.writeFile(BASELINE_PATH, payload)
}

async function scanWorkspace() {
  const files = await walk(SRC_ROOT)
  /** @type {DesignSystemViolation[]} */
  const violations = []
  for (const file of files) {
    const code = await fs.readFile(file, 'utf8')
    violations.push(...scanCode(code, file))
  }
  return violations
}

const isMain = (() => {
  if (typeof process === 'undefined' || !Array.isArray(process.argv) || process.argv.length < 2) {
    return false
  }
  return path.resolve(process.argv[1] ?? '') === fileURLToPath(import.meta.url)
})()

if (isMain) {
  const wantsJson = process.argv.includes('--json')
  const wantsWriteBaseline = process.argv.includes('--write-baseline')
  const violations = await scanWorkspace()

  process.stderr.write(
    `[sweep-progress] Design-system audit C1-C6 violations: ${violations.length}\n`,
  )

  if (wantsJson) {
    const payload = JSON.stringify({ violations })
    const wrote = process.stdout.write(payload)
    if (!wrote) {
      await new Promise((resolve) => process.stdout.once('drain', resolve))
    }
    process.exit(0)
  }

  if (wantsWriteBaseline) {
    await writeBaseline(new Set(violations.map(violationBaselineKey)))
    process.stderr.write(`[gate-summary] Design-system baseline written: ${violations.length} entries\n`)
    process.exit(0)
  }

  const baseline = await readBaseline()
  const { baselined, newViolations, staleBaselineEntries } = partitionViolationsByBaseline(violations, baseline)
  process.stderr.write(
    `[gate-summary] Design-system baseline: ${baselined.length} acknowledged, ${newViolations.length} new, ${staleBaselineEntries.length} stale baseline entries\n`,
  )

  if (newViolations.length > 0) {
    process.stderr.write('\nNew design-system violations:\n')
    for (const violation of newViolations.slice(0, 50)) {
      process.stderr.write(
        `  ${violation.file}:${violation.line}:${violation.column} ${violation.category} ${violation.reason} (${violation.statement_fingerprint})\n`,
      )
    }
    if (newViolations.length > 50) {
      process.stderr.write(`  ...and ${newViolations.length - 50} more\n`)
    }
  }

  if (staleBaselineEntries.length > 0) {
    process.stderr.write('\nStale design-system baseline entries; shrink tools/audit-design-system-baseline.json:\n')
    for (const entry of staleBaselineEntries.slice(0, 50)) {
      process.stderr.write(`  ${entry}\n`)
    }
    if (staleBaselineEntries.length > 50) {
      process.stderr.write(`  ...and ${staleBaselineEntries.length - 50} more\n`)
    }
  }

  if (newViolations.length > 0 || staleBaselineEntries.length > 0) {
    process.exit(1)
  }
}
