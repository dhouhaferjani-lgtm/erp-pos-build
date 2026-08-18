# Defaults-editor lifecycle frontend route

Source: terminal audit round 1, finding F-9 (P3).

## Gap

The backend exposes the feature-flagged defaults-editor lifecycle API for list, create, update, and
credential reset under `/api/v1/admin/country-defaults/editors`. The web application has template,
template-detail, and assignment routes, but no page or route for those editor-lifecycle endpoints.
Operators cannot manage defaults-editor accounts through the shipped frontend.

## Owner and status

- Owner: country-defaults frontend + central-admin auth/MFA lane
- Status: OPEN; external editors remain disabled until the central-admin MFA prerequisite lands

## Acceptance criteria

- Add a role-policy-backed admin route and navigation entry for defaults-editor lifecycle
  management, visible only to authorized active central administrators.
- Provide list, create, update, and credential-reset flows using the existing API error envelope,
  canonical components/design tokens, and complete English/French translations.
- Add route-boundary, permission, API-error, and lifecycle interaction tests.
- Keep `COUNTRY_DEFAULTS_EXTERNAL_EDITORS_ENABLED=false` until the MFA prerequisite and owner
  activation gate are complete.
