// @ts-check
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import {
  analyzeRouteReachability,
  classifyListingPage,
  discoverListingPages,
} from '../audit-listing-census.mjs'

const { describe, it } = process.env['VITEST']
  ? await import('vitest')
  : await import('node:test')

const WEB_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')

/** @param {string[]} filenames */
async function readWebFiles(filenames) {
  return new Map(await Promise.all(filenames.map(async (filename) => [
    filename,
    await readFile(path.join(WEB_ROOT, filename), 'utf8'),
  ])))
}

describe('listing-page discovery', () => {
  it('discovers feature listing pages by filename instead of a fixed inventory', () => {
    const files = [
      'src/features/example/pages/ExampleListPage.tsx',
      'src/features/example/pages/ExampleQueuePage.tsx',
      'src/features/example/pages/ExampleDetailPage.tsx',
      'src/pages/OutsideFeatureListPage.tsx',
      'src/features/example/pages/ExampleListPage.test.tsx',
    ]

    assert.deepEqual(discoverListingPages(files), [
      'src/features/example/pages/ExampleListPage.tsx',
      'src/features/example/pages/ExampleQueuePage.tsx',
    ])
  })
})

describe('component-graph-aware listing classification', () => {
  it('attributes pagination rendered through nested organisms to the resolved source file', () => {
    const files = new Map([
      ['src/features/example/pages/ExampleListPage.tsx', `
        import { ExampleList } from '../components/ExampleList'
        export function ExampleListPage() { return <ExampleList /> }
      `],
      ['src/features/example/components/ExampleList.tsx', `
        import { ExampleTable } from './ExampleTable'
        export function ExampleList() { return <ExampleTable /> }
      `],
      ['src/features/example/components/ExampleTable.tsx', `
        import { OffsetPagination } from '@/components/ui/OffsetPagination'
        export function ExampleTable() { return <OffsetPagination currentPage={1} totalPages={2} onPageChange={() => {}} /> }
      `],
    ])

    const result = classifyListingPage('src/features/example/pages/ExampleListPage.tsx', files)

    assert.deepEqual(result.pagination, {
      mechanism: 'OffsetPagination',
      sourceFile: 'src/features/example/components/ExampleTable.tsx',
    })
  })

  it('classifies an empty-state molecule rendered by an organism', () => {
    const files = new Map([
      ['src/features/example/pages/ExampleListPage.tsx', `
        import { ExampleList } from '../components/ExampleList'
        export function ExampleListPage() { return <ExampleList /> }
      `],
      ['src/features/example/components/ExampleList.tsx', `
        import { EmptyState } from '@/components/molecules/EmptyState'
        export function ExampleList() { return rows.length === 0 ? <EmptyState title="No rows" /> : null }
      `],
    ])

    const result = classifyListingPage('src/features/example/pages/ExampleListPage.tsx', files)

    assert.deepEqual(result.emptyState, {
      mechanism: 'EmptyState',
      sourceFile: 'src/features/example/components/ExampleList.tsx',
    })
  })

  it('does not confuse a later zero comparison with the list-length branch', () => {
    const files = new Map([
      ['src/features/example/pages/ExampleListPage.tsx', `
        export function ExampleListPage({ items, mode }) {
          return items.length > 0 && mode === 0 ? <div>Rows</div> : null
        }
      `],
    ])

    const result = classifyListingPage('src/features/example/pages/ExampleListPage.tsx', files)

    assert.equal(result.emptyState, null)
  })

  it('classifies filter primitives rendered by an organism', () => {
    const files = new Map([
      ['src/features/example/pages/ExampleListPage.tsx', `
        import { ExampleFilters } from '../components/ExampleFilters'
        export function ExampleListPage() { return <ExampleFilters /> }
      `],
      ['src/features/example/components/ExampleFilters.tsx', `
        import { SearchInput } from '@/components/molecules/SearchInput'
        import { FilterTabs } from '@/components/molecules/FilterTabs'
        export function ExampleFilters() { return <><SearchInput value="" onChange={() => {}} /><FilterTabs tabs={[]} value="" onChange={() => {}} /></> }
      `],
    ])

    const result = classifyListingPage('src/features/example/pages/ExampleListPage.tsx', files)

    assert.deepEqual(result.filterPattern, {
      mechanism: 'SearchInput/FilterTabs',
      primitives: ['FilterTabs', 'SearchInput'],
      sourceFile: 'src/features/example/components/ExampleFilters.tsx',
    })
  })

  it('classifies the ExpenseListPage empty state through ExpenseList', async () => {
    const files = await readWebFiles([
      'src/features/expenses/pages/ExpenseListPage.tsx',
      'src/features/expenses/components/organisms/ExpenseList.tsx',
    ])

    const result = classifyListingPage('src/features/expenses/pages/ExpenseListPage.tsx', files)

    assert.deepEqual(result.emptyState, {
      mechanism: 'bespoke',
      sourceFile: 'src/features/expenses/components/organisms/ExpenseList.tsx',
    })
  })

  it('classifies the BundleListPage empty state through BundleList', async () => {
    const files = await readWebFiles([
      'src/features/workshop-bundles/pages/BundleListPage.tsx',
      'src/features/workshop-bundles/components/organisms/BundleList.tsx',
    ])

    const result = classifyListingPage('src/features/workshop-bundles/pages/BundleListPage.tsx', files)

    assert.deepEqual(result.emptyState, {
      mechanism: 'bespoke',
      sourceFile: 'src/features/workshop-bundles/components/organisms/BundleList.tsx',
    })
  })
})

describe('route reachability', () => {
  it('matches a parameterized route to a single dynamic Link reference', () => {
    const routes = `
      import { Routes, Route } from 'react-router-dom'
      export function AppRoutes() {
        return <Routes><Route path="/x"><Route path=":id" element={<DetailPage />} /></Route></Routes>
      }
    `
    const sources = new Map([
      ['src/features/example/ExampleListPage.tsx', `
        import { Link } from 'react-router-dom'
        export function ExampleListPage({ id }) { return <Link to={\`/x/\${id}\`}>Open</Link> }
      `],
    ])

    const result = analyzeRouteReachability(routes, sources)
    const detail = result.find((route) => route.path === '/x/:id')

    assert.equal(detail?.orphaned, false)
    assert.deepEqual(detail?.inboundReferences, [
      { sourceFile: 'src/features/example/ExampleListPage.tsx', target: '/x/:param' },
    ])
  })

  it('marks a parameterized route with no inbound references as orphaned', () => {
    const routes = `
      import { Routes, Route } from 'react-router-dom'
      export function AppRoutes() {
        return <Routes><Route path="/x"><Route path=":id" element={<DetailPage />} /></Route></Routes>
      }
    `

    const result = analyzeRouteReachability(routes, new Map())
    const detail = result.find((route) => route.path === '/x/:id')

    assert.equal(detail?.orphaned, true)
    assert.deepEqual(detail?.inboundReferences, [])
  })

  it('does not let a generic dynamic template reach unrelated literal segments', () => {
    const routes = `
      import { Routes, Route } from 'react-router-dom'
      export function AppRoutes() {
        return <Routes><Route path="/scheduling/capacity" element={<CapacityPage />} /></Routes>
      }
    `
    const sources = new Map([
      ['src/features/example/ReturnNotePage.tsx', `
        import { Link } from 'react-router-dom'
        export function ReturnNotePage({ section, slug }) { return <Link to={\`/\${section}/\${slug}\`}>Open</Link> }
      `],
    ])

    const result = analyzeRouteReachability(routes, sources)

    assert.equal(result[0]?.orphaned, true)
  })

  it('resolves conditional base paths and literal props used by shared navigation components', () => {
    const routes = `
      import { Routes, Route } from 'react-router-dom'
      export function AppRoutes() {
        return <Routes>
          <Route path="/purchases/suppliers/new" element={<SupplierForm />} />
          <Route path="/sales/customers/:id/edit" element={<CustomerForm />} />
          <Route path="/sales/quotes/:id/edit" element={<DocumentForm />} />
        </Routes>
      }
    `
    const sources = new Map([
      ['src/features/partners/PartnerListPage.tsx', `
        export function PartnerListPage({ supplier }) {
          const basePath = supplier ? '/purchases/suppliers' : '/sales/customers'
          return <Link to={\`${'${basePath}'}/new\`}>New</Link>
        }
      `],
      ['src/features/partners/PartnerDetailPage.tsx', `
        export function PartnerDetailPage({ supplier, partner }) {
          const basePath = supplier ? '/purchases/suppliers' : '/sales/customers'
          return <Link to={\`${'${basePath}'}/${'${partner.id}'}/edit\`}>Edit</Link>
        }
      `],
      ['src/features/documents/quotes/QuoteDetailPage.tsx', `
        export function QuoteDetailPage() {
          return <DocumentActionBar basePath="/sales/quotes" />
        }
      `],
      ['src/features/documents/components/DocumentActionBar.tsx', `
        export function DocumentActionBar({ basePath, document }) {
          return <Link to={\`${'${basePath}'}/${'${document.id}'}/edit\`}>Edit</Link>
        }
      `],
    ])

    const result = analyzeRouteReachability(routes, sources)

    assert.deepEqual(result.map(({ path, orphaned }) => ({ path, orphaned })), [
      { path: '/purchases/suppliers/new', orphaned: false },
      { path: '/sales/customers/:id/edit', orphaned: false },
      { path: '/sales/quotes/:id/edit', orphaned: false },
    ])
  })

  it('counts a breadcrumb parent as an inbound path', () => {
    const routes = `
      import { Routes, Route } from 'react-router-dom'
      export function AppRoutes() {
        return <Routes><Route path="/inventory" element={<InventoryHubPage />} /></Routes>
      }
    `
    const sources = new Map([
      ['src/components/molecules/Breadcrumb/Breadcrumb.tsx', `
        export const breadcrumbParents = { '/inventory/products': '/inventory' }
      `],
    ])

    const result = analyzeRouteReachability(routes, sources)
    const inventory = result.find((route) => route.path === '/inventory')

    assert.equal(inventory?.orphaned, false)
    assert.deepEqual(inventory?.inboundReferences, [
      { sourceFile: 'src/components/molecules/Breadcrumb/Breadcrumb.tsx', target: '/inventory' },
    ])
  })
})
