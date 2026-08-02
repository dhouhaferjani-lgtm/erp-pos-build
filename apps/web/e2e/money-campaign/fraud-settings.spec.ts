/**
 * MONEY TEST CAMPAIGN — W-2 execution agent — surface `FRD` (fraud settings /
 * cash-variance controls), P0 per orchestrator ruling F-7
 * (docs/qa/2026-08-02-full-e2e-campaign-plan.md §F.7): "IDEM, UOM, FRD, RET
 * P0-eligible".
 *
 * REVISED-FROM-PLAN-PREMISE (record so a future agent doesn't re-file): the
 * plan's §B.7 flow 93 describes `MTP-FRD-*` as covering "M2/M3 ceilings"
 * (offline refund count/value ceiling, online-required threshold) at
 * `/settings/compliance/fraud-settings`. Live research (2026-08-02) shows
 * those three specific fields — `offline_refund_count_ceiling`,
 * `offline_refund_value_ceiling`, `online_required_refund_threshold`
 * (`CompanyFraudSettings.php:80-106`) — have **no admin web UI this pass**,
 * per an explicit owner ruling documented in the `REFUND_EXPOSURE_KEYS`
 * comment (`FraudSettingsController.php:40-63`): they are absent from
 * `CompanyFraudSettingsData`, unvalidated on `PATCH /fraud-settings`, and
 * readable ONLY via the separate ungated POS-device endpoint
 * `GET /api/v1/pos/fraud-settings`. FRD-01 below proves this live (structural
 * absence + presence-on-the-other-endpoint) so it reads as a verified finding,
 * not a guess. The web admin surface that DOES exist —
 * `/settings/compliance/fraud-settings`, abandoned-draft-threshold / fraud
 * alerts / cash-drawer-variance controls — is what FRD-02..06 exercise
 * instead; it is real, money-adjacent config (cash-variance thresholds gate
 * `require_manager_pin_above_hard` at POS close) and was previously
 * completely untested at the e2e layer.
 *
 * MUTATION SAFETY: `company_fraud_settings` has a UNIQUE `company_id` column
 * — one row per company, no location/user scoping (confirmed via the
 * migration). Every mutating test here captures the pre-test snapshot via
 * `getFraudSettings()` and restores it in a `finally` block so a concurrent
 * sibling session's own fraud-settings assertions are not corrupted.
 *
 * Real login (`loginAsRole` from ./helpers), real API (`apiRequest`), no
 * mocking. Fixture names carry the `W2c` prefix (see w2c-support.ts header).
 */
import { test, expect } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import { getFraudSettings, patchFraudSettings, resetFraudSettings, restoreFraudSettings, type FraudSettingsBody } from './w2c-support'

test.describe('MTP-FRD — fraud settings / cash-variance controls (W-2)', () => {
  test.setTimeout(60_000)

  test('MTP-FRD-01 (P1): GET /fraud-settings contract + M2/M3 refund-exposure fields are NOT on this endpoint (structural absence, cross-checked against the POS device endpoint)', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const settings = await getFraudSettings(page)
    expect(settings.abandoned_draft_threshold).toBeGreaterThanOrEqual(1)
    expect(settings.time_window_days).toBeGreaterThanOrEqual(1)
    expect(typeof settings.alert_enabled).toBe('boolean')
    expect(typeof settings.cash_variance_over_soft).toBe('string')
    expect(typeof settings.is_configured).toBe('boolean')

    // Structural absence: the M2/M3 refund-exposure ceilings are excluded
    // from this DTO by design (REFUND_EXPOSURE_KEYS, FraudSettingsController.php:40-63).
    expect(Object.keys(settings)).not.toContain('offline_refund_count_ceiling')
    expect(Object.keys(settings)).not.toContain('offline_refund_value_ceiling')
    expect(Object.keys(settings)).not.toContain('online_required_refund_threshold')

    // Cross-check: those three fields DO exist, on the separate ungated
    // POS-device endpoint (FraudSettingsPosController.php:16-21). SPEC NOTE:
    // that endpoint's DTO uses camelCase keys (device/JS-facing contract),
    // unlike the snake_case admin DTO above — verified live, not assumed.
    const deviceRes = await apiRequest(page, 'GET', '/pos/fraud-settings')
    expect(deviceRes.status).toBe(200)
    const deviceBody = (deviceRes.body as { data?: Record<string, unknown> }).data ?? {}
    expect(Object.keys(deviceBody)).toEqual(
      expect.arrayContaining(['offlineRefundCountCeiling', 'offlineRefundValueCeiling', 'onlineRequiredRefundThreshold'])
    )
  })

  test('MTP-FRD-02 (P0): owner PATCHes cash-variance thresholds — persists, restored after', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const before = await getFraudSettings(page)
    try {
      const patchRes = await patchFraudSettings(page, {
        cash_variance_over_soft: '5.0000',
        cash_variance_over_hard: '20.0000',
        cash_variance_under_soft: '5.0000',
        cash_variance_under_hard: '20.0000',
      })
      expect(patchRes.status, `PATCH -> ${patchRes.status} ${JSON.stringify(patchRes.body)}`).toBe(200)
      const after = await getFraudSettings(page)
      expect(after.cash_variance_over_soft).toBe('5.0000')
      expect(after.cash_variance_over_hard).toBe('20.0000')
      expect(after.cash_variance_under_soft).toBe('5.0000')
      expect(after.cash_variance_under_hard).toBe('20.0000')
    } finally {
      await restoreFraudSettings(page, before)
      const restored = await getFraudSettings(page)
      expect(restored.cash_variance_over_soft, 'cleanup: restored to pre-test value').toBe(before.cash_variance_over_soft)
    }
  })

  test('MTP-FRD-03 (P0): cross-field validation — soft >= hard is refused (422), original values untouched', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const before = await getFraudSettings(page)
    // soft (30) >= hard (10) on the SAME (over) pair must 422 per the
    // cross-field rule (FraudSettingsController.php:130-144).
    const patchRes = await patchFraudSettings(page, {
      cash_variance_over_soft: '30.0000',
      cash_variance_over_hard: '10.0000',
    })
    expect(patchRes.status, `soft>=hard must be refused -> ${patchRes.status} ${JSON.stringify(patchRes.body)}`).toBe(422)
    const after = await getFraudSettings(page)
    expect(after.cash_variance_over_soft, 'refused PATCH leaves state untouched').toBe(before.cash_variance_over_soft)
    expect(after.cash_variance_over_hard).toBe(before.cash_variance_over_hard)
  })

  test('MTP-FRD-04 (P1): decimal-scale ceiling — a 5th decimal on a cash-variance field is refused (422)', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const before = await getFraudSettings(page)
    const patchRes = await patchFraudSettings(page, {
      // Regex ceiling is /^\d+(\.\d{1,4})?$/ (FraudSettingsController.php ~118-123) — 5dp must fail.
      cash_variance_over_soft: '5.00001',
    })
    expect(patchRes.status, `5dp must be refused -> ${patchRes.status} ${JSON.stringify(patchRes.body)}`).toBe(422)
    const after = await getFraudSettings(page)
    expect(after.cash_variance_over_soft).toBe(before.cash_variance_over_soft)
  })

  test('MTP-FRD-05 (P0, RULING): fraud-settings.update WITHOUT pos.configure_cash_count can edit non-cash fields but is refused on cash-control fields', async ({ page }) => {
    test.setTimeout(90_000)
    // No seeded role isolates "fraud-settings.update without
    // pos.configure_cash_count" (only admin/manager hold fraud-settings.update
    // at all, and both also hold pos.configure_cash_count —
    // RolesAndPermissionsSeeder.php:542,567). Constructed live via the Roles
    // API and granted temporarily to the `viewer` user, mirroring the
    // established MTP-PERM-15 pattern (permissions.spec.ts) exactly.
    await loginAsRole(page, 'viewer')
    const viewerMe = await apiRequest(page, 'GET', '/auth/me')
    const viewerId = ((viewerMe.body as { data?: { id: string } }).data as { id: string }).id
    const viewerPermsBefore = ((viewerMe.body as { data?: { permissions?: string[] } }).data?.permissions ?? []) as string[]
    expect(viewerPermsBefore).not.toContain('fraud-settings.update')

    await loginAsRole(page, 'owner')
    const roleName = `frd05-fraud-editor-${Date.now()}`
    const roleCreate = await apiRequest(page, 'POST', '/roles', {
      name: roleName,
      permissions: ['fraud-settings.view', 'fraud-settings.update'],
    })
    expect(roleCreate.status, `role create -> ${roleCreate.status} ${JSON.stringify(roleCreate.body)}`).toBe(201)
    const assign = await apiRequest(page, 'POST', `/users/${viewerId}/roles`, { role: roleName })
    expect(assign.status).toBeLessThan(300)

    const before = await getFraudSettings(page)
    try {
      await loginAsRole(page, 'viewer')
      const meAfter = await apiRequest(page, 'GET', '/auth/me')
      const permsAfter = ((meAfter.body as { data?: { permissions?: string[] } }).data?.permissions ?? []) as string[]
      expect(permsAfter).toContain('fraud-settings.update')
      expect(permsAfter, 'RULING setup check: principal does NOT hold pos.configure_cash_count').not.toContain('pos.configure_cash_count')

      // Non-cash field: allowed.
      const nonCashPatch = await patchFraudSettings(page, { abandoned_draft_threshold: 7 })
      expect(nonCashPatch.status, `non-cash field PATCH -> ${nonCashPatch.status} ${JSON.stringify(nonCashPatch.body)}`).toBe(200)

      // Cash-control field: refused — the inline Gate::authorize('pos.configure_cash_count')
      // check (FraudSettingsController.php:104-106) is a SECOND, additive gate
      // on top of the route-level fraud-settings.update permission.
      const cashPatch = await patchFraudSettings(page, { cash_variance_over_soft: '9.0000' })
      expect(cashPatch.status, `RULING: cash-control field requires pos.configure_cash_count even with fraud-settings.update -> ${cashPatch.status}`).toBe(403)
    } finally {
      await loginAsRole(page, 'owner')
      await restoreFraudSettings(page, before as FraudSettingsBody)
      await apiRequest(page, 'DELETE', `/users/${viewerId}/roles`, { role: roleName })
      const roles = await apiRequest(page, 'GET', '/roles')
      const roleRow = ((roles.body as { data?: Array<{ id: number; name: string }> }).data ?? []).find((r) => r.name === roleName)
      if (roleRow) {
        await apiRequest(page, 'DELETE', `/roles/${roleRow.id}`)
      }
    }
  })

  test('MTP-FRD-06 (P0): cashier has no fraud-settings permission at all (GET+PATCH both 403); accountant can view but not update; reset-to-defaults also needs pos.configure_cash_count', async ({ page }) => {
    test.setTimeout(90_000)
    await loginAsRole(page, 'cashier')
    const cashierGet = await apiRequest(page, 'GET', '/fraud-settings')
    expect(cashierGet.status).toBe(403)
    const cashierPatch = await patchFraudSettings(page, { abandoned_draft_threshold: 9 })
    expect(cashierPatch.status).toBe(403)

    await loginAsRole(page, 'accountant')
    const accountantGet = await apiRequest(page, 'GET', '/fraud-settings')
    expect(accountantGet.status, 'accountant holds fraud-settings.view (read-only audit access)').toBe(200)
    const accountantPatch = await patchFraudSettings(page, { abandoned_draft_threshold: 9 })
    expect(accountantPatch.status, 'accountant does NOT hold fraud-settings.update').toBe(403)
    const accountantReset = await resetFraudSettings(page)
    expect(accountantReset.status).toBe(403)

    // Owner CAN reset (holds both fraud-settings.update and
    // pos.configure_cash_count) — verify then restore the pre-test snapshot,
    // since reset-to-defaults is itself a company-wide mutation.
    await loginAsRole(page, 'owner')
    const before = await getFraudSettings(page)
    try {
      const resetRes = await resetFraudSettings(page)
      expect(resetRes.status, `owner reset -> ${resetRes.status} ${JSON.stringify(resetRes.body)}`).toBe(200)
      const afterReset = await getFraudSettings(page)
      expect(afterReset.is_configured, 'reset still returns a configured row (defaults persisted)').toBeTruthy()
    } finally {
      await restoreFraudSettings(page, before)
    }
  })
})
