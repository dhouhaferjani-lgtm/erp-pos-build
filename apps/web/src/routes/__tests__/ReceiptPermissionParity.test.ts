import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { MODULE_PERMISSIONS } from '@/hooks/usePermissions'

const routesSource = readFileSync(`${process.cwd()}/src/routes/index.tsx`, 'utf8')
const sidebarSource = readFileSync(`${process.cwd()}/src/components/organisms/Sidebar/Sidebar.tsx`, 'utf8')
const compliancePageSource = readFileSync(`${process.cwd()}/src/features/compliance/pages/ComplianceExportPage.tsx`, 'utf8')

describe('receipt reporting permission parity', () => {
  it('defines every exact POS child permission without fail-open aliases', () => {
    expect(MODULE_PERMISSIONS['pos.operate_terminal']).toEqual(['pos.operate_terminal'])
    expect(MODULE_PERMISSIONS['pos.manage_terminals']).toEqual(['pos.manage_terminals'])
    expect(MODULE_PERMISSIONS['pos.manage_shifts']).toEqual(['pos.manage_shifts'])
    expect(MODULE_PERMISSIONS['pos.manage_tables']).toEqual(['pos.manage_tables'])
    expect(MODULE_PERMISSIONS['pos.view_reports']).toEqual(['pos.view_reports'])
    expect(MODULE_PERMISSIONS['pos.view_receipts']).toEqual(['pos.view_receipts'])
  })

  it.each([
    ['posOrders', 'pos.operate_terminal'],
    ['tables', 'pos.manage_tables'],
    ['kitchen', 'pos.operate_terminal'],
    ['terminals', 'pos.manage_terminals'],
    ['shiftHistory', 'pos.manage_shifts'],
    ['receipts', 'pos.view_receipts'],
    ['zReports', 'pos.view_reports'],
    ['analytics', 'pos.view_reports'],
  ])('keys %s to %s', (child, permission) => {
    expect(sidebarSource).toContain(`{ key: '${child}'`)
    const start = sidebarSource.indexOf(`{ key: '${child}'`)
    expect(sidebarSource.slice(start, start + 180)).toContain(`permission: '${permission}'`)
  })

  it('keeps the POS parent and vouchers on the deliberate pos alias', () => {
    expect(sidebarSource).toContain("key: 'pointOfSale'")
    expect(sidebarSource.slice(sidebarSource.indexOf("key: 'pointOfSale'"), sidebarSource.indexOf("key: 'pointOfSale'") + 100)).toContain("permission: 'pos'")
    const vouchers = sidebarSource.indexOf("{ key: 'vouchers'")
    expect(sidebarSource.slice(vouchers, vouchers + 150)).toContain("permission: 'pos'")
  })

  it('registers the read-only receipt register behind the exact receipt permission', () => {
    const receipts = routesSource.indexOf('path="receipts"', routesSource.indexOf('<Route path="pos">'))
    const fragment = routesSource.slice(receipts, receipts + 420)

    expect(receipts).toBeGreaterThanOrEqual(0)
    expect(fragment).toContain('permission="pos.view_receipts"')
    expect(fragment).toContain('<ReceiptListPage />')
    expect(fragment).not.toMatch(/createReceipt|processReceiptPayments|ReturnItemsModal|voidReceipt/)
  })

  it('gates compliance by any raw panel permission and fraud routes exactly', () => {
    const compliance = routesSource.indexOf('path="compliance/export"')
    const complianceFragment = routesSource.slice(compliance, compliance + 650)
    expect(complianceFragment).toContain('permissions={[')
    expect(complianceFragment).toContain("'compliance.export_jet'")
    expect(complianceFragment).toContain("'compliance.verify_chains'")
    expect(complianceFragment).toContain("'compliance.view_reprint_log'")

    const settings = routesSource.indexOf('path="compliance/fraud-settings"')
    expect(routesSource.slice(settings, settings + 400)).toContain('permission="fraud-settings.view"')
    const alerts = routesSource.indexOf('path="compliance/fraud-alerts"')
    expect(routesSource.slice(alerts, alerts + 400)).toContain('permission="fraud-alerts.view"')
    expect(sidebarSource).not.toContain("href: '/settings/compliance/fraud-settings'")
    expect(sidebarSource).not.toContain("href: '/settings/compliance/fraud-alerts'")

    expect(compliancePageSource).toContain('<RequirePermission permission="compliance.export_jet"')
    expect(compliancePageSource).toContain('<RequirePermission permission="compliance.verify_chains"')
    expect(compliancePageSource).toContain('<RequirePermission permission="compliance.view_reprint_log"')
  })

  it('places compliance export in the bottom section immediately before settings', () => {
    const compliance = sidebarSource.indexOf("key: 'complianceExport'")
    const settings = sidebarSource.indexOf("key: 'settings'", compliance)
    expect(compliance).toBeGreaterThanOrEqual(0)
    expect(settings).toBeGreaterThan(compliance)
    expect(sidebarSource.slice(compliance, settings)).toContain("section: 'bottom'")
  })
})
