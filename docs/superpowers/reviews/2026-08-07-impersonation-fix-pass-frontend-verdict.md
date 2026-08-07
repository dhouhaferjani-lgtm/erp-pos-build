# Tenant Impersonation Fix-pass Re-review — Frontend

Date: 2026-08-07

Reviewed implementation commit: `63f14987a07c2198db3162f1e583b1b7f7d0dc60`

Reconfirmed branch head: `c52d0f71faf66352a439e3d834813f96f43406be`

## Findings

No blocker, major, or minor frontend findings remain.

## Evidence

- English, French, and Arabic contain `grant_expired` and `request_failed`. The locale test derives its required keys from the generated backend `SessionEventType`, giving compile-time exhaustiveness and runtime locale parity.
- `GrantData` is generated, `OffsetPaginationMetaData` models object-shaped pagination, and `SupportAccessOverviewData` emits typed grant arrays and metadata.
- The frontend grant and overview contracts derive from the generated namespace, so future backend drift reaches TypeScript checking.
- Paginated mutation invalidation matches every page variant while stored query data remains tenant/company stamped.
- Absolute support-window validation and backend length/window limits remain aligned.
- The persistent, non-dismissible accessible banner remains mounted above `AppRoutes`.
- Tenant history remains sanitized, field-complete, and paginated.
- `RequirePermission("support-access.view")`, server-authoritative permissions, authenticated downloads, memory-only subject-token handling, i18n, and design tokens remain intact.

Verification:

- Focused App/support-access suite: 16 passed across seven files.
- TypeScript check: pass.
- TanStack query-key audit: zero violations.
- Design-system audit: zero new violations.
- React Doctor: 90/100, no diagnostics.
- Generated Types Drift Guard in exact-head CI: pass.

The `63f14987a..2f779989` delta changes only backend CI workflow configuration. It does not change frontend application code, tests, generated contracts, or build configuration. The prior frontend acceptance therefore remains valid and was reconfirmed at `2f7799890`.

The `2f779989..c52d0f71` delta changes only a backend PostgreSQL test teardown guard. It does not change frontend code, tests, workflow configuration, or generated contracts. Frontend acceptance was reconfirmed at `c52d0f71f`.

Final exact-head CI run `31202946159` passes TypeScript and generated-type drift. Repository-wide frontend lint and Vitest remain red on the documented unrelated baselines; no frontend file changed in either test-infrastructure delta.

## Verdict

**ACCEPT.** No frontend defect remains in the reviewed scope or in either test-infrastructure branch-head delta.
