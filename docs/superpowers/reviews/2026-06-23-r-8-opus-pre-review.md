# R-8 Opus Pre-Review

Opus returned an adversarial pre-review for the R-8 `DocumentLineEditor`
Service-tab gate.

Key findings:

- The existing `DocumentLineEditor.test.tsx` mock for
  `useCompanyConfig()` only returns `config`; adding a component call to
  `hasModule()` would break every existing test unless the mock is updated.
- The services `useQuery` `enabled` guard is the security-relevant client-side
  behavior. It must be gated by `canSearchServices`, not only hidden visually.
- All stale `searchTab === 'service'` reads should use an effective tab value so
  async config transitions cannot briefly render or fetch services for
  non-Workshop companies.
- `useCompanyConfig()` must be called unconditionally; do not wrap hooks
  conditionally.
- Tests must open the dropdown before asserting the Service tab is absent, and
  should positively assert the Product tab is present to avoid vacuous passes.
- The canonical module name should be `Workshop`, matching existing route
  `ModuleGuard` usage.

Invoice/media attachment wiring remains out of scope while unified media
management is in transition.
