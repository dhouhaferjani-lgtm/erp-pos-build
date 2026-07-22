// @ts-check
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { relative, resolve } from 'node:path'

import ts from 'typescript'
import { describe, expect, it } from 'vitest'

const paginationTypePath = resolve(process.cwd(), 'src/types/pagination.ts')
const sourceRoot = resolve(process.cwd(), 'src')
const coreOffsetFields = ['current_page', 'last_page', 'per_page', 'total']

const handoffFeatureSources = [
  'features/admin/api/index.ts',
  'features/catalog/types/compositeItem.ts',
  'features/channels/types.ts',
  'features/compliance/api/complianceApi.ts',
  'features/compliance/api/fraudApi.ts',
  'features/crm/api/contactApi.ts',
  'features/expenses/types/index.ts',
  'features/income/types/index.ts',
  'features/loyalty/types/loyalty.ts',
  'features/replenishment/types/index.ts',
  'features/scheduling/types.ts',
  'features/stock-transfers/types/index.ts',
]

/** @param {string[]} relativePaths */
function findDuplicateOffsetMetaDeclarations(relativePaths) {
  /** @type {string[]} */
  const duplicates = []

  for (const relativePath of relativePaths) {
    const filePath = resolve(sourceRoot, relativePath)
    const source = readFileSync(filePath, 'utf8')
    const sourceFile = ts.createSourceFile(
      filePath,
      source,
      ts.ScriptTarget.Latest,
      true,
      filePath.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
    )

    /** @param {import('typescript').Node} node */
    const visit = (node) => {
      if (ts.isInterfaceDeclaration(node) || ts.isTypeLiteralNode(node)) {
        const propertyNames = node.members
          .filter((member) => ts.isPropertySignature(member))
          .map((member) => member.name.getText(sourceFile).replace(/^['"]|['"]$/g, ''))

        if (coreOffsetFields.every((field) => propertyNames.includes(field))) {
          const { line } = sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile))
          duplicates.push(`${relativePath}:${String(line + 1)}`)
        }
      }

      ts.forEachChild(node, visit)
    }

    visit(sourceFile)
  }

  return duplicates
}

function findProductionTypeScriptSources() {
  /** @type {string[]} */
  const sources = []

  /** @param {string} directory */
  const visitDirectory = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const entryPath = resolve(directory, entry.name)
      if (entry.isDirectory()) {
        if (entry.name !== '__tests__') visitDirectory(entryPath)
        continue
      }
      if (!entry.name.match(/\.tsx?$/) || entry.name.includes('.test.')) continue

      const relativePath = relative(sourceRoot, entryPath).replaceAll('\\', '/')
      if (relativePath !== 'types/pagination.ts') sources.push(relativePath)
    }
  }

  visitDirectory(sourceRoot)
  return sources
}

describe('OffsetPaginationMeta', () => {
  it('defines the canonical Laravel offset-pagination metadata contract', () => {
    expect(existsSync(paginationTypePath)).toBe(true)

    const source = readFileSync(paginationTypePath, 'utf8')
    const sourceFile = ts.createSourceFile(
      paginationTypePath,
      source,
      ts.ScriptTarget.Latest,
      true,
      ts.ScriptKind.TS,
    )
    const declaration = sourceFile.statements.find(
      (statement) => ts.isInterfaceDeclaration(statement)
        && statement.name.text === 'OffsetPaginationMeta',
    )

    expect(declaration).toBeDefined()
    if (!declaration || !ts.isInterfaceDeclaration(declaration)) return

    const properties = Object.fromEntries(
      declaration.members
        .filter((member) => ts.isPropertySignature(member))
        .map((member) => [member.name.getText(sourceFile), member.type?.getText(sourceFile)]),
    )

    expect(properties).toEqual({
      current_page: 'number',
      last_page: 'number',
      per_page: 'number',
      total: 'number',
      from: 'number | null',
      to: 'number | null',
    })
  })

  it('is reused by the features named in the Wave B handoff', () => {
    expect(findDuplicateOffsetMetaDeclarations(handoffFeatureSources)).toEqual([])
  })

  it('is reused by every production offset-pagination response type', () => {
    expect(findDuplicateOffsetMetaDeclarations(findProductionTypeScriptSources())).toEqual([])
  })
})
