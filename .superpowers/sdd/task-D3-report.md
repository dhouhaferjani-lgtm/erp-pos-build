# Task D3 Report — Notification Center Frontend

## Scope

- Binding plan: Global Constraints and Task D3 in `docs/superpowers/plans/2026-07-12-treasury-phase3-cash-visibility.md`.
- Binding spec: §6.1, §6.4, §10, and §15 L3-6/L3-8.
- Plan review corrections: `src/lib/i18n.ts` is the registration path; mutations invalidate raw `['notifications', userId]`, which prefix-matches both tenant-scoped notification queries.
- D3 base/React Doctor base: `0aad1edfdd8d0821356dbf7a769cf0fb8e50cf49`.

## Implementation

- Added typed notification API wrappers. Paginated list uses raw `api.get` and preserves `{data,meta}`; unread count uses `apiGet`; ownership-scoped read/read-all calls use `apiPost`.
- Added user-aware, tenant-gated TanStack hooks. Query keys use `tenantScopedKey(['notifications', userId, ...])`; unread count polls every 60 seconds; list query is panel-open gated; both mutations invalidate the raw leading user prefix.
- Added a live bell with a count badge rendered only above zero, outside-click/Escape dismissal, and accessible expanded/popup state.
- Added the latest-15 notification panel with newest-first ordering, semantic/design-token unread state, mark-read-before-navigation, mark-all, loading/error/empty states, and generic legacy FQCN plus `data.message` fallback.
- Used a native non-modal `<dialog open>` panel, preserving dropdown behavior while providing native accessibility semantics.
- Replaced the TopBar organism's hardcoded dot with `NotificationBell` without touching the layout re-export.
- Added and registered complete en/fr/ar `notifications` bundles.

## TDD Evidence

### API and hooks RED

`pnpm exec vitest run src/features/notifications/api/notificationsApi.test.ts src/features/notifications/hooks/useNotifications.test.tsx`

- Exit 1.
- Both suites failed at import resolution because their production modules did not exist.

### API and hooks GREEN

- 2 files passed, 8 tests passed after the minimal wrappers/hooks were added.

### Components, i18n, and TopBar RED

`pnpm exec vitest run src/features/notifications/components/NotificationBell.test.tsx src/features/notifications/components/NotificationPanel.test.tsx src/features/notifications/notificationsI18n.test.ts src/components/organisms/TopBar/TopBar.test.tsx`

- Bell/Panel suites failed at missing imports.
- en/fr/ar bundles were undefined.
- TopBar wiring test rendered the old hardcoded bell/dot and could not find the live bell.

### Final focused GREEN

`pnpm exec vitest run src/features/notifications src/components/organisms/TopBar/TopBar.test.tsx`

- Exit 0.
- 6 files passed, 20 tests passed.

### React Doctor accessibility follow-up

- The authoritative repository-root pinned scan first found a generic `role="dialog"` container and scored 98/100; an earlier app-directory invocation had not detected it.
- Added a native-element assertion and observed RED: expected `DIALOG`, received `SECTION`.
- Replaced the generic wrapper with a non-modal native `<dialog open>` and reran the focused suite: 6 files, 20 tests passed.

## Verification

- `pnpm typecheck` — exit 0.
- `pnpm lint` — exit 0, 0 errors (repository warning baseline only); rule tests passed.
- `pnpm audit:keys` — 0 unscoped keys, 0 new.
- `pnpm audit:design-system` — 753 acknowledged baseline, 0 new.
- From the repository root: `npx react-doctor@latest apps/web --verbose --scope changed --base 0aad1edfdd8d0821356dbf7a769cf0fb8e50cf49 --blocking warning` — React Doctor v0.7.6, no issues, 100/100.
- `git diff --check` — exit 0.

## Deviations

None. React Doctor's current CLI uses `--scope changed --base` for the pinned diff scan requested by the task.
