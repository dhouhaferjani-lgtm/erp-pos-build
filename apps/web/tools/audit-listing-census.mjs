#!/usr/bin/env node
// @ts-check
/**
 * Component-graph-aware listing census.
 *
 * Discovery rule: every production TSX file below `src/features/` whose
 * basename ends in ListPage, ListView, QueuePage, or IndexPage. The inventory
 * is derived from the filesystem; no expected total or page allowlist is
 * embedded here. Classification follows rendered, feature-local imports one or
 * more levels so thin pages inherit the mechanisms supplied by organisms.
 *
 * This is analysis tooling, not a ratchet. It prints evidence and always exits
 * zero, including when the scan itself reports an error.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'

const MODULE_PATH = fileURLToPath(import.meta.url)
const TOOLS_ROOT = path.dirname(MODULE_PATH)
const WEB_ROOT = path.resolve(TOOLS_ROOT, '..')
const SRC_ROOT = path.join(WEB_ROOT, 'src')
const ROUTES_FILE = 'src/routes/index.tsx'

const LISTING_BASENAME_RE = /(?:ListPage|ListView|QueuePage|IndexPage)\.tsx$/
const SOURCE_EXTENSIONS = ['.tsx', '.ts', '.jsx', '.js']
const FILTER_COMPONENTS = new Set([
  'ActiveFilters',
  'BooleanFilter',
  'DateRangeFilter',
  'EnumFilter',
  'FilterPanel',
  'FilterTabs',
  'Input',
  'RangeFilter',
  'SearchFilter',
  'SearchInput',
  'Select',
  'input',
  'select',
])
const ROUTE_WRAPPERS = new Set([
  'ErrorBoundary',
  'ModuleGuard',
  'RequireAdminAuth',
  'RequireAuth',
  'RequirePermission',
  'Suspense',
  'SuspenseWrapper',
])
const EXTERNAL_ENTRY_PATHS = new Set([
  '/admin/login',
  '/forgot-password',
  '/login',
  '/privacy',
  '/register',
  '/reset-password',
  '/terms',
  '/verify-email',
])

/** @param {string} value */
function normalizeFile(value) {
  return value.replaceAll('\\', '/').replace(/^\.\//, '')
}

/** @param {string} value */
function escapeTable(value) {
  return value.replaceAll('|', '\\|').replaceAll('\n', ' ')
}

/**
 * @param {string[]} files
 * @returns {string[]}
 */
export function discoverListingPages(files) {
  return files
    .map(normalizeFile)
    .filter((file) => (
      file.startsWith('src/features/') &&
      LISTING_BASENAME_RE.test(path.posix.basename(file)) &&
      !/\.(?:test|spec)\.tsx$/.test(file) &&
      !file.includes('/__tests__/')
    ))
    .sort()
}

/**
 * @param {string} code
 * @param {string} filename
 */
function parseSource(code, filename) {
  const kind = filename.endsWith('.tsx') || filename.endsWith('.jsx')
    ? ts.ScriptKind.TSX
    : ts.ScriptKind.TS
  return ts.createSourceFile(filename, code, ts.ScriptTarget.Latest, true, kind)
}

/** @param {ts.JsxTagNameExpression} name */
function jsxName(name) {
  if (ts.isIdentifier(name)) return name.text
  if (ts.isPropertyAccessExpression(name)) return name.name.text
  return name.getText()
}

/**
 * @param {ts.SourceFile} source
 * @returns {Set<string>}
 */
function collectJsxNames(source) {
  const names = new Set()
  /** @param {ts.Node} node */
  function visit(node) {
    if (ts.isJsxOpeningElement(node) || ts.isJsxSelfClosingElement(node)) {
      names.add(jsxName(node.tagName))
    }
    ts.forEachChild(node, visit)
  }
  visit(source)
  return names
}

/**
 * @param {ts.SourceFile} source
 * @param {string} component
 * @param {string} attribute
 */
function jsxComponentHasAttribute(source, component, attribute) {
  let found = false
  /** @param {ts.Node} node */
  function visit(node) {
    if (found) return
    if (ts.isJsxOpeningElement(node) || ts.isJsxSelfClosingElement(node)) {
      if (jsxName(node.tagName) === component) {
        found = node.attributes.properties.some((property) => (
          ts.isJsxAttribute(property) && property.name.getText(source) === attribute
        ))
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(source)
  return found
}

/**
 * @param {string} importer
 * @param {string} specifier
 * @param {Map<string, string>} files
 * @returns {string | null}
 */
export function resolveLocalImport(importer, specifier, files) {
  let unresolved
  if (specifier.startsWith('@/')) {
    unresolved = `src/${specifier.slice(2)}`
  } else if (specifier.startsWith('.')) {
    unresolved = path.posix.join(path.posix.dirname(importer), specifier)
  } else {
    return null
  }

  const base = normalizeFile(path.posix.normalize(unresolved))
  const candidates = path.posix.extname(base)
    ? [base]
    : [
        ...SOURCE_EXTENSIONS.map((extension) => `${base}${extension}`),
        ...SOURCE_EXTENSIONS.map((extension) => `${base}/index${extension}`),
      ]

  return candidates.find((candidate) => files.has(candidate)) ?? null
}

/**
 * Follow only imported components that are actually rendered by this file.
 * Shared primitives are classified at their call site; traversing into their
 * implementation would make every DataTable inherit its optional empty UI.
 *
 * @param {string} filename
 * @param {string} code
 * @param {Map<string, string>} files
 * @returns {string[]}
 */
function renderedFeatureImports(filename, code, files) {
  const source = parseSource(code, filename)
  const renderedNames = collectJsxNames(source)
  const resolved = new Set()

  for (const statement of source.statements) {
    if (!ts.isImportDeclaration(statement) || !ts.isStringLiteral(statement.moduleSpecifier)) continue
    const clause = statement.importClause
    if (!clause) continue
    const localNames = []
    if (clause.name) localNames.push(clause.name.text)
    if (clause.namedBindings) {
      if (ts.isNamedImports(clause.namedBindings)) {
        for (const element of clause.namedBindings.elements) localNames.push(element.name.text)
      } else {
        localNames.push(clause.namedBindings.name.text)
      }
    }
    const renderedPresentationNames = localNames.filter((name) => (
      renderedNames.has(name) && /(?:Filters|Grid|List|Queue|Results|Table|View)$/.test(name)
    ))
    if (renderedPresentationNames.length === 0) continue
    const target = resolveLocalImport(filename, statement.moduleSpecifier.text, files)
    if (target?.startsWith('src/features/')) resolved.add(target)
  }

  return [...resolved].sort()
}

/**
 * @typedef {{mechanism: string, sourceFile: string} | null} MechanismResult
 * @typedef {{mechanism: string, primitives: string[], sourceFile: string} | null} FilterResult
 */

/**
 * @param {string} filename
 * @param {string} code
 */
function scanListingSource(filename, code) {
  const source = parseSource(code, filename)
  const names = collectJsxNames(source)

  /** @type {MechanismResult} */
  let pagination = null
  if (names.has('OffsetPagination')) {
    pagination = { mechanism: 'OffsetPagination', sourceFile: filename }
  } else if (
    (/(?:last_page\s*>\s*1|links\??\.(?:next|prev)|cursor-based pagination|goToPage\s*\()/i.test(code)) &&
    [...names].some((name) => /Button$/.test(name) || name === 'button')
  ) {
    const isCursor = /(?:links\??\.(?:next|prev)|cursor-based pagination|['"]cursor['"])/i.test(code)
    pagination = { mechanism: isCursor ? 'cursor controls' : 'bespoke controls', sourceFile: filename }
  }

  /** @type {MechanismResult} */
  let emptyState = null
  if (names.has('EmptyState')) {
    emptyState = { mechanism: 'EmptyState', sourceFile: filename }
  } else if (names.has('DataTable') && jsxComponentHasAttribute(source, 'DataTable', 'emptyTitle')) {
    emptyState = { mechanism: 'DataTable emptyTitle', sourceFile: filename }
  } else if (hasBespokeEmptyState(source)) {
    emptyState = { mechanism: 'bespoke', sourceFile: filename }
  }

  const primitives = [...names].filter((name) => FILTER_COMPONENTS.has(name)).sort()
  /** @type {FilterResult} */
  let filterPattern = null
  if (names.has('FilterPanel')) {
    filterPattern = { mechanism: 'FilterPanel', primitives, sourceFile: filename }
  } else if (names.has('SearchInput') || names.has('FilterTabs')) {
    filterPattern = { mechanism: 'SearchInput/FilterTabs', primitives, sourceFile: filename }
  } else if (primitives.some((name) => ['ActiveFilters', 'BooleanFilter', 'DateRangeFilter', 'EnumFilter', 'RangeFilter', 'SearchFilter'].includes(name))) {
    filterPattern = { mechanism: 'filter primitives', primitives, sourceFile: filename }
  } else if (names.has('Input') || names.has('Select')) {
    filterPattern = { mechanism: 'inline atoms', primitives, sourceFile: filename }
  } else if (names.has('input') || names.has('select')) {
    filterPattern = { mechanism: 'raw DOM', primitives, sourceFile: filename }
  } else if (/(?:source-filter-|filter\.value|activeTab|filterTabs|tab nav)/i.test(code) && (names.has('Button') || names.has('button'))) {
    filterPattern = { mechanism: 'bespoke chips/tabs', primitives: [], sourceFile: filename }
  }

  return {
    pagination,
    emptyState,
    filterPattern,
    listPageLayout: names.has('ListPageLayout') ? { present: true, sourceFile: filename } : null,
    dataTable: names.has('DataTable') ? { present: true, sourceFile: filename } : null,
  }
}

/** @param {ts.Node} node */
function containsJsx(node) {
  let found = false
  /** @param {ts.Node} child */
  function visit(child) {
    if (found) return
    if (ts.isJsxElement(child) || ts.isJsxSelfClosingElement(child) || ts.isJsxFragment(child)) {
      found = true
      return
    }
    ts.forEachChild(child, visit)
  }
  visit(node)
  return found
}

/** @param {ts.SourceFile} source */
function hasBespokeEmptyState(source) {
  let found = false
  /** @param {string} condition */
  function emptyBranch(condition) {
    if (/(?:!\s*[\w?.]+\.length|length[\s\S]*(?:===?\s*0|<=\s*0|<\s*1))/.test(condition)) return 'true'
    if (/length[\s\S]*(?:!==?\s*0|>\s*0|>=\s*1)/.test(condition)) return 'false'
    return null
  }
  /** @param {ts.Node} node */
  function visit(node) {
    if (found) return
    if (ts.isIfStatement(node)) {
      const condition = node.expression.getText(source)
      if (emptyBranch(condition) === 'true' && containsJsx(node.thenStatement)) {
        found = true
        return
      }
    }
    if (ts.isConditionalExpression(node)) {
      const condition = node.condition.getText(source)
      const branch = emptyBranch(condition)
      if ((branch === 'true' && containsJsx(node.whenTrue)) || (branch === 'false' && containsJsx(node.whenFalse))) {
        found = true
        return
      }
    }
    if (ts.isBinaryExpression(node) && node.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken) {
      const condition = node.left.getText(source)
      if (emptyBranch(condition) === 'true' && containsJsx(node.right)) {
        found = true
        return
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(source)
  return found
}

const FILTER_PRIORITY = new Map([
  ['FilterPanel', 6],
  ['SearchInput/FilterTabs', 5],
  ['filter primitives', 4],
  ['inline atoms', 3],
  ['raw DOM', 2],
  ['bespoke chips/tabs', 1],
])

/**
 * @param {string} entryFile
 * @param {Map<string, string>} inputFiles
 */
export function classifyListingPage(entryFile, inputFiles) {
  const files = new Map([...inputFiles].map(([file, code]) => [normalizeFile(file), code]))
  const normalizedEntry = normalizeFile(entryFile)
  const queue = [normalizedEntry]
  const visited = new Set()
  const scans = []

  while (queue.length > 0) {
    const filename = queue.shift()
    if (!filename || visited.has(filename)) continue
    const code = files.get(filename)
    if (code === undefined) continue
    visited.add(filename)
    scans.push(scanListingSource(filename, code))
    for (const imported of renderedFeatureImports(filename, code, files)) {
      if (!visited.has(imported)) queue.push(imported)
    }
  }

  const pagination = scans.find((scan) => scan.pagination?.mechanism === 'OffsetPagination')?.pagination
    ?? scans.find((scan) => scan.pagination)?.pagination
    ?? null
  const emptyState = scans.find((scan) => scan.emptyState?.mechanism === 'EmptyState')?.emptyState
    ?? scans.find((scan) => scan.emptyState?.mechanism === 'DataTable emptyTitle')?.emptyState
    ?? scans.find((scan) => scan.emptyState)?.emptyState
    ?? null
  const filterCandidates = scans.map((scan) => scan.filterPattern).filter(Boolean)
  filterCandidates.sort((left, right) => (
    (FILTER_PRIORITY.get(right?.mechanism ?? '') ?? 0) -
    (FILTER_PRIORITY.get(left?.mechanism ?? '') ?? 0)
  ))
  const primaryFilter = filterCandidates[0] ?? null
  const allPrimitives = [...new Set(filterCandidates.flatMap((filter) => filter?.primitives ?? []))].sort()
  const filterPattern = primaryFilter
    ? { ...primaryFilter, primitives: allPrimitives }
    : null

  return {
    entryFile: normalizedEntry,
    graphFiles: [...visited],
    pagination,
    emptyState,
    filterPattern,
    listPageLayout: scans.find((scan) => scan.listPageLayout)?.listPageLayout ?? null,
    dataTable: scans.find((scan) => scan.dataTable)?.dataTable ?? null,
  }
}

/**
 * @param {ts.JsxAttributes} attributes
 * @param {string} name
 * @param {ts.SourceFile} source
 * @returns {string | null}
 */
function jsxStringAttribute(attributes, name, source) {
  const property = attributes.properties.find((candidate) => (
    ts.isJsxAttribute(candidate) && candidate.name.getText(source) === name
  ))
  if (!property || !ts.isJsxAttribute(property) || !property.initializer) return null
  if (ts.isStringLiteral(property.initializer)) return property.initializer.text
  if (ts.isJsxExpression(property.initializer) && property.initializer.expression) {
    const expression = property.initializer.expression
    if (ts.isStringLiteral(expression) || ts.isNoSubstitutionTemplateLiteral(expression)) return expression.text
  }
  return null
}

/**
 * @param {ts.JsxAttributes} attributes
 * @param {string} name
 * @param {ts.SourceFile} source
 */
function jsxHasAttribute(attributes, name, source) {
  return attributes.properties.some((candidate) => (
    ts.isJsxAttribute(candidate) && candidate.name.getText(source) === name
  ))
}

/** @param {string} parent @param {string} child */
function joinRoutePath(parent, child) {
  if (child.startsWith('/')) return normalizeRoutePath(child)
  const base = parent === '/' ? '' : parent
  return normalizeRoutePath(`${base}/${child}`)
}

/** @param {string} value */
function normalizeRoutePath(value) {
  const withoutQuery = value.split(/[?#]/, 1)[0]
  if (withoutQuery === '/') return '/'
  return `/${withoutQuery.split('/').filter(Boolean).join('/')}`
}

/**
 * @param {string} routeCode
 * @returns {Array<{path: string, component: string, index: boolean}>}
 */
export function extractRoutes(routeCode) {
  const source = parseSource(routeCode, ROUTES_FILE)
  const routes = []

  /**
   * @param {ts.Node} node
   * @param {string} parentPath
   */
  function visit(node, parentPath) {
    if (ts.isJsxElement(node) || ts.isJsxSelfClosingElement(node)) {
      const opening = ts.isJsxElement(node) ? node.openingElement : node
      if (jsxName(opening.tagName) === 'Route') {
        const ownPath = jsxStringAttribute(opening.attributes, 'path', source)
        const isIndex = jsxHasAttribute(opening.attributes, 'index', source)
        const routePath = ownPath === null ? parentPath : joinRoutePath(parentPath, ownPath)
        const elementProperty = opening.attributes.properties.find((candidate) => (
          ts.isJsxAttribute(candidate) && candidate.name.getText(source) === 'element'
        ))
        const elementText = elementProperty?.getText(source) ?? ''
        const elementComponents = [...elementText.matchAll(/<([A-Z][\w.]*)\b/g)].map((match) => match[1])
        const component = elementComponents.findLast((name) => !ROUTE_WRAPPERS.has(name))
          ?? elementComponents.at(-1)
          ?? (isIndex ? 'index' : 'unknown')
        if ((ownPath !== null || isIndex) && elementProperty && !routePath.includes('*')) {
          routes.push({ path: routePath, component, index: isIndex })
        }
        if (ts.isJsxElement(node)) {
          for (const child of node.children) visit(child, routePath)
        }
        return
      }
    }
    ts.forEachChild(node, (child) => visit(child, parentPath))
  }

  visit(source, '/')
  return routes
    .filter((route, index, all) => all.findIndex((candidate) => (
      candidate.path === route.path && candidate.component === route.component
    )) === index)
    .sort((left, right) => left.path.localeCompare(right.path) || left.component.localeCompare(right.component))
}

/**
 * @param {ts.TemplateExpression} expression
 * @returns {string}
 */
function templatePath(expression) {
  let value = expression.head.text
  for (const span of expression.templateSpans) value += `:param${span.literal.text}`
  return value
}

/**
 * @param {string} filename
 * @param {string} code
 * @returns {Array<{sourceFile: string, target: string}>}
 */
export function extractRouteReferences(filename, code) {
  const source = parseSource(code, filename)
  const targets = new Set()
  const bindings = new Map()

  /** @param {ts.Node} node */
  function collectBindings(node) {
    if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) && node.initializer) {
      bindings.set(node.name.text, node.initializer)
    }
    ts.forEachChild(node, collectBindings)
  }
  collectBindings(source)

  /** @param {ts.Expression | ts.JsxAttributeValue | undefined} expression */
  function addExpression(expression) {
    if (!expression) return
    let candidate = null
    if (ts.isStringLiteral(expression) || ts.isNoSubstitutionTemplateLiteral(expression)) candidate = expression.text
    if (ts.isTemplateExpression(expression)) candidate = templatePath(expression)
    if (ts.isJsxExpression(expression) && expression.expression) addExpression(expression.expression)
    if (ts.isIdentifier(expression)) addExpression(bindings.get(expression.text))
    if (candidate?.startsWith('/') && !candidate.startsWith('//') && !/\s/.test(candidate)) {
      targets.add(normalizeRoutePath(candidate))
    }
  }

  /** @param {ts.Node} node */
  function visit(node) {
    if (ts.isJsxAttribute(node) && ['href', 'to'].includes(node.name.getText(source))) {
      addExpression(node.initializer)
    } else if (ts.isPropertyAssignment(node)) {
      const name = node.name.getText(source).replaceAll(/["']/g, '')
      if (['href', 'to'].includes(name)) addExpression(node.initializer)
    } else if (ts.isCallExpression(node) && /(?:^|\.)navigate$/.test(node.expression.getText(source))) {
      addExpression(node.arguments[0])
    } else if (filename.includes('/Breadcrumb') || filename.endsWith('/entityRoutes.ts')) {
      if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node) || ts.isTemplateExpression(node)) {
        addExpression(node)
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(source)
  return [...targets].sort().map((target) => ({ sourceFile: filename, target }))
}

/** @param {string} routePath @param {string} target */
function routeMatchesReference(routePath, target) {
  const routeSegments = normalizeRoutePath(routePath).split('/').filter(Boolean)
  const targetSegments = normalizeRoutePath(target).split('/').filter(Boolean)
  if (routeSegments.length !== targetSegments.length) return false
  return routeSegments.every((segment, index) => (
    segment.startsWith(':') || segment === targetSegments[index]
  ))
}

/**
 * @param {string} routeCode
 * @param {Map<string, string>} inputSources
 */
export function analyzeRouteReachability(routeCode, inputSources) {
  const references = [...inputSources]
    .filter(([filename]) => normalizeFile(filename) !== ROUTES_FILE)
    .flatMap(([filename, code]) => extractRouteReferences(normalizeFile(filename), code))

  return extractRoutes(routeCode).map((route) => {
    const inboundReferences = references
      .filter((reference) => routeMatchesReference(route.path, reference.target))
      .sort((left, right) => left.sourceFile.localeCompare(right.sourceFile) || left.target.localeCompare(right.target))
    const excludedFromOrphanSet = route.component === 'Navigate' || route.component.endsWith('Layout') || EXTERNAL_ENTRY_PATHS.has(route.path)
    const role = excludedFromOrphanSet
      ? 'entry/redirect/shell'
      : /\/(?:create|edit|new)$/.test(route.path) || /(?:Create|Form)/.test(route.component)
        ? 'action/form'
        : route.path.includes('/:')
          ? 'parameterized view'
          : 'view'
    return {
      ...route,
      role,
      excludedFromOrphanSet,
      orphaned: !excludedFromOrphanSet && inboundReferences.length === 0,
      inboundReferences,
    }
  })
}

/** @param {string} root */
async function readSourceTree(root) {
  const out = new Map()
  /** @param {string} directory */
  async function visit(directory) {
    const entries = await fs.readdir(directory, { withFileTypes: true })
    for (const entry of entries) {
      const absolute = path.join(directory, entry.name)
      if (entry.isDirectory()) {
        if (entry.name !== '__tests__') await visit(absolute)
        continue
      }
      if (!/\.[jt]sx?$/.test(entry.name) || /\.(?:test|spec)\.[jt]sx?$/.test(entry.name)) continue
      const relative = normalizeFile(path.relative(WEB_ROOT, absolute))
      out.set(relative, await fs.readFile(absolute, 'utf8'))
    }
  }
  await visit(root)
  return out
}

/**
 * @param {{listings: ReturnType<typeof classifyListingPage>[], routes: ReturnType<typeof analyzeRouteReachability>}} census
 */
export function renderCensusReport(census) {
  /** @param {Array<Record<string, unknown>>} rows @param {(row: Record<string, unknown>) => string} key */
  function distribution(rows, key) {
    const counts = new Map()
    for (const row of rows) {
      const value = key(row)
      counts.set(value, (counts.get(value) ?? 0) + 1)
    }
    return [...counts].sort(([left], [right]) => left.localeCompare(right)).map(([name, count]) => `${name}: ${count}`).join(' · ')
  }
  const lines = [
    '# Component-graph-aware listing census',
    '',
    `Listings discovered: ${census.listings.length}`,
    '',
    `- Pagination: ${distribution(census.listings, (listing) => listing.pagination?.mechanism ?? 'none')}`,
    `- Empty state: ${distribution(census.listings, (listing) => listing.emptyState?.mechanism ?? 'none')}`,
    `- Filter pattern: ${distribution(census.listings, (listing) => listing.filterPattern?.mechanism ?? 'none')}`,
    `- ListPageLayout: ${census.listings.filter((listing) => listing.listPageLayout).length}; DataTable: ${census.listings.filter((listing) => listing.dataTable).length}`,
    '',
    '| Page | Graph files | Pagination | Pagination source | Empty state | Empty source | Filter pattern | Filter primitives | Filter source | ListPageLayout | DataTable |',
    '|---|---:|---|---|---|---|---|---|---|---|---|',
  ]
  for (const listing of census.listings) {
    lines.push(`| ${escapeTable(listing.entryFile)} | ${listing.graphFiles.length} | ${listing.pagination?.mechanism ?? 'none'} | ${listing.pagination?.sourceFile ?? '—'} | ${listing.emptyState?.mechanism ?? 'none'} | ${listing.emptyState?.sourceFile ?? '—'} | ${listing.filterPattern?.mechanism ?? 'none'} | ${listing.filterPattern?.primitives.join(', ') || '—'} | ${listing.filterPattern?.sourceFile ?? '—'} | ${listing.listPageLayout?.sourceFile ?? 'no'} | ${listing.dataTable?.sourceFile ?? 'no'} |`)
  }
  const orphaned = census.routes.filter((route) => route.orphaned)
  lines.push(
    '',
    `Registered route records: ${census.routes.length}; orphan candidates after redirect/external-entry exclusions: ${orphaned.length}`,
    '',
    '| Route | Component | Role | Inbound references | Reference sources | Status |',
    '|---|---|---|---:|---|---|',
  )
  for (const route of census.routes) {
    const status = route.excludedFromOrphanSet ? 'excluded entry/redirect' : route.orphaned ? 'orphan candidate' : 'reachable'
    const sources = [...new Set(route.inboundReferences.map((reference) => reference.sourceFile))].join(', ') || '—'
    lines.push(`| ${escapeTable(route.path)} | ${escapeTable(route.component)} | ${route.role} | ${route.inboundReferences.length} | ${escapeTable(sources)} | ${status} |`)
  }
  return `${lines.join('\n')}\n`
}

export async function runCensus() {
  const files = await readSourceTree(SRC_ROOT)
  const listings = discoverListingPages([...files.keys()]).map((entryFile) => classifyListingPage(entryFile, files))
  const routeCode = files.get(ROUTES_FILE)
  if (!routeCode) throw new Error(`Missing route source: ${ROUTES_FILE}`)
  const routes = analyzeRouteReachability(routeCode, files)
  return { listings, routes }
}

export async function main() {
  try {
    console.log(renderCensusReport(await runCensus()))
  } catch (error) {
    const message = error instanceof Error ? error.stack ?? error.message : String(error)
    console.error(`[audit-listing-census] scan failed (analysis tool remains non-gating):\n${message}`)
  }
  process.exitCode = 0
}

if (process.argv[1] && path.resolve(process.argv[1]) === MODULE_PATH) {
  await main()
}
