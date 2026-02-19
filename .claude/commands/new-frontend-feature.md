Create a new frontend feature named "$ARGUMENTS" following AutoERP's frontend conventions.

## Steps

1. **Create API client** at `apps/web/src/features/{feature}/api/{feature}Api.ts`:
   - Use `apiGet`, `apiPost`, `apiPut`, `apiPatch`, `apiDelete` from `@/lib/api`
   - Return directly — these helpers already unwrap `response.data.data`
   - Type parameter is your actual data type, NOT a wrapper
   ```typescript
   // CORRECT
   return apiGet<Item[]>('/items')
   // WRONG — causes undefined errors
   const res = await apiGet<{data: Item[]}>('/items')
   return res.data
   ```

2. **Create React Query hooks** at `apps/web/src/features/{feature}/hooks/`:
   - Use key factory pattern: `const keys = { all: ['{feature}'] as const, ... }`
   - See `docs/conventions/05-REACT-QUERY.md` for patterns

3. **Create pages** at `apps/web/src/features/{feature}/pages/`:
   - List page with data table
   - Detail page
   - Form page (create/edit) using react-hook-form + zod
   - ALL text via `useTranslation` — zero hardcoded strings

4. **Register routes** in `apps/web/src/routes/index.tsx`:
   ```tsx
   const ListPage = lazy(() => import('@/features/{feature}/pages/ListPage'))
   // Wrap with RequirePermission and SuspenseWrapper
   ```

5. **Add sidebar entry** in `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`:
   - Add navigation item with permission check

6. **Create i18n namespace**:
   - `apps/web/src/locales/en/{feature}.json`
   - `apps/web/src/locales/fr/{feature}.json`
   - Update `apps/web/src/lib/i18n.ts` in 3 places (import, resources, ns array)

## Constraints

- No `any` types — use `unknown` + type guards if type is truly unknown
- No hardcoded text — every string via `t()` from react-i18next
- Use logical Tailwind properties (ms/me, ps/pe, start/end) for RTL readiness
- Read `docs/conventions/` for detailed patterns (forms, queries, routing, types)
